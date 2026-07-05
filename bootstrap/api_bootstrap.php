<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/User.php';
require_once __DIR__ . '/../helpers/ws_auth.php';

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

        'ensure_connection',

        'create_tournament',
        'join_tournament',
        'leave_tournament',
        'review_tournament_team',
        'close_tournament_registration',
        'reopen_tournament_registration'
    ];
}

function postOnlyActions(): array {
    return csrfProtectedActions();
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