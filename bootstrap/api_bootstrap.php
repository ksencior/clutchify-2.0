<?php
$maxDesktopSessionLifetime = 60 * 60 * 24 * 30; // 30 dni

$isDesktopClient = ($_SERVER['HTTP_X_CLUTCHIFY_DESKTOP'] ?? '') === '1';

$isHttps =
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

if ($isDesktopClient) {
    ini_set('session.gc_maxlifetime', (string)$maxDesktopSessionLifetime);
}

session_set_cookie_params([
    'lifetime' => $isDesktopClient ? $maxDesktopSessionLifetime : 0,
    'path' => '/',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax'
]);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/User.php';
require_once __DIR__ . '/../helpers/ws_auth.php';
require_once __DIR__ . '/../helpers/secrets.php';

$auth = new Auth($pdo);
$action = $_GET['action'] ?? '';

function jsonSuccess(array $data = [], int $statusCode = 200): void {
    http_response_code($statusCode);

    echo json_encode(array_merge([
        'success' => true
    ], $data));

    exit;
}

function jsonError(string $message, int $statusCode = 400, array $extra = []): void {
    http_response_code($statusCode);

    echo json_encode(array_merge([
        'success' => false,
        'message' => $message
    ], $extra));

    exit;
}

function getJsonInput(): array {
    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true);

    return is_array($input) ? $input : [];
}

function normalizeUsername(string $username): string {
    return trim($username);
}

function isValidUsername(string $username): bool {
    return preg_match('/^[a-zA-Z0-9_.-]{3,24}$/', $username) === 1;
}

function usernameValidationMessage(): string {
    return 'Nick może mieć 3-24 znaki i zawierać tylko litery, cyfry, _, . oraz -. Bez spacji i znaków specjalnych.';
}

function getRandomHex($num_bytes = 4) {
    return bin2hex(openssl_random_pseudo_bytes($num_bytes));
}

function requireUserId(): int {
    if (!isset($_SESSION['user_id'])) {
        jsonError('Musisz być zalogowany.', 401);
    }

    return (int)$_SESSION['user_id'];
}

function requireAdminUserId(PDO $pdo): int {
    $userId = requireUserId();

    $stmt = $pdo->prepare("
        SELECT isAdmin
        FROM players
        WHERE user_id = ?
        LIMIT 1
    ");
    $stmt->execute([$userId]);

    $isAdmin = (bool)$stmt->fetchColumn();

    if (!$isAdmin) {
        jsonError('Brak uprawnień administratora.', 403);
    }

    return $userId;
}

function getCsrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function getRequestCsrfToken(): ?string {
    return $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
}

function validateCsrfToken(): void {
    $sessionToken = $_SESSION['csrf_token'] ?? null;
    $requestToken = getRequestCsrfToken();

    if (!$sessionToken || !$requestToken || !hash_equals($sessionToken, $requestToken)) {
        jsonError('Nieprawidłowy token bezpieczeństwa. Odśwież stronę i spróbuj ponownie.', 403);
    }
}

function requirePostMethod(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonError('Ta akcja wymaga metody POST.', 405);
    }
}

function csrfProtectedActions(): array {
    return [
        'logout',
        'update_profile_settings',
        'change_password',

        'send_friend_request',
        'respond_friend_request',
        'send_message',

        'respond_notification',
        'mark_system_notifications_seen',

        'create_team',
        'update_team_logo',
        'invite_player',
        'leave_team',
        'delete_team',
        'kick_player',
        'request_join_team',

        'ensure_connection',

        'create_tournament',
        'join_tournament',
        'leave_tournament',
        'review_tournament_team',
        'close_tournament_registration',
        'reopen_tournament_registration',
        'generate_bracket',

        'set_player_ready',
        'reset_ready_check',
        'start_match',

        'submit_map_veto',
        'auto_resolve_map_veto',
        'reset_map_veto',
        'set_match_veto_format',

        'test_practice_rcon',
        'start_practice',
        'practice_action',
        'end_practice',

        'save_admin_game_server',
        'delete_admin_game_server',
        'test_admin_game_server_rcon'
    ];
}

function postOnlyActions(): array {
    return array_merge(csrfProtectedActions(), [
        'register',
        'login'
    ]);
}

function getFriendship(PDO $pdo, int $userA, int $userB) {
    $stmt = $pdo->prepare("
        SELECT *
        FROM friendships
        WHERE (requester_id = ? AND addressee_id = ?)
           OR (requester_id = ? AND addressee_id = ?)
        ORDER BY id DESC
        LIMIT 1
    ");

    $stmt->execute([$userA, $userB, $userB, $userA]);

    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function areFriends(PDO $pdo, int $userA, int $userB): bool {
    $friendship = getFriendship($pdo, $userA, $userB);

    return $friendship && $friendship['status'] === 'accepted';
}

function getFriendStatusForViewer(PDO $pdo, int $viewerId, int $targetId): array {
    $friendship = getFriendship($pdo, $viewerId, $targetId);

    if (!$friendship) {
        return [
            'status' => 'none',
            'friendship_id' => null
        ];
    }

    if ($friendship['status'] === 'accepted') {
        return [
            'status' => 'accepted',
            'friendship_id' => (int)$friendship['id']
        ];
    }

    if ($friendship['status'] === 'pending') {
        return [
            'status' => ((int)$friendship['requester_id'] === $viewerId)
                ? 'pending_sent'
                : 'pending_received',
            'friendship_id' => (int)$friendship['id']
        ];
    }

    return [
        'status' => 'none',
        'friendship_id' => (int)$friendship['id']
    ];
}

function logActivity(
    PDO $pdo,
    string $type,
    string $title,
    string $message,
    ?int $actorUserId = null,
    ?string $targetType = null,
    ?int $targetId = null,
    array $metadata = [],
    string $visibility = 'public',
    int $dedupeSeconds = 0
): void {
    try {
        if ($dedupeSeconds > 0 && $actorUserId !== null) {
            $stmt = $pdo->prepare("
                SELECT id
                FROM activity_events
                WHERE actor_user_id = ?
                  AND type = ?
                  AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND)
                LIMIT 1
            ");
            $stmt->execute([$actorUserId, $type, $dedupeSeconds]);

            if ($stmt->fetch()) {
                return;
            }
        }

        $stmt = $pdo->prepare("
            INSERT INTO activity_events (
                actor_user_id,
                type,
                title,
                message,
                target_type,
                target_id,
                metadata,
                visibility
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $actorUserId,
            $type,
            $title,
            $message,
            $targetType,
            $targetId,
            $metadata ? json_encode($metadata, JSON_UNESCAPED_UNICODE) : null,
            in_array($visibility, ['public', 'admin'], true) ? $visibility : 'public'
        ]);
    } catch (Throwable $e) {
        /**
         * Activity feed nie może wysypać głównej akcji.
         * Jeśli logowanie aktywności padnie, akcja użytkownika nadal ma działać.
         */
        error_log('Activity log error: ' . $e->getMessage());
    }
}


function profileCompleteness(array $profile): array {
    $checks = [
        [
            'key' => 'avatar',
            'label' => 'Dodaj avatar',
            'done' => !empty($profile['avatar'])
        ],
        [
            'key' => 'bio',
            'label' => 'Uzupełnij bio',
            'done' => !empty($profile['bio'])
        ],
        [
            'key' => 'preferred_role',
            'label' => 'Wybierz rolę w CS',
            'done' => !empty($profile['preferred_role']) && $profile['preferred_role'] !== 'unknown'
        ],
        [
            'key' => 'faceit_level',
            'label' => 'Dodaj Faceit level',
            'done' => !empty($profile['faceit_level'])
        ],
        [
            'key' => 'region',
            'label' => 'Ustaw region',
            'done' => !empty($profile['region'])
        ],
        [
            'key' => 'school',
            'label' => 'Dodaj szkołę / organizację',
            'done' => !empty($profile['school'])
        ],
        [
            'key' => 'availability',
            'label' => 'Dodaj dostępność',
            'done' => !empty($profile['availability'])
        ],
        [
            'key' => 'steam_id',
            'label' => 'Połącz Steam',
            'done' => !empty($profile['steam_id'])
        ],
        [
            'key' => 'discord_id',
            'label' => 'Połącz Discord',
            'done' => !empty($profile['discord_id'])
        ],
    ];

    $done = 0;

    foreach ($checks as $check) {
        if ($check['done']) {
            $done++;
        }
    }

    $total = count($checks);
    $percent = $total > 0 ? (int)round(($done / $total) * 100) : 0;

    return [
        'percent' => $percent,
        'done' => $done,
        'total' => $total,
        'missing' => array_values(array_filter($checks, fn($check) => !$check['done']))
    ];
}

function profileBadges(array $profile, array $completeness): array {
    $badges = [];

    if (!empty($profile['isAdmin'])) {
        $badges[] = [
            'type' => 'admin',
            'label' => 'Admin',
            'description' => 'Administrator platformy'
        ];
    }

    if (!empty($profile['steam_id'])) {
        $badges[] = [
            'type' => 'steam',
            'label' => 'Steam',
            'description' => 'Połączone konto Steam'
        ];
    }

    if (!empty($profile['discord_id'])) {
        $badges[] = [
            'type' => 'discord',
            'label' => 'Discord',
            'description' => 'Połączone konto Discord'
        ];
    }

    if (!empty($profile['team_name'])) {
        $badges[] = [
            'type' => 'team',
            'label' => 'Team Player',
            'description' => 'Należy do drużyny'
        ];
    }

    if (!empty($profile['faceit_level'])) {
        $badges[] = [
            'type' => 'faceit',
            'label' => 'Faceit ' . (int)$profile['faceit_level'],
            'description' => 'Poziom Faceit gracza'
        ];
    }

    if (!empty($profile['preferred_role']) && $profile['preferred_role'] !== 'unknown') {
        $badges[] = [
            'type' => 'role',
            'label' => profileRoleLabel($profile['preferred_role']),
            'description' => 'Preferowana rola w CS'
        ];
    }

    return $badges;
}

function profileRoleLabel(?string $role): string {
    return match ($role) {
        'entry' => 'Entry Fragger',
        'rifler' => 'Rifler',
        'awper' => 'AWPer',
        'igl' => 'IGL',
        'lurker' => 'Lurker',
        'support' => 'Support',
        default => 'Nie ustawiono'
    };
}