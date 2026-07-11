<?php

use Thedudeguy\Rcon;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../helpers/secrets.php';

function practiceEnabled(): bool {
    return env('PRACTICE_ENABLED', '0') === '1';
}

function practiceGenerateJoinPassword(): string {
    /**
     * Tylko bezpieczne znaki: bez spacji, średników, cudzysłowów.
     */
    return 'CF-' . strtoupper(bin2hex(random_bytes(4)));
}

function practicePasswordCommand(string $password): string {
    if ($password !== '' && !preg_match('/^[A-Za-z0-9_-]{4,32}$/', $password)) {
        throw new RuntimeException('Nieprawidłowe hasło sesji practice.');
    }

    return 'sv_password "' . $password . '"';
}

function practiceSessionJoinPassword(array $session): string {
    $encrypted = trim((string)($session['connect_password_encrypted'] ?? ''));

    if ($encrypted !== '') {
        return decryptSecret($encrypted);
    }

    return trim((string)($session['connect_password'] ?? ''));
}

function practiceServerDefaultPassword(array $server): string {
    return trim((string)($server['connect_password'] ?? ''));
}

function practiceShouldRotatePassword(array $server): bool {
    return (int)($server['rotate_password_per_session'] ?? 1) === 1;
}

function practiceConnectStringFromSession(array $session): string {
    $connect = 'connect ' . $session['public_address'];

    $password = practiceSessionJoinPassword($session);

    if ($password !== '') {
        $connect .= '; password ' . $password;
    }

    return $connect;
}

function practiceMaps(): array {
    return [
        'de_mirage' => 'Mirage',
        'de_inferno' => 'Inferno',
        'de_nuke' => 'Nuke',
        'de_anubis' => 'Anubis',
        'de_ancient' => 'Ancient',
        'de_dust2' => 'Dust2',
        'de_cache' => 'Cache'
    ];
}

function practiceSessionMinutes(): int {
    return max(10, min(240, (int)env('PRACTICE_SESSION_MINUTES', '60')));
}

function practiceIsAdmin(PDO $pdo, int $userId): bool {
    $stmt = $pdo->prepare("SELECT isAdmin FROM players WHERE user_id = ? LIMIT 1");
    $stmt->execute([$userId]);

    return (bool)$stmt->fetchColumn();
}

function practiceExpireOldSessions(PDO $pdo): void {
    $minutes = practiceSessionMinutes();

    $stmt = $pdo->prepare("
        SELECT
            ps.id AS session_id,

            gs.id,
            gs.name,
            gs.connect_password,
            gs.rcon_host,
            gs.rcon_port,
            gs.rcon_password_env,
            gs.rcon_password_encrypted,
            gs.rotate_password_per_session
        FROM practice_sessions ps
        JOIN game_servers gs ON gs.id = ps.game_server_id
        WHERE ps.status = 'active'
          AND ps.started_at < (NOW() - INTERVAL {$minutes} MINUTE)
    ");
    $stmt->execute();

    $expired = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($expired as $server) {
        try {
            practiceRunCommands($server, [
                'say [Clutchify] Practice session expired',
                'bot_kick',
                practicePasswordCommand(practiceServerDefaultPassword($server))
            ]);
        } catch (Throwable $e) {
            error_log('[Clutchify] Nie udało się zresetować hasła wygasłej sesji practice #' . $server['session_id'] . ': ' . $e->getMessage());
        }

        $stmt = $pdo->prepare("
            UPDATE practice_sessions
            SET status = 'expired',
                ended_at = NOW()
            WHERE id = ?
              AND status = 'active'
        ");
        $stmt->execute([(int)$server['session_id']]);
    }
}

function practiceGameServers(PDO $pdo): array {
    $stmt = $pdo->query("
        SELECT
            gs.*,

            ps.id AS active_session_id,
            ps.user_id AS active_user_id,
            ps.map_name AS active_map_name,
            ps.started_at AS active_started_at,

            u.username AS active_username
        FROM game_servers gs
        LEFT JOIN practice_sessions ps
            ON ps.game_server_id = gs.id
           AND ps.status = 'active'
        LEFT JOIN users u
            ON u.id = ps.user_id
        WHERE gs.is_enabled = 1
          AND gs.purpose IN ('practice', 'both')
        ORDER BY gs.id ASC
    ");

    $servers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($servers as &$server) {
        $server['id'] = (int)$server['id'];
        $server['rcon_port'] = (int)$server['rcon_port'];
        $server['is_enabled'] = (bool)$server['is_enabled'];
        $server['is_busy'] = $server['active_session_id'] !== null;

        $server['active_session_id'] = $server['active_session_id'] !== null
            ? (int)$server['active_session_id']
            : null;

        $server['active_user_id'] = $server['active_user_id'] !== null
            ? (int)$server['active_user_id']
            : null;

        unset($server['rcon_password_env'], $server['rcon_password_encrypted'], $server['connect_password']);
    }

    return $servers;
}

function practiceGetGameServer(PDO $pdo, int $serverId): ?array {
    $stmt = $pdo->prepare("
        SELECT *
        FROM game_servers
        WHERE id = ?
          AND is_enabled = 1
          AND purpose IN ('practice', 'both')
        LIMIT 1
    ");
    $stmt->execute([$serverId]);

    $server = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$server) {
        return null;
    }

    $server['id'] = (int)$server['id'];
    $server['rcon_port'] = (int)$server['rcon_port'];

    return $server;
}

function practiceFindFreeServer(PDO $pdo): ?array {
    $stmt = $pdo->query("
        SELECT gs.*
        FROM game_servers gs
        LEFT JOIN practice_sessions ps
            ON ps.game_server_id = gs.id
           AND ps.status = 'active'
        WHERE gs.is_enabled = 1
          AND gs.purpose IN ('practice', 'both')
          AND ps.id IS NULL
        ORDER BY gs.id ASC
        LIMIT 1
    ");

    $server = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$server) {
        return null;
    }

    $server['id'] = (int)$server['id'];
    $server['rcon_port'] = (int)$server['rcon_port'];

    return $server;
}

function practiceServerIsBusy(PDO $pdo, int $serverId, ?int $exceptUserId = null): ?array {
    $params = [$serverId];

    $exceptSql = '';

    if ($exceptUserId !== null) {
        $exceptSql = 'AND ps.user_id != ?';
        $params[] = $exceptUserId;
    }

    $stmt = $pdo->prepare("
        SELECT
            ps.*,
            u.username
        FROM practice_sessions ps
        JOIN users u ON u.id = ps.user_id
        WHERE ps.game_server_id = ?
          AND ps.status = 'active'
          {$exceptSql}
        LIMIT 1
    ");
    $stmt->execute($params);

    $session = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$session) {
        return null;
    }

    $session['id'] = (int)$session['id'];
    $session['user_id'] = (int)$session['user_id'];
    $session['game_server_id'] = (int)$session['game_server_id'];

    return $session;
}

function practiceActiveSessionForUser(PDO $pdo, int $userId): ?array {
    practiceExpireOldSessions($pdo);

    $stmt = $pdo->prepare("
        SELECT
            ps.*,
            u.username,
            gs.name AS server_name,
            gs.public_address,
            gs.connect_password
        FROM practice_sessions ps
        JOIN users u ON u.id = ps.user_id
        JOIN game_servers gs ON gs.id = ps.game_server_id
        WHERE ps.status = 'active'
          AND ps.user_id = ?
        ORDER BY ps.id DESC
        LIMIT 1
    ");
    $stmt->execute([$userId]);

    $session = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$session) {
        return null;
    }

    $session['id'] = (int)$session['id'];
    $session['user_id'] = (int)$session['user_id'];
    $session['game_server_id'] = (int)$session['game_server_id'];

    return $session;
}

function practiceConnectStringFromServer(array $server): string {
    $connect = 'connect ' . $server['public_address'];

    if (!empty($server['connect_password'])) {
        $connect .= '; password ' . $server['connect_password'];
    }

    return $connect;
}

function practiceRunCommands(array $server, array $commands): array {
    $host = (string)$server['rcon_host'];
    $port = (int)$server['rcon_port'];
    $password = gameServerRconPassword($server);
    $timeout = (int)env('PRACTICE_RCON_TIMEOUT', '5');

    $rcon = new Rcon($host, $port, $password, $timeout);

    if (!$rcon->connect()) {
        throw new RuntimeException("Nie można połączyć się z RCON {$host}:{$port}.");
    }

    $responses = [];

    foreach ($commands as $command) {
        $response = $rcon->sendCommand($command);

        $responses[] = [
            'command' => $command,
            'response' => is_string($response) ? trim($response) : ''
        ];
    }

    return $responses;
}

function practiceRconCommand(array $server, string $command): string {
    $host = (string)$server['rcon_host'];
    $port = (int)$server['rcon_port'];
    $password = gameServerRconPassword($server);
    $timeout = (int)env('PRACTICE_RCON_TIMEOUT', '5');

    $rcon = new Rcon($host, $port, $password, $timeout);

    if (!$rcon->connect()) {
        throw new RuntimeException("Nie można połączyć się z RCON {$host}:{$port}.");
    }

    $response = $rcon->sendCommand($command);

    return is_string($response) ? trim($response) : '';
}

function practiceNormalizeMapName(?string $map): ?string {
    $map = strtolower(trim((string)$map));

    if ($map === '') {
        return null;
    }

    $map = trim($map, "\"' \t\n\r\0\x0B");
    $map = str_replace('\\', '/', $map);

    /**
     * Gdyby status kiedyś zwrócił ścieżkę typu workshop/123/de_mirage
     * albo maps/de_mirage, bierzemy końcową nazwę mapy.
     */
    if (str_contains($map, '/')) {
        $parts = array_values(array_filter(explode('/', $map)));
        $map = end($parts) ?: $map;
    }

    return preg_match('/^[a-z0-9_]+$/', $map) ? $map : null;
}

function practiceExtractMapFromStatus(string $status): ?string {
    /**
     * CS2 / SourceTV format:
     * Game Time 00:01, Mod "csgo", Map "de_mirage"
     */
    if (preg_match('/\bMap\s+"([^"]+)"/i', $status, $match)) {
        return practiceNormalizeMapName($match[1]);
    }

    /**
     * CS2 spawngroup format:
     * loaded spawngroup(  1)  : SV:  [1: de_mirage | main lump | mapload]
     *
     * Bierzemy tylko spawngroup 1, bo kolejne linie mają prefaby:
     * maps/prefabs/de_mirage/3dskybox...
     */
    if (preg_match('/loaded\s+spawngroup\(\s*1\s*\)\s*:\s*SV:\s*\[\s*1:\s*([a-zA-Z0-9_]+)/i', $status, $match)) {
        return practiceNormalizeMapName($match[1]);
    }

    /**
     * Starszy fallback:
     * map : de_mirage
     */
    if (preg_match('/^\s*map\s*:\s*([a-zA-Z0-9_]+)/mi', $status, $match)) {
        return practiceNormalizeMapName($match[1]);
    }

    /**
     * Inny fallback:
     * map=de_mirage
     */
    if (preg_match('/\bmap\s*=\s*([a-zA-Z0-9_]+)/i', $status, $match)) {
        return practiceNormalizeMapName($match[1]);
    }

    return null;
}

function practiceCurrentMap(array $server): ?string {
    $status = practiceRconCommand($server, 'status');

    return practiceExtractMapFromStatus($status);
}

function practiceWaitForMap(array $server, string $expectedMap): void {
    $expectedMap = practiceNormalizeMapName($expectedMap);

    if (!$expectedMap) {
        throw new RuntimeException('Nieprawidłowa oczekiwana mapa.');
    }

    $timeout = max(5, min(90, (int)env('PRACTICE_MAP_LOAD_TIMEOUT', '35')));
    $pollMs = max(250, min(5000, (int)env('PRACTICE_MAP_LOAD_POLL_MS', '1000')));
    $afterDelayMs = max(0, min(10000, (int)env('PRACTICE_AFTER_MAP_DELAY_MS', '1500')));

    $deadline = microtime(true) + $timeout;
    $lastMap = null;
    $lastError = null;

    while (microtime(true) < $deadline) {
        try {
            $currentMap = practiceCurrentMap($server);

            if ($currentMap !== null) {
                $lastMap = $currentMap;
            }

            if ($currentMap === $expectedMap) {
                if ($afterDelayMs > 0) {
                    usleep($afterDelayMs * 1000);
                }

                return;
            }
        } catch (Throwable $e) {
            /**
             * Podczas changelevel RCON może chwilowo nie odpowiadać.
             * To normalne, więc retry.
             */
            $lastError = $e->getMessage();
        }

        usleep($pollMs * 1000);
    }

    $detail = $lastMap
        ? "Ostatnia wykryta mapa: {$lastMap}."
        : "Nie udało się odczytać mapy z komendy status.";

    if ($lastError) {
        $detail .= " Ostatni błąd RCON: {$lastError}";
    }

    throw new RuntimeException("Timeout ładowania mapy {$expectedMap}. {$detail}");
}

function practiceChangeMapAndRunAfterLoad(
    array $server,
    string $map,
    string $joinPassword,
    array $afterLoadCommands
): array {
    $responses = [];

    $responses = array_merge($responses, practiceRunCommands($server, [
        'say [Clutchify] Loading map ' . $map . '...',
        practicePasswordCommand($joinPassword),
        'changelevel ' . $map
    ]));

    practiceWaitForMap($server, $map);

    $commandsAfterLoad = array_merge(
        [
            practicePasswordCommand($joinPassword)
        ],
        $afterLoadCommands
    );

    $responses = array_merge($responses, practiceRunCommands($server, $commandsAfterLoad));

    return $responses;
}

function practiceStatusPayload(PDO $pdo, int $userId): array {
    $mySession = practiceActiveSessionForUser($pdo, $userId);
    $servers = practiceGameServers($pdo);

    $myServer = null;

    if ($mySession) {
        foreach ($servers as $server) {
            if ((int)$server['id'] === (int)$mySession['game_server_id']) {
                $myServer = $server;
                break;
            }
        }
    }

    $connect = $mySession ? practiceConnectStringFromSession($mySession) : null;

    $desktopConnect = null;

    if ($mySession) {
        $desktopConnect = [
            'address' => (string)$mySession['public_address'],
            'password' => practiceSessionJoinPassword($mySession),
            'server_name' => (string)($mySession['server_name'] ?? 'Practice Server'),
            'map_name' => (string)($mySession['map_name'] ?? '')
        ];

        unset(
            $mySession['connect_password'],
            $mySession['connect_password_encrypted']
        );
    }

    return [
        'enabled' => practiceEnabled(),
        'maps' => practiceMaps(),
        'servers' => $servers,
        'session' => $mySession,
        'server' => $myServer,
        'connect' => $connect,
        'desktop_connect' => $desktopConnect,
        'daily_quota' => practiceCanStartToday($pdo, $userId)
    ];
}

function practiceCurrentServerForUser(PDO $pdo, int $userId): array {
    $session = practiceActiveSessionForUser($pdo, $userId);

    if (!$session) {
        jsonError('Brak aktywnej sesji practice.');
    }

    $server = practiceGetGameServer($pdo, (int)$session['game_server_id']);

    if (!$server) {
        jsonError('Serwer przypisany do sesji nie istnieje albo jest wyłączony.');
    }

    return [$session, $server];
}

function practiceDailyLimitForNonAdmin(): int {
    return max(0, (int)env('PRACTICE_DAILY_LIMIT_NON_ADMIN', '1'));
}

function practiceUserStartsToday(PDO $pdo, int $userId): int {
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM practice_sessions
        WHERE user_id = ?
          AND started_at >= CURDATE()
          AND started_at < (CURDATE() + INTERVAL 1 DAY)
    ");
    $stmt->execute([$userId]);

    return (int)$stmt->fetchColumn();
}

function practiceCanStartToday(PDO $pdo, int $userId): array {
    if (practiceIsAdmin($pdo, $userId)) {
        return [
            'allowed' => true,
            'limit' => null,
            'used' => 0,
            'remaining' => null,
            'is_admin' => true
        ];
    }

    $limit = practiceDailyLimitForNonAdmin();

    if ($limit <= 0) {
        return [
            'allowed' => false,
            'limit' => 0,
            'used' => practiceUserStartsToday($pdo, $userId),
            'remaining' => 0,
            'is_admin' => false
        ];
    }

    $used = practiceUserStartsToday($pdo, $userId);
    $remaining = max(0, $limit - $used);

    return [
        'allowed' => $remaining > 0,
        'limit' => $limit,
        'used' => $used,
        'remaining' => $remaining,
        'is_admin' => false
    ];
}

if ($action === 'get_practice_status') {
    $userId = requireUserId();

    jsonSuccess(practiceStatusPayload($pdo, $userId));
}

if ($action === 'start_practice') {
    $userId = requireUserId();

    if (!practiceEnabled()) {
        jsonError('Practice mode jest wyłączony.');
    }

    practiceExpireOldSessions($pdo);

    $existingUserSession = practiceActiveSessionForUser($pdo, $userId);

    if (!$existingUserSession) {
        $quota = practiceCanStartToday($pdo, $userId);

        if (!$quota['allowed']) {
            jsonError('Wykorzystałeś dzisiejszy limit practice. Spróbuj ponownie jutro.');
        }
    }

    $input = getJsonInput();

    $map = (string)($input['map'] ?? 'de_mirage');
    $serverId = (int)($input['server_id'] ?? 0);

    $maps = practiceMaps();

    if (!isset($maps[$map])) {
        jsonError('Nieprawidłowa mapa.');
    }

    if ($serverId > 0) {
        $server = practiceGetGameServer($pdo, $serverId);

        if (!$server) {
            jsonError('Wybrany serwer practice nie istnieje albo jest wyłączony.');
        }

        $busySession = practiceServerIsBusy($pdo, $serverId, $userId);

        if ($busySession) {
            jsonError('Ten serwer jest aktualnie zajęty przez gracza ' . $busySession['username'] . '.');
        }
    } else {
        $server = practiceFindFreeServer($pdo);

        if (!$server) {
            jsonError('Brak wolnych serwerów practice.');
        }
    }

    try {
        $newSessionId = 0;

        $joinPassword = practiceShouldRotatePassword($server)
            ? practiceGenerateJoinPassword()
            : practiceServerDefaultPassword($server);

        $joinPasswordEncrypted = $joinPassword !== ''
            ? encryptSecret($joinPassword)
            : null;

        $previousSession = practiceActiveSessionForUser($pdo, $userId);

        if ($previousSession) {
            $previousServer = practiceGetGameServer($pdo, (int)$previousSession['game_server_id']);

            if ($previousServer) {
                try {
                    practiceRunCommands($previousServer, [
                        'say [Clutchify] Previous practice session closed',
                        practicePasswordCommand(practiceServerDefaultPassword($previousServer))
                    ]);
                } catch (Throwable $e) {
                    error_log('[Clutchify] Nie udało się zresetować hasła poprzedniej sesji practice: ' . $e->getMessage());
                }
            }
        }

        $stmt = $pdo->prepare("
            UPDATE practice_sessions
            SET status = 'ended',
                ended_at = NOW()
            WHERE status = 'active'
              AND user_id = ?
        ");
        $stmt->execute([$userId]);

        $stmt = $pdo->prepare("
            INSERT INTO practice_sessions (user_id, game_server_id, map_name, connect_password_encrypted)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([
            $userId,
            (int)$server['id'],
            $map,
            $joinPasswordEncrypted
        ]);
        $newSessionId = (int)$pdo->lastInsertId();

        practiceChangeMapAndRunAfterLoad($server, $map, $joinPassword, [
            'css_prac',
            'say [Clutchify] Practice mode ON'
        ]);

        jsonSuccess([
            'message' => 'Practice wystartował na serwerze ' . $server['name'] . '.',
            'status' => practiceStatusPayload($pdo, $userId)
        ]);
    } catch (Throwable $e) {
        if (!empty($newSessionId)) {
            $stmt = $pdo->prepare("
                UPDATE practice_sessions
                SET status = 'ended',
                    ended_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([(int)$newSessionId]);
        }

        try {
            practiceRunCommands($server, [
                'say [Clutchify] Practice start failed',
                practicePasswordCommand(practiceServerDefaultPassword($server))
            ]);
        } catch (Throwable $cleanupError) {
            error_log('[Clutchify] Practice cleanup failed: ' . $cleanupError->getMessage());
        }

        jsonError('Nie udało się wystartować practice: ' . $e->getMessage());
    }
}

if ($action === 'practice_action') {
    $userId = requireUserId();

    if (!practiceEnabled()) {
        jsonError('Practice mode jest wyłączony.');
    }

    [$session, $server] = practiceCurrentServerForUser($pdo, $userId);

    $input = getJsonInput();
    $practiceAction = (string)($input['practice_action'] ?? '');

    $map = (string)($input['map'] ?? '');
    $maps = practiceMaps();
    
    $sessionPassword = practiceSessionJoinPassword($session);

    $actions = [
        'restart' => [
            'mp_restartgame 1',
            'say [Clutchify] Round restarted'
        ],
        'kick_bots' => [
            'bot_kick',
            'say [Clutchify] Bots kicked'
        ],
        'add_bot_ct' => [
            'bot_add_ct'
        ],
        'add_bot_t' => [
            'bot_add_t'
        ],
        'pause' => [
            'mp_pause_match'
        ],
        'unpause' => [
            'mp_unpause_match'
        ],
        'practice_cfg' => [
            practicePasswordCommand($sessionPassword),
            'css_prac'
        ]
    ];

    if ($practiceAction === 'change_map') {
        if (!isset($maps[$map])) {
            jsonError('Nieprawidłowa mapa.');
        }

        $actions['change_map'] = [
            practicePasswordCommand($sessionPassword),
            'say [Clutchify] Changing map to ' . $map,
            'changelevel ' . $map
        ];
    }

    if (!isset($actions[$practiceAction])) {
        jsonError('Nieprawidłowa akcja practice.');
    }

    try {
        if ($practiceAction === 'change_map') {
            $responses = practiceChangeMapAndRunAfterLoad($server, $map, $sessionPassword, [
                'css_prac',
                'say [Clutchify] Practice mode ON'
            ]);
        } else {
            $responses = practiceRunCommands($server, $actions[$practiceAction]);
        }

        $stmt = $pdo->prepare("
            UPDATE practice_sessions
            SET last_action_at = NOW(),
                map_name = IF(? != '', ?, map_name)
            WHERE id = ?
        ");
        $stmt->execute([
            $practiceAction === 'change_map' ? $map : '',
            $map,
            (int)$session['id']
        ]);

        jsonSuccess([
            'message' => 'Akcja wykonana.',
            'responses' => $responses,
            'status' => practiceStatusPayload($pdo, $userId)
        ]);
    } catch (Throwable $e) {
        jsonError('Błąd RCON: ' . $e->getMessage());
    }
}

if ($action === 'end_practice') {
    $userId = requireUserId();

    [$session, $server] = practiceCurrentServerForUser($pdo, $userId);

    try {
        practiceRunCommands($server, [
            'say [Clutchify] Practice session ended',
            'bot_kick',
            'css_exitprac',
            practicePasswordCommand(practiceServerDefaultPassword($server))
        ]);

        $stmt = $pdo->prepare("
            UPDATE practice_sessions
            SET status = 'ended',
                ended_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([(int)$session['id']]);

        jsonSuccess([
            'message' => 'Practice zakończony.',
            'status' => practiceStatusPayload($pdo, $userId)
        ]);
    } catch (Throwable $e) {
        jsonError('Nie udało się zakończyć practice: ' . $e->getMessage());
    }
}

if ($action === 'test_practice_rcon') {
    $userId = requireUserId();

    if (!practiceIsAdmin($pdo, $userId)) {
        jsonError('Brak uprawnień administratora.', 403);
    }

    $input = getJsonInput();
    $serverId = (int)($input['server_id'] ?? 0);

    $server = $serverId > 0
        ? practiceGetGameServer($pdo, $serverId)
        : practiceFindFreeServer($pdo);

    if (!$server) {
        jsonError('Nie znaleziono serwera do testu.');
    }

    try {
        $started = microtime(true);

        $responses = practiceRunCommands($server, [
            'status'
        ]);

        jsonSuccess([
            'message' => 'RCON działa.',
            'duration_ms' => (int)round((microtime(true) - $started) * 1000),
            'server' => [
                'id' => (int)$server['id'],
                'name' => $server['name'],
                'rcon_host' => $server['rcon_host'],
                'rcon_port' => (int)$server['rcon_port']
            ],
            'responses' => $responses
        ]);
    } catch (Throwable $e) {
        jsonError('RCON test failed: ' . $e->getMessage());
    }
}