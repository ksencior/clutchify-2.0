<?php

use Thedudeguy\Rcon;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/secrets.php';

function matchzyPlayersPerTeam(): int {
    return max(1, min(10, (int)env('MATCHZY_PLAYERS_PER_TEAM', '5')));
}

function matchzyConfigHeaderName(): string {
    $header = env('MATCHZY_CONFIG_HEADER', 'X-Clutchify-Match');

    return preg_match('/^[A-Za-z0-9-]{3,80}$/', $header)
        ? $header
        : 'X-Clutchify-Match';
}

function matchzyConfigToken(): string {
    return bin2hex(random_bytes(32));
}

function matchzyConfigUrl(int $matchId): string {
    return rtrim(env('APP_URL', ''), '/') . '/matchzy/match_' . $matchId . '.json';
}

function matchzyEventHeaderName(): string {
    $header = env('MATCHZY_EVENT_HEADER', 'X-Clutchify-MatchZy-Event');

    return preg_match('/^[A-Za-z0-9-]{3,80}$/', $header)
        ? $header
        : 'X-Clutchify-MatchZy-Event';
}

function matchzyEventUrl(int $matchId): string {
    return rtrim(env('APP_URL', ''), '/') . '/matchzy/events';
}

function matchzyEnsureEventToken(PDO $pdo, int $matchId): string {
    $stmt = $pdo->prepare("
        SELECT matchzy_event_token
        FROM tournament_matches
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->execute([$matchId]);

    $token = trim((string)$stmt->fetchColumn());

    if ($token !== '') {
        return $token;
    }

    $token = bin2hex(random_bytes(32));

    $stmt = $pdo->prepare("
        UPDATE tournament_matches
        SET matchzy_event_token = ?,
            matchzy_event_url = ?
        WHERE id = ?
    ");
    $stmt->execute([
        $token,
        matchzyEventUrl($matchId),
        $matchId
    ]);

    return $token;
}

function matchzyJoinSeconds(): int {
    return max(60, min(1800, (int)env('MATCHZY_JOIN_SECONDS', '300')));
}

function matchzyGenerateConnectPassword(): string {
    $prefix = strtoupper(preg_replace('/[^A-Z0-9_-]/i', '', env('MATCHZY_CONNECT_PASSWORD_PREFIX', 'CFM')));

    if ($prefix === '') {
        $prefix = 'CFM';
    }

    return $prefix . '-' . strtoupper(bin2hex(random_bytes(4)));
}

function matchzyPasswordCommand(string $password): string {
    $password = trim($password);

    if ($password !== '' && !preg_match('/^[A-Za-z0-9_-]{4,32}$/', $password)) {
        throw new RuntimeException('Nieprawidłowe hasło serwera.');
    }

    return 'sv_password "' . $password . '"';
}

function matchzyConnectString(array $match): ?string {
    $address = trim((string)($match['game_server_public_address'] ?? ''));

    if ($address === '') {
        return null;
    }

    $password = '';

    if (!empty($match['connect_password_encrypted'])) {
        try {
            $password = decryptSecret((string)$match['connect_password_encrypted']);
        } catch (Throwable $e) {
            $password = '';
        }
    }

    return $password !== ''
        ? 'connect ' . $address . '; password ' . $password
        : 'connect ' . $address;
}

function matchzyStorageDir(): string {
    $dir = __DIR__ . '/../storage/matchzy';

    if (!is_dir($dir)) {
        if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Nie udało się utworzyć katalogu storage/matchzy.');
        }
    }

    return $dir;
}

function matchzyConfigFilePath(int $matchId): string {
    return matchzyStorageDir() . '/match_' . $matchId . '.json';
}

function matchzyWriteConfigFile(PDO $pdo, array $match): string {
    $matchId = (int)$match['id'];
    $config = matchzyBuildConfig($pdo, $match);

    $json = json_encode(
        $config,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );

    if ($json === false) {
        throw new RuntimeException('Nie udało się zakodować MatchZy JSON.');
    }

    $path = matchzyConfigFilePath($matchId);
    $tmpPath = $path . '.tmp';

    if (file_put_contents($tmpPath, $json . PHP_EOL, LOCK_EX) === false) {
        throw new RuntimeException('Nie udało się zapisać tymczasowego pliku MatchZy JSON.');
    }

    if (!rename($tmpPath, $path)) {
        @unlink($tmpPath);
        throw new RuntimeException('Nie udało się zapisać pliku MatchZy JSON.');
    }

    @chmod($path, 0664);

    return $path;
}

function matchzyReadConfigFile(int $matchId): ?string {
    $path = matchzyConfigFilePath($matchId);

    if (!is_file($path)) {
        return null;
    }

    $json = file_get_contents($path);

    return is_string($json) && trim($json) !== '' ? $json : null;
}

function matchzyRconQuote(string $value): string {
    if (str_contains($value, '"')) {
        throw new RuntimeException('Nieprawidłowy argument RCON.');
    }

    return '"' . $value . '"';
}

function matchzyLoadCommand(int $matchId, string $token): string {
    $url = matchzyConfigUrl($matchId);
    $header = matchzyConfigHeaderName();

    return 'matchzy_loadmatch_url '
        . matchzyRconQuote($url)
        . ' '
        . matchzyRconQuote($header)
        . ' '
        . matchzyRconQuote($token);
}

function matchzyEnsureToken(PDO $pdo, int $matchId): string {
    $stmt = $pdo->prepare("
        SELECT matchzy_config_token
        FROM tournament_matches
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->execute([$matchId]);

    $token = (string)$stmt->fetchColumn();

    if ($token !== '') {
        return $token;
    }

    $token = matchzyConfigToken();

    $stmt = $pdo->prepare("
        UPDATE tournament_matches
        SET matchzy_config_token = ?,
            matchzy_config_url = ?
        WHERE id = ?
    ");
    $stmt->execute([
        $token,
        matchzyConfigUrl($matchId),
        $matchId
    ]);

    return $token;
}

function matchzyGameServer(PDO $pdo, int $serverId): ?array {
    $stmt = $pdo->prepare("
        SELECT *
        FROM game_servers
        WHERE id = ?
          AND is_enabled = 1
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

function matchzyFindFreeMatchServer(PDO $pdo): ?array {
    $stmt = $pdo->query("
        SELECT gs.*
        FROM game_servers gs
        LEFT JOIN practice_sessions ps
            ON ps.game_server_id = gs.id
           AND ps.status = 'active'
        LEFT JOIN tournament_matches tm
            ON tm.game_server_id = gs.id
           AND tm.status IN ('pending', 'ready_check', 'live')
        WHERE gs.is_enabled = 1
          AND gs.purpose IN ('match', 'both')
          AND ps.id IS NULL
          AND tm.id IS NULL
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

function matchzyAssignServerIfNeeded(PDO $pdo, array $match): array {
    if (!empty($match['game_server_id'])) {
        $server = matchzyGameServer($pdo, (int)$match['game_server_id']);

        if (!$server) {
            throw new RuntimeException('Przypisany serwer gry jest wyłączony albo nie istnieje.');
        }

        return $server;
    }

    $server = matchzyFindFreeMatchServer($pdo);

    if (!$server) {
        throw new RuntimeException('Brak wolnych serwerów match/both.');
    }

    $stmt = $pdo->prepare("
        UPDATE tournament_matches
        SET game_server_id = ?,
            server_assigned_at = NOW()
        WHERE id = ?
          AND game_server_id IS NULL
    ");
    $stmt->execute([
        (int)$server['id'],
        (int)$match['id']
    ]);

    return $server;
}

function matchzyRunCommands(array $server, array $commands): array {
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

function matchzyServerStatus(array $server): string {
    $responses = matchzyRunCommands($server, ['status']);

    return (string)($responses[0]['response'] ?? '');
}

function matchzyHumanCountFromStatus(string $status): int {
    /**
     * CS2 status:
     * players  : 1 humans, 1 bots (10 max) ...
     */
    if (preg_match('/players\s*:\s*(\d+)\s+humans?/i', $status, $match)) {
        return max(0, (int)$match[1]);
    }

    /**
     * Fallback na tabelkę players.
     */
    $humans = 0;

    foreach (preg_split('/\R/', $status) as $line) {
        $line = trim($line);

        if ($line === '') {
            continue;
        }

        if (
            str_contains($line, 'BOT')
            || str_contains($line, 'SourceTV')
            || str_contains($line, '[NoChan]')
            || str_contains($line, 'challenging')
            || str_starts_with($line, 'id ')
            || str_starts_with($line, '#')
        ) {
            continue;
        }

        /**
         * Przykład:
         * 2 00:12 15 0 active 196608 1.2.3.4:27005 PlayerName
         */
        if (preg_match('/^\d+\s+[\d:]+\s+\d+\s+\d+\s+active\s+/i', $line)) {
            $humans++;
        }
    }

    return $humans;
}

function matchzyServerHasHumanPlayer(PDO $pdo, array $match): bool {
    if (empty($match['game_server_id'])) {
        return false;
    }

    $server = matchzyGameServer($pdo, (int)$match['game_server_id']);

    if (!$server) {
        return false;
    }

    $status = matchzyServerStatus($server);
    $humans = matchzyHumanCountFromStatus($status);

    return $humans > 0;
}

function matchzyTeamDisplayName(array $match, string $side): string {
    if ($side === 'a') {
        return ($match['team_a_name'] ?? 'Team 1');
    }

    return ($match['team_b_name'] ?? 'Team 2');
}

function matchzyTeamPlayers(PDO $pdo, int $teamId, int $limit): array {
    $stmt = $pdo->prepare("
        SELECT
            u.username,
            p.steam_id,
            p.is_substitute
        FROM players p
        JOIN users u ON u.id = p.user_id
        WHERE p.team_id = ?
        ORDER BY p.is_substitute ASC, u.username ASC
    ");
    $stmt->execute([$teamId]);

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $players = [];
    $missingSteam = [];

    foreach ($rows as $row) {
        $steamId = trim((string)($row['steam_id'] ?? ''));

        if ($steamId === '' || !preg_match('/^[0-9]{17}$/', $steamId)) {
            if (!(bool)$row['is_substitute']) {
                $missingSteam[] = $row['username'];
            }

            continue;
        }

        $players[$steamId] = (string)$row['username'];

        if (count($players) >= $limit) {
            break;
        }
    }

    return [
        'players' => $players,
        'missing_steam' => $missingSteam
    ];
}

function matchzyBuildTeamBlock(PDO $pdo, array $match, string $side): array {
    $teamId = $side === 'a'
        ? (int)$match['team_a_id']
        : (int)$match['team_b_id'];

    $limit = matchzyPlayersPerTeam();
    $result = matchzyTeamPlayers($pdo, $teamId, $limit);

    if ($result['missing_steam']) {
        throw new RuntimeException(
            'Brakuje Steam64 ID u graczy: ' . implode(', ', $result['missing_steam'])
        );
    }

    if (count($result['players']) < $limit) {
        throw new RuntimeException(
            matchzyTeamDisplayName($match, $side) . ' nie ma wymaganej liczby graczy ze Steam64 ID. Wymagane: ' . $limit . '.'
        );
    }

    return [
        'name' => matchzyTeamDisplayName($match, $side),
        'players' => $result['players']
    ];
}

function matchzyMatchSettings(array $match): array {
    $decoded = json_decode($match['match_settings_json'] ?? '', true);
    $settings = is_array($decoded) ? $decoded : [];

    $mr = (int)($settings['mr'] ?? 12);

    if (!in_array($mr, [12, 15], true)) {
        $mr = 12;
    }

    return [
        'friendly_fire' => !empty($settings['friendly_fire']),
        'overtime_enabled' => array_key_exists('overtime_enabled', $settings)
            ? !empty($settings['overtime_enabled'])
            : true,
        'knife_round' => array_key_exists('knife_round', $settings)
            ? !empty($settings['knife_round'])
            : true,
        'mr' => $mr
    ];
}

function matchzySideForMap(array $match, array $map, bool $knifeRound): string {
    if (!empty($map['side_team_id']) && !empty($map['side_choice'])) {
        $sideTeamId = (int)$map['side_team_id'];
        $choice = strtolower((string)$map['side_choice']);

        if ($sideTeamId === (int)$match['team_a_id']) {
            return $choice === 'ct' ? 'team1_ct' : 'team2_ct';
        }

        if ($sideTeamId === (int)$match['team_b_id']) {
            return $choice === 'ct' ? 'team2_ct' : 'team1_ct';
        }
    }

    return $knifeRound ? 'knife' : 'team1_ct';
}

function matchzyBuildConfig(PDO $pdo, array $match): array {
    if (!function_exists('matchVetoState')) {
        throw new RuntimeException('Brak funkcji matchVetoState(). Sprawdź kolejność require w api.php.');
    }

    $state = matchVetoState($pdo, $match, 0, true, null);

    if (empty($state['completed'])) {
        throw new RuntimeException('Veto map nie jest jeszcze zakończone.');
    }

    $finalMaps = $state['final_maps'] ?? [];

    if (!$finalMaps) {
        throw new RuntimeException('Brak finalnych map z veto.');
    }

    $settings = matchzyMatchSettings($match);
    $mapList = array_map(fn($item) => $item['map_name'], $finalMaps);
    $mapSides = array_map(
        fn($item) => matchzySideForMap($match, $item, $settings['knife_round']),
        $finalMaps
    );

    $team1 = matchzyBuildTeamBlock($pdo, $match, 'a');
    $team2 = matchzyBuildTeamBlock($pdo, $match, 'b');

    $hostname = 'Clutchify | '
        . ($match['team_a_tag'] ?? 'T1')
        . ' vs '
        . ($match['team_b_tag'] ?? 'T2');

    return [
        'matchid' => (int)$match['id'],
        'team1' => $team1,
        'team2' => $team2,
        'num_maps' => count($mapList),
        'maplist' => $mapList,
        'map_sides' => $mapSides,
        'clinch_series' => true,
        'players_per_team' => matchzyPlayersPerTeam(),
        'cvars' => [
            'hostname' => $hostname,
            'mp_friendlyfire' => $settings['friendly_fire'] ? '1' : '0',
            'mp_overtime_enable' => $settings['overtime_enabled'] ? '1' : '0',
            'mp_maxrounds' => (string)($settings['mr'] * 2),
            'mp_halftime' => '1',

            'matchzy_remote_log_url' => matchzyEventUrl((int)$match['id']),
            'matchzy_remote_log_header_key' => matchzyEventHeaderName(),
            'matchzy_remote_log_header_value' => matchzyEnsureEventToken($pdo, (int)$match['id']),
            'matchzy_reset_cvars_on_series_end' => '1'
        ]
    ];
}

function matchzyPrepareMatchServer(PDO $pdo, array $match): bool {
    $matchId = (int)$match['id'];

    $server = matchzyAssignServerIfNeeded($pdo, $match);
    $configToken = matchzyEnsureToken($pdo, $matchId);
    $eventToken = matchzyEnsureEventToken($pdo, $matchId);

    $url = matchzyConfigUrl($matchId);
    $eventUrl = matchzyEventUrl($matchId);

    $connectPassword = matchzyGenerateConnectPassword();
    $connectPasswordEncrypted = encryptSecret($connectPassword);

    /**
     * Odświeżamy match po przypisaniu serwera i tokenów.
     */
    if (function_exists('matchLobbyGetMatch')) {
        $freshMatch = matchLobbyGetMatch($pdo, $matchId);
        if ($freshMatch) {
            $match = $freshMatch;
        }
    }

    /**
     * Tworzymy JSON już teraz, ale NIE ładujemy go jeszcze w MatchZy.
     */
    matchzyWriteConfigFile($pdo, $match);

    $delayMs = max(0, min(10000, (int)env('MATCHZY_LOAD_DELAY_MS', '1500')));

    if ($delayMs > 0) {
        usleep($delayMs * 1000);
    }

    matchzyRunCommands($server, [
        'say [Clutchify] Match server prepared. Waiting for first player...',
        matchzyPasswordCommand($connectPassword),
        'matchzy_remote_log_url ' . matchzyRconQuote($eventUrl),
        'matchzy_remote_log_header_key ' . matchzyRconQuote(matchzyEventHeaderName()),
        'matchzy_remote_log_header_value ' . matchzyRconQuote($eventToken)
    ]);

    $stmt = $pdo->prepare("
        UPDATE tournament_matches
        SET status = 'server_ready',
            server_ready_at = NOW(),
            join_deadline_at = DATE_ADD(NOW(), INTERVAL " . matchzyJoinSeconds() . " SECOND),
            connect_password_encrypted = ?,
            matchzy_config_url = ?,
            matchzy_event_url = ?,
            matchzy_loaded_at = NULL,
            matchzy_load_error = NULL
        WHERE id = ?
    ");

    $stmt->execute([
        $connectPasswordEncrypted,
        $url,
        $eventUrl,
        $matchId
    ]);

    return true;
}

function matchzyLoadPreparedMatch(PDO $pdo, array $match): bool {
    $matchId = (int)$match['id'];

    if (!empty($match['matchzy_loaded_at'])) {
        return false;
    }

    if (empty($match['game_server_id'])) {
        throw new RuntimeException('Brak przypisanego serwera gry.');
    }

    $server = matchzyGameServer($pdo, (int)$match['game_server_id']);

    if (!$server) {
        throw new RuntimeException('Przypisany serwer gry jest wyłączony albo nie istnieje.');
    }

    $configToken = matchzyEnsureToken($pdo, $matchId);
    $command = matchzyLoadCommand($matchId, $configToken);

    /**
     * Upewniamy się, że plik JSON istnieje tuż przed loadem.
     */
    matchzyWriteConfigFile($pdo, $match);

    matchzyRunCommands($server, [
        'say [Clutchify] First player detected. Loading MatchZy config...',
        $command
    ]);

    $stmt = $pdo->prepare("
        UPDATE tournament_matches
        SET matchzy_loaded_at = NOW(),
            matchzy_load_error = NULL
        WHERE id = ?
    ");
    $stmt->execute([$matchId]);

    return true;
}

function matchzyLoadPreparedMatchIfHumanJoined(PDO $pdo, array $match): array {
    if (($match['status'] ?? '') !== 'server_ready') {
        return [
            'loaded' => false,
            'has_human' => false,
            'message' => 'Serwer nie jest w fazie oczekiwania na graczy.'
        ];
    }

    if (!empty($match['matchzy_loaded_at'])) {
        return [
            'loaded' => false,
            'has_human' => true,
            'message' => 'MatchZy config jest już wczytany.'
        ];
    }

    $hasHuman = matchzyServerHasHumanPlayer($pdo, $match);

    if (!$hasHuman) {
        return [
            'loaded' => false,
            'has_human' => false,
            'message' => 'Brak gracza na serwerze.'
        ];
    }

    $loaded = matchzyLoadPreparedMatch($pdo, $match);

    return [
        'loaded' => $loaded,
        'has_human' => true,
        'message' => $loaded
            ? 'Wykryto gracza. MatchZy config został wczytany.'
            : 'Gracz jest na serwerze, config był już wczytany.'
    ];
}

function matchzyExtractPlayerIdsFromStatus(string $status): array {
    $ids = [];

    foreach (preg_split('/\R/', $status) as $line) {
        $line = trim($line);

        if ($line === '' || str_contains($line, 'SourceTV') || str_contains($line, 'BOT')) {
            continue;
        }

        if (preg_match('/^(\d+)\s+\S+\s+\d+\s+\d+\s+\w+\s+\d+\s+(.+)$/', $line, $match)) {
            $id = (int)$match[1];

            if ($id > 0 && $id !== 65535) {
                $ids[] = $id;
            }
        }
    }

    return array_values(array_unique($ids));
}

function matchzyResetServerAfterMatch(PDO $pdo, array $match): void {
    if (empty($match['game_server_id'])) {
        return;
    }

    $server = matchzyGameServer($pdo, (int)$match['game_server_id']);

    if (!$server) {
        return;
    }

    $resetCommand = trim((string)env('MATCHZY_RESET_COMMAND', 'css_restart'));
    $resetMap = trim((string)env('MATCHZY_RESET_MAP', 'de_mirage'));
    $kickPlayers = (int)env('MATCHZY_END_KICK_PLAYERS', '1') === 1;
    $delayMs = max(0, min(15000, (int)env('MATCHZY_RESET_DELAY_MS', '2500')));

    $commands = [
        'say [Clutchify] Match finished. Resetting server...',
        'matchzy_remote_log_url ""',
        'matchzy_remote_log_header_key ""',
        'matchzy_remote_log_header_value ""'
    ];

    if ($resetCommand !== '') {
        $commands[] = $resetCommand;
    }

    $commands[] = matchzyPasswordCommand('');

    matchzyRunCommands($server, $commands);

    if ($delayMs > 0) {
        usleep($delayMs * 1000);
    }

    if ($kickPlayers) {
        try {
            $responses = matchzyRunCommands($server, ['status']);
            $status = $responses[0]['response'] ?? '';
            $ids = matchzyExtractPlayerIdsFromStatus($status);

            foreach ($ids as $id) {
                try {
                    matchzyRunCommands($server, [
                        'kickid ' . (int)$id . ' "Scrim zakończony - serwer resetowany"'
                    ]);
                } catch (Throwable $e) {
                    error_log('[Clutchify] kickid failed: ' . $e->getMessage());
                }
            }
        } catch (Throwable $e) {
            error_log('[Clutchify] status/kick cleanup failed: ' . $e->getMessage());
        }
    }

    if ($resetMap !== '' && preg_match('/^[a-z0-9_]{3,64}$/i', $resetMap)) {
        try {
            matchzyRunCommands($server, [
                'changelevel ' . $resetMap
            ]);
        } catch (Throwable $e) {
            error_log('[Clutchify] reset map failed: ' . $e->getMessage());
        }
    }
}