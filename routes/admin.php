<?php

use Thedudeguy\Rcon;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../helpers/secrets.php';

function adminScalar(PDO $pdo, string $sql, array $params = []): int {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return (int)$stmt->fetchColumn();
}

function adminTableExists(PDO $pdo, string $table): bool {
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
        return false;
    }

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
        LIMIT 1
    ");

    $stmt->execute([$table]);

    return (int)$stmt->fetchColumn() > 0;
}

function adminColumnExists(PDO $pdo, string $table, string $column): bool {
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
        return false;
    }

    if (!preg_match('/^[a-zA-Z0-9_]+$/', $column)) {
        return false;
    }

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
        LIMIT 1
    ");

    $stmt->execute([$table, $column]);

    return (int)$stmt->fetchColumn() > 0;
}

function adminGameServerPurpose(string $purpose): string {
    return in_array($purpose, ['practice', 'match', 'both'], true)
        ? $purpose
        : 'both';
}

function adminValidateGameServerInput(array $input, bool $isUpdate = false): array {
    $name = trim((string)($input['name'] ?? ''));
    $purpose = adminGameServerPurpose((string)($input['purpose'] ?? 'both'));
    $publicAddress = trim((string)($input['public_address'] ?? ''));
    $connectPassword = trim((string)($input['connect_password'] ?? ''));

    $rconHost = trim((string)($input['rcon_host'] ?? '127.0.0.1'));
    $rconPort = (int)($input['rcon_port'] ?? 27015);
    $rconPassword = (string)($input['rcon_password'] ?? '');
    $rconPasswordEnv = trim((string)($input['rcon_password_env'] ?? ''));

    $rotatePassword = !empty($input['rotate_password_per_session']) ? 1 : 0;
    $isEnabled = !empty($input['is_enabled']) ? 1 : 0;

    if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
        jsonError('Nazwa serwera musi mieć od 2 do 120 znaków.');
    }

    if ($publicAddress === '' || mb_strlen($publicAddress) > 190 || preg_match('/[\s;"\']/', $publicAddress)) {
        jsonError('Nieprawidłowy publiczny adres serwera.');
    }

    if ($rconHost === '' || mb_strlen($rconHost) > 190 || preg_match('/[\s;"\']/', $rconHost)) {
        jsonError('Nieprawidłowy RCON host.');
    }

    if ($rconPort < 1 || $rconPort > 65535) {
        jsonError('Nieprawidłowy RCON port.');
    }

    if ($connectPassword !== '' && !preg_match('/^[A-Za-z0-9_-]{4,32}$/', $connectPassword)) {
        jsonError('Hasło wejścia na serwer może mieć 4-32 znaki: litery, cyfry, _ albo -.');
    }

    if ($rconPasswordEnv !== '' && !preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,119}$/', $rconPasswordEnv)) {
        jsonError('Nieprawidłowa nazwa zmiennej ENV dla hasła RCON.');
    }

    if (!$isUpdate && $rconPassword === '' && $rconPasswordEnv === '') {
        jsonError('Podaj hasło RCON albo ENV key.');
    }

    return [
        'name' => $name,
        'purpose' => $purpose,
        'public_address' => $publicAddress,
        'connect_password' => $connectPassword !== '' ? $connectPassword : null,
        'rotate_password_per_session' => $rotatePassword,
        'rcon_host' => $rconHost,
        'rcon_port' => $rconPort,
        'rcon_password' => $rconPassword,
        'rcon_password_env' => $rconPasswordEnv !== '' ? $rconPasswordEnv : null,
        'is_enabled' => $isEnabled
    ];
}

function adminGameServers(PDO $pdo): array {
    $stmt = $pdo->query("
        SELECT
            gs.*,

            ps.id AS active_practice_session_id,
            ps.user_id AS active_practice_user_id,
            ps.map_name AS active_practice_map,
            ps.started_at AS active_practice_started_at,

            u.username AS active_practice_username
        FROM game_servers gs
        LEFT JOIN practice_sessions ps
            ON ps.game_server_id = gs.id
           AND ps.status = 'active'
        LEFT JOIN users u
            ON u.id = ps.user_id
        ORDER BY gs.is_enabled DESC, gs.id ASC
    ");

    $servers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($servers as &$server) {
        $server['id'] = (int)$server['id'];
        $server['rcon_port'] = (int)$server['rcon_port'];
        $server['is_enabled'] = (bool)$server['is_enabled'];
        $server['rotate_password_per_session'] = (bool)($server['rotate_password_per_session'] ?? true);

        $server['active_practice_session_id'] = $server['active_practice_session_id'] !== null
            ? (int)$server['active_practice_session_id']
            : null;

        $server['active_practice_user_id'] = $server['active_practice_user_id'] !== null
            ? (int)$server['active_practice_user_id']
            : null;

        $hasEncrypted = trim((string)($server['rcon_password_encrypted'] ?? '')) !== '';
        $hasEnv = trim((string)($server['rcon_password_env'] ?? '')) !== '';

        $server['has_rcon_password'] = $hasEncrypted || $hasEnv;
        $server['rcon_password_mode'] = $hasEncrypted ? 'db' : ($hasEnv ? 'env' : 'missing');

        unset($server['rcon_password_encrypted']);
    }

    return $servers;
}

function adminGetGameServerRaw(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare("
        SELECT *
        FROM game_servers
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->execute([$id]);

    $server = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$server) {
        return null;
    }

    $server['id'] = (int)$server['id'];
    $server['rcon_port'] = (int)$server['rcon_port'];

    return $server;
}

function adminRunGameServerRcon(array $server, string $command): string {
    $password = gameServerRconPassword($server);
    $timeout = (int)env('PRACTICE_RCON_TIMEOUT', '5');

    $rcon = new Rcon(
        (string)$server['rcon_host'],
        (int)$server['rcon_port'],
        $password,
        $timeout
    );

    if (!$rcon->connect()) {
        throw new RuntimeException("Nie można połączyć się z RCON {$server['rcon_host']}:{$server['rcon_port']}.");
    }

    $response = $rcon->sendCommand($command);

    return is_string($response) ? trim($response) : '';
}

if ($action === 'get_admin_overview') {
    requireAdminUserId($pdo);

    $hasTournamentTeams = adminTableExists($pdo, 'tournament_teams');
    $hasPrivateMessages = adminTableExists($pdo, 'private_messages');
    $hasActivityEvents = adminTableExists($pdo, 'activity_events');
    $hasTournamentStatus = adminColumnExists($pdo, 'tournaments', 'status');

    $stats = [
        'users_total' => adminScalar($pdo, "SELECT COUNT(*) FROM users"),
        'users_today' => adminScalar($pdo, "SELECT COUNT(*) FROM users WHERE DATE(created_at) = CURDATE()"),
        'users_week' => adminScalar($pdo, "SELECT COUNT(*) FROM users WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"),

        'teams_total' => adminScalar($pdo, "SELECT COUNT(*) FROM teams"),

        'tournaments_total' => adminScalar($pdo, "SELECT COUNT(*) FROM tournaments"),

        'open_tournaments' => $hasTournamentStatus
            ? adminScalar($pdo, "SELECT COUNT(*) FROM tournaments WHERE status = 'registration_open'")
            : adminScalar($pdo, "SELECT COUNT(*) FROM tournaments WHERE sign_in_end IS NULL OR sign_in_end > NOW()"),

        'pending_tournament_requests' => $hasTournamentTeams
            ? adminScalar($pdo, "SELECT COUNT(*) FROM tournament_teams WHERE status = 'pending'")
            : 0,

        'pending_team_invites' => adminTableExists($pdo, 'notifications')
            ? adminScalar($pdo, "SELECT COUNT(*) FROM notifications WHERE type = 'team_invite' AND status = 'pending'")
            : 0,

        'messages_today' => $hasPrivateMessages
            ? adminScalar($pdo, "SELECT COUNT(*) FROM private_messages WHERE DATE(created_at) = CURDATE()")
            : 0,

        'activity_today' => $hasActivityEvents
            ? adminScalar($pdo, "SELECT COUNT(*) FROM activity_events WHERE DATE(created_at) = CURDATE()")
            : 0,
        'game_servers_total' => adminTableExists($pdo, 'game_servers')
            ? adminScalar($pdo, "SELECT COUNT(*) FROM game_servers")
            : 0,

        'game_servers_enabled' => adminTableExists($pdo, 'game_servers')
            ? adminScalar($pdo, "SELECT COUNT(*) FROM game_servers WHERE is_enabled = 1")
            : 0,

        'practice_sessions_active' => adminTableExists($pdo, 'practice_sessions')
            ? adminScalar($pdo, "SELECT COUNT(*) FROM practice_sessions WHERE status = 'active'")
            : 0
    ];

    $stmt = $pdo->prepare("
        SELECT
            u.id,
            u.username,
            u.email,
            u.created_at,

            p.avatar,
            p.steam_id,
            p.discord_id,
            p.isAdmin AS is_admin,

            t.name AS team_name
        FROM users u
        LEFT JOIN players p ON p.user_id = u.id
        LEFT JOIN teams t ON t.id = p.team_id
        ORDER BY u.created_at DESC
        LIMIT 6
    ");
    $stmt->execute();
    $latestUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($latestUsers as &$user) {
        $user['id'] = (int)$user['id'];
        $user['is_admin'] = (bool)$user['is_admin'];
    }

    $stmt = $pdo->prepare("
        SELECT
            teams.id,
            teams.name,
            teams.tag,
            teams.logo,
            teams.created_at,

            u.username AS captain_username,

            COUNT(p.id) AS players_count
        FROM teams
        LEFT JOIN users u ON u.id = teams.captain_id
        LEFT JOIN players p ON p.team_id = teams.id
        GROUP BY teams.id, teams.name, teams.tag, teams.logo, teams.created_at, u.username
        ORDER BY teams.created_at DESC
        LIMIT 6
    ");
    $stmt->execute();
    $latestTeams = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($latestTeams as &$team) {
        $team['id'] = (int)$team['id'];
        $team['players_count'] = (int)$team['players_count'];
    }

    $statusSelect = $hasTournamentStatus
        ? "status"
        : "NULL AS status";

    $stmt = $pdo->prepare("
        SELECT
            id,
            title,
            creator,
            is_open,
            {$statusSelect},
            sign_in_end,
            starts_at,
            created_at
        FROM tournaments
        ORDER BY created_at DESC
        LIMIT 6
    ");
    $stmt->execute();
    $latestTournaments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($latestTournaments as &$tournament) {
        $tournament['id'] = (int)$tournament['id'];
        $tournament['is_open'] = (bool)$tournament['is_open'];
    }

    $pendingRequests = [];

    if ($hasTournamentTeams) {
        $ttCreatedSelect = adminColumnExists($pdo, 'tournament_teams', 'created_at')
            ? "tt.created_at"
            : "NULL AS created_at";

        $ttOrder = adminColumnExists($pdo, 'tournament_teams', 'created_at')
            ? "tt.created_at DESC"
            : "tt.id DESC";

        $stmt = $pdo->prepare("
            SELECT
                tt.id,
                {$ttCreatedSelect},

                tournaments.id AS tournament_id,
                tournaments.title AS tournament_title,

                teams.id AS team_id,
                teams.name AS team_name,
                teams.tag AS team_tag,

                users.username AS captain_username
            FROM tournament_teams tt
            JOIN tournaments ON tournaments.id = tt.tournament_id
            JOIN teams ON teams.id = tt.team_id
            LEFT JOIN users ON users.id = teams.captain_id
            WHERE tt.status = 'pending'
            ORDER BY {$ttOrder}
            LIMIT 8
        ");

        $stmt->execute();
        $pendingRequests = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($pendingRequests as &$request) {
            $request['id'] = (int)$request['id'];
            $request['tournament_id'] = (int)$request['tournament_id'];
            $request['team_id'] = (int)$request['team_id'];
        }
    }

    $latestActivity = [];

    if ($hasActivityEvents) {
        $stmt = $pdo->prepare("
            SELECT
                ae.id,
                ae.type,
                ae.title,
                ae.message,
                ae.visibility,
                ae.created_at,

                u.username AS actor_username
            FROM activity_events ae
            LEFT JOIN users u ON u.id = ae.actor_user_id
            ORDER BY ae.created_at DESC
            LIMIT 8
        ");
        $stmt->execute();

        $latestActivity = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($latestActivity as &$activity) {
            $activity['id'] = (int)$activity['id'];
        }
    }

    jsonSuccess([
        'stats' => $stats,
        'latest_users' => $latestUsers,
        'latest_teams' => $latestTeams,
        'latest_tournaments' => $latestTournaments,
        'pending_requests' => $pendingRequests,
        'latest_activity' => $latestActivity,
        'game_servers' => adminTableExists($pdo, 'game_servers') ? adminGameServers($pdo) : [],
        'server_time' => date('Y-m-d H:i:s')
    ]);
}
if ($action === 'save_admin_game_server') {
    requireAdminUserId($pdo);

    if (!adminTableExists($pdo, 'game_servers')) {
        jsonError('Tabela game_servers nie istnieje. Uruchom migracje.');
    }

    $input = getJsonInput();
    $id = (int)($input['id'] ?? 0);

    $isUpdate = $id > 0;
    $data = adminValidateGameServerInput($input, $isUpdate);

    $encryptedPassword = null;

    if ($data['rcon_password'] !== '') {
        $encryptedPassword = encryptSecret($data['rcon_password']);
    }

    if ($isUpdate) {
        $existing = adminGetGameServerRaw($pdo, $id);

        if (!$existing) {
            jsonError('Serwer nie istnieje.', 404);
        }

        $finalEncryptedPassword = $encryptedPassword ?? ($existing['rcon_password_encrypted'] ?? null);
        $finalEnvKey = $data['rcon_password_env'] ?? ($existing['rcon_password_env'] ?? null);

        if (trim((string)$finalEncryptedPassword) === '' && trim((string)$finalEnvKey) === '') {
            jsonError('Serwer musi mieć hasło RCON w DB albo fallback ENV key.');
        }

        $stmt = $pdo->prepare("
            UPDATE game_servers
            SET name = ?,
                purpose = ?,
                public_address = ?,
                connect_password = ?,
                rotate_password_per_session = ?,
                rcon_host = ?,
                rcon_port = ?,
                rcon_password_env = ?,
                rcon_password_encrypted = ?,
                is_enabled = ?
            WHERE id = ?
        ");

        $stmt->execute([
            $data['name'],
            $data['purpose'],
            $data['public_address'],
            $data['connect_password'],
            $data['rotate_password_per_session'],
            $data['rcon_host'],
            $data['rcon_port'],
            $finalEnvKey,
            $finalEncryptedPassword,
            $data['is_enabled'],
            $id
        ]);

        jsonSuccess([
            'message' => 'Serwer został zaktualizowany.',
            'game_servers' => adminGameServers($pdo)
        ]);
    }

    $stmt = $pdo->prepare("
        INSERT INTO game_servers (
            name,
            purpose,
            public_address,
            connect_password,
            rotate_password_per_session,
            rcon_host,
            rcon_port,
            rcon_password_env,
            rcon_password_encrypted,
            is_enabled
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->execute([
        $data['name'],
        $data['purpose'],
        $data['public_address'],
        $data['connect_password'],
        $data['rotate_password_per_session'],
        $data['rcon_host'],
        $data['rcon_port'],
        $data['rcon_password_env'],
        $encryptedPassword,
        $data['is_enabled']
    ]);

    jsonSuccess([
        'message' => 'Serwer został dodany.',
        'game_servers' => adminGameServers($pdo)
    ]);
}

if ($action === 'delete_admin_game_server') {
    requireAdminUserId($pdo);

    $input = getJsonInput();
    $id = (int)($input['id'] ?? 0);

    if ($id <= 0) {
        jsonError('Nieprawidłowy serwer.');
    }

    $server = adminGetGameServerRaw($pdo, $id);

    if (!$server) {
        jsonError('Serwer nie istnieje.', 404);
    }

    $activePractice = adminScalar(
        $pdo,
        "SELECT COUNT(*) FROM practice_sessions WHERE game_server_id = ? AND status = 'active'",
        [$id]
    );

    if ($activePractice > 0) {
        jsonError('Nie można usunąć serwera, który ma aktywną sesję practice.');
    }

    /**
     * Soft-delete: wyłączamy serwer, żeby nie rozwalić historii practice/meczów.
     */
    $stmt = $pdo->prepare("
        UPDATE game_servers
        SET is_enabled = 0
        WHERE id = ?
    ");
    $stmt->execute([$id]);

    jsonSuccess([
        'message' => 'Serwer został wyłączony.',
        'game_servers' => adminGameServers($pdo)
    ]);
}

if ($action === 'test_admin_game_server_rcon') {
    requireAdminUserId($pdo);

    $input = getJsonInput();
    $id = (int)($input['id'] ?? 0);

    if ($id <= 0) {
        jsonError('Nieprawidłowy serwer.');
    }

    $server = adminGetGameServerRaw($pdo, $id);

    if (!$server) {
        jsonError('Serwer nie istnieje.', 404);
    }

    try {
        $started = microtime(true);
        $response = adminRunGameServerRcon($server, 'status');

        jsonSuccess([
            'message' => 'RCON działa.',
            'duration_ms' => (int)round((microtime(true) - $started) * 1000),
            'response' => mb_substr($response, 0, 3000)
        ]);
    } catch (Throwable $e) {
        jsonError('RCON test failed: ' . $e->getMessage());
    }
}