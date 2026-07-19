<?php
require_once __DIR__ . '/../helpers/matchzy.php';
function matchLobbyIsAdmin(PDO $pdo, int $userId): bool {
    $stmt = $pdo->prepare("SELECT isAdmin FROM players WHERE user_id = ? LIMIT 1");
    $stmt->execute([$userId]);

    return (bool)$stmt->fetchColumn();
}

function matchLobbyGetUserTeamId(PDO $pdo, int $userId): ?int {
    $stmt = $pdo->prepare("SELECT team_id FROM players WHERE user_id = ? LIMIT 1");
    $stmt->execute([$userId]);

    $teamId = $stmt->fetchColumn();

    return $teamId !== false && $teamId !== null ? (int)$teamId : null;
}

function matchLobbyGetMatch(PDO $pdo, int $matchId): ?array {
    $stmt = $pdo->prepare("
        SELECT
            tm.id,
            tm.tournament_id,
            tm.round_number,
            tm.match_number,
            tm.match_format,
            tm.match_source,
            tm.scrim_post_id,
            tm.scrim_offer_id,
            tm.match_settings_json,
            tm.game_server_id,
            tm.server_assigned_at,
            tm.matchzy_config_token,
            tm.matchzy_config_url,
            tm.matchzy_loaded_at,
            tm.matchzy_load_error,
            gs.name AS game_server_name,
            gs.public_address AS game_server_public_address,
            tm.veto_status,
            tm.veto_started_at,
            tm.veto_turn_started_at,
            tm.veto_completed_at,
            tm.team_a_id,
            tm.team_b_id,
            tm.winner_team_id,
            tm.status,
            tm.scheduled_at,
            tm.started_at,
            tm.finished_at,
            tm.created_at,

            t.title AS tournament_title,
            t.status AS tournament_status,

            ta.name AS team_a_name,
            ta.tag AS team_a_tag,
            ta.logo AS team_a_logo,

            tb.name AS team_b_name,
            tb.tag AS team_b_tag,
            tb.logo AS team_b_logo,

            tw.name AS winner_team_name,
            tw.tag AS winner_team_tag
        FROM tournament_matches tm
        JOIN tournaments t ON t.id = tm.tournament_id
        LEFT JOIN teams ta ON ta.id = tm.team_a_id
        LEFT JOIN teams tb ON tb.id = tm.team_b_id
        LEFT JOIN teams tw ON tw.id = tm.winner_team_id
        LEFT JOIN game_servers gs ON gs.id = tm.game_server_id
        WHERE tm.id = ?
        LIMIT 1
    ");
    $stmt->execute([$matchId]);

    $match = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$match) {
        return null;
    }

    foreach (['id', 'tournament_id', 'round_number', 'match_number'] as $field) {
        $match[$field] = (int)$match[$field];
    }

    foreach (['team_a_id', 'team_b_id', 'winner_team_id'] as $field) {
        $match[$field] = $match[$field] !== null ? (int)$match[$field] : null;
    }

    foreach (['scrim_post_id', 'scrim_offer_id', 'game_server_id'] as $field) {
        if (array_key_exists($field, $match)) {
            $match[$field] = $match[$field] !== null ? (int)$match[$field] : null;
        }
    }

    $settings = json_decode($match['match_settings_json'] ?? '', true);
    $match['match_settings'] = is_array($settings) ? $settings : [];

    return $match;
}

function matchLobbyStatusLabel(string $status): string {
    return match ($status) {
        'pending' => 'Oczekuje',
        'ready_check' => 'Ready check',
        'live' => 'Live',
        'finished' => 'Zakończony',
        'cancelled' => 'Anulowany',
        default => $status
    };
}

function matchLobbyCanView(array $match, bool $isAdmin, ?int $viewerTeamId): bool {
    if ($isAdmin) {
        return true;
    }

    if (!$viewerTeamId) {
        return false;
    }

    return in_array($viewerTeamId, [
        $match['team_a_id'],
        $match['team_b_id']
    ], true);
}

function matchLobbyTeamPlayers(PDO $pdo, ?int $teamId, int $matchId): array {
    if (!$teamId) {
        return [];
    }

    $stmt = $pdo->prepare("
        SELECT
            u.id,
            u.username,
            p.avatar,
            p.is_substitute,
            COALESCE(mrc.is_ready, 0) AS is_ready,
            mrc.ready_at
        FROM players p
        JOIN users u ON u.id = p.user_id
        LEFT JOIN match_ready_checks mrc
            ON mrc.user_id = u.id
           AND mrc.match_id = ?
        WHERE p.team_id = ?
        ORDER BY p.is_substitute ASC, u.username ASC
    ");
    $stmt->execute([$matchId, $teamId]);

    $players = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($players as &$player) {
        $player['id'] = (int)$player['id'];
        $player['is_substitute'] = (bool)$player['is_substitute'];
        $player['is_ready'] = (bool)$player['is_ready'];
        $player['required_ready'] = !$player['is_substitute'];
    }

    return $players;
}

function matchLobbyReadySummary(array $teamAPlayers, array $teamBPlayers): array {
    $countRequired = function (array $players): array {
        $required = array_values(array_filter($players, fn($player) => !empty($player['required_ready'])));

        return [
            'ready' => count(array_filter($required, fn($player) => !empty($player['is_ready']))),
            'required' => count($required)
        ];
    };

    $teamA = $countRequired($teamAPlayers);
    $teamB = $countRequired($teamBPlayers);

    $totalReady = $teamA['ready'] + $teamB['ready'];
    $totalRequired = $teamA['required'] + $teamB['required'];

    return [
        'team_a' => $teamA,
        'team_b' => $teamB,
        'total_ready' => $totalReady,
        'total_required' => $totalRequired,
        'all_ready' => $totalRequired > 0 && $totalReady >= $totalRequired
    ];
}

function matchLobbyTargetUserIds(PDO $pdo, array $match): array {
    $targetIds = [];

    $teamIds = array_values(array_filter([
        $match['team_a_id'] ?? null,
        $match['team_b_id'] ?? null
    ]));

    if ($teamIds) {
        $placeholders = implode(',', array_fill(0, count($teamIds), '?'));

        $stmt = $pdo->prepare("
            SELECT user_id
            FROM players
            WHERE team_id IN ({$placeholders})
        ");
        $stmt->execute($teamIds);

        $targetIds = array_merge(
            $targetIds,
            array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN))
        );
    }

    $stmt = $pdo->query("
        SELECT user_id
        FROM players
        WHERE isAdmin = 1
    ");

    $targetIds = array_merge(
        $targetIds,
        array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN))
    );

    $targetIds = array_values(array_unique(array_filter($targetIds)));

    return $targetIds;
}
function matchVetoTurnSeconds(): int {
    return max(10, min(120, (int)env('MATCH_VETO_TURN_SECONDS', '30')));
}

function matchVetoFormats(): array {
    return ['bo1', 'bo3', 'bo5'];
}

function matchVetoNormalizeFormat(?string $format): string {
    return in_array($format, matchVetoFormats(), true) ? $format : 'bo1';
}

function matchVetoMapPool(): array {
    $raw = env('MATCH_MAP_POOL', 'de_mirage,de_inferno,de_nuke,de_anubis,de_ancient,de_dust2,de_vertigo');

    $maps = array_map('trim', explode(',', $raw));
    $maps = array_filter($maps, fn($map) => preg_match('/^[a-z0-9_]{3,64}$/i', $map));

    return array_values(array_unique($maps));
}

function matchVetoTeamSide(array $match, ?int $teamId): ?string {
    if (!$teamId) return null;
    if ((int)$match['team_a_id'] === $teamId) return 'a';
    if ((int)$match['team_b_id'] === $teamId) return 'b';

    return null;
}

function matchVetoTeamLabel(array $match, ?int $teamId): string {
    $side = matchVetoTeamSide($match, $teamId);

    if ($side === 'a') {
        return ($match['team_a_tag'] ? '[' . $match['team_a_tag'] . '] ' : '') . ($match['team_a_name'] ?? 'Team 1');
    }

    if ($side === 'b') {
        return ($match['team_b_tag'] ? '[' . $match['team_b_tag'] . '] ' : '') . ($match['team_b_name'] ?? 'Team 2');
    }

    return 'System';
}

function matchVetoState(PDO $pdo, array $match, int $viewerId, bool $isAdmin, ?int $viewerTeamId): array {
    $status = $match['veto_status'] ?? 'not_started';
    $format = matchVetoNormalizeFormat($match['match_format'] ?? 'bo1');
    $pool = matchVetoMapPool();
    $rows = matchVetoRows($pdo, (int)$match['id']);

    foreach ($rows as &$row) {
        $row['actor_side'] = matchVetoTeamSide($match, $row['actor_team_id']);
        $row['actor_label'] = matchVetoTeamLabel($match, $row['actor_team_id']);

        if ($row['action'] === 'decider') {
            $row['actor_label'] = 'Decider';
        }
    }

    if ($status === 'not_started') {
        return [
            'status' => 'not_started',
            'format' => $format,
            'map_pool' => $pool,
            'actions' => $rows,
            'available_maps' => $pool,
            'current' => null,
            'viewer_can_act' => false,
            'completed' => false,
            'turn' => null,
            'final_maps' => []
        ];
    }

    if ($status === 'completed') {
        return [
            'status' => 'completed',
            'format' => $format,
            'map_pool' => $pool,
            'actions' => $rows,
            'available_maps' => [],
            'current' => null,
            'viewer_can_act' => false,
            'completed' => true,
            'turn' => null,
            'final_maps' => matchVetoFinalMaps($match, $rows)
        ];
    }

    $current = matchVetoCurrentFromPlan($match, $rows);

    $usedMaps = matchVetoUsedMaps($rows);
    $availableMaps = array_values(array_diff($pool, $usedMaps));

    $viewerCanAct = false;

    if ($current && !empty($current['actor_team_id'])) {
        $viewerCanAct = matchVetoUserCanAct(
            $pdo,
            $match,
            $viewerId,
            $isAdmin,
            $viewerTeamId,
            (int)$current['actor_team_id']
        );
    }

    return [
        'status' => 'active',
        'format' => $format,
        'map_pool' => $pool,
        'actions' => $rows,
        'available_maps' => $availableMaps,
        'current' => $current ? [
            'step_number' => (int)$current['step_number'],
            'action' => $current['action'],
            'actor_team_id' => $current['actor_team_id'],
            'actor_side' => matchVetoTeamSide($match, $current['actor_team_id']),
            'actor_label' => matchVetoTeamLabel($match, $current['actor_team_id']),
            'map_name' => $current['map_name'] ?? null,
            'picked_by_team_id' => $current['picked_by_team_id'] ?? null
        ] : null,
        'viewer_can_act' => $viewerCanAct,
        'completed' => false,
        'turn' => matchVetoTurnMeta($match),
        'final_maps' => []
    ];
}

function matchVetoRows(PDO $pdo, int $matchId): array {
    $stmt = $pdo->prepare("
        SELECT *
        FROM match_map_veto
        WHERE match_id = ?
        ORDER BY step_number ASC
    ");
    $stmt->execute([$matchId]);

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$row) {
        $row['id'] = (int)$row['id'];
        $row['match_id'] = (int)$row['match_id'];
        $row['step_number'] = (int)$row['step_number'];
        $row['actor_team_id'] = $row['actor_team_id'] !== null ? (int)$row['actor_team_id'] : null;
        $row['created_by'] = $row['created_by'] !== null ? (int)$row['created_by'] : null;
        $row['is_auto'] = (bool)($row['is_auto'] ?? false);
    }

    return $rows;
}

function matchVetoPlan(array $match): array {
    $format = matchVetoNormalizeFormat($match['match_format'] ?? 'bo1');
    $teamA = (int)$match['team_a_id'];
    $teamB = (int)$match['team_b_id'];

    $step = 1;
    $plan = [];

    $add = function (string $action, ?int $actorTeamId) use (&$plan, &$step) {
        $plan[] = [
            'step_number' => $step++,
            'action' => $action,
            'actor_team_id' => $actorTeamId
        ];
    };

    if ($format === 'bo1') {
        $banCount = max(0, count(matchVetoMapPool()) - 1);

        for ($i = 0; $i < $banCount; $i++) {
            $add('ban', $i % 2 === 0 ? $teamA : $teamB);
        }

        $add('decider', null);
        return $plan;
    }

    if ($format === 'bo3') {
        $add('ban', $teamA);
        $add('ban', $teamB);

        $add('pick', $teamA);
        $add('side', $teamB);

        $add('pick', $teamB);
        $add('side', $teamA);

        $add('ban', $teamA);
        $add('ban', $teamB);

        $add('decider', null);

        return $plan;
    }

    /**
     * BO5:
     * T1 Ban, T2 Ban,
     * T1 Pick/T2 Side,
     * T2 Pick/T1 Side,
     * T1 Pick/T2 Side,
     * T2 Pick/T1 Side,
     * Decider.
     */
    $add('ban', $teamA);
    $add('ban', $teamB);

    $add('pick', $teamA);
    $add('side', $teamB);

    $add('pick', $teamB);
    $add('side', $teamA);

    $add('pick', $teamA);
    $add('side', $teamB);

    $add('pick', $teamB);
    $add('side', $teamA);

    $add('decider', null);

    return $plan;
}

function matchVetoUsedMaps(array $rows): array {
    $used = [];

    foreach ($rows as $row) {
        if (in_array($row['action'], ['ban', 'pick', 'decider'], true)) {
            $used[] = $row['map_name'];
        }
    }

    return array_values(array_unique($used));
}

function matchVetoLastPickBeforeStep(array $rows, int $stepNumber): ?array {
    $lastPick = null;

    foreach ($rows as $row) {
        if ((int)$row['step_number'] >= $stepNumber) {
            continue;
        }

        if ($row['action'] === 'pick') {
            $lastPick = $row;
        }
    }

    return $lastPick;
}

function matchVetoCurrentFromPlan(array $match, array $rows): ?array {
    $plan = matchVetoPlan($match);

    $rowsByStep = [];

    foreach ($rows as $row) {
        $rowsByStep[(int)$row['step_number']] = $row;
    }

    foreach ($plan as $planned) {
        $step = (int)$planned['step_number'];

        if (isset($rowsByStep[$step])) {
            continue;
        }

        if ($planned['action'] === 'side') {
            $lastPick = matchVetoLastPickBeforeStep($rows, $step);

            if ($lastPick) {
                $planned['map_name'] = $lastPick['map_name'];
                $planned['picked_by_team_id'] = $lastPick['actor_team_id'];
            }
        }

        return $planned;
    }

    return null;
}

function matchVetoTurnMeta(array $match): array {
    $turnSeconds = matchVetoTurnSeconds();
    $startedAt = $match['veto_turn_started_at'] ?? null;

    if (!$startedAt) {
        return [
            'turn_seconds' => $turnSeconds,
            'started_at' => null,
            'deadline_at' => null,
            'remaining_seconds' => $turnSeconds,
            'timed_out' => false
        ];
    }

    $startedTs = strtotime($startedAt);
    $deadlineTs = $startedTs + $turnSeconds;
    $remaining = max(0, $deadlineTs - time());

    return [
        'turn_seconds' => $turnSeconds,
        'started_at' => $startedAt,
        'deadline_at' => date('Y-m-d H:i:s', $deadlineTs),
        'remaining_seconds' => $remaining,
        'timed_out' => $remaining <= 0
    ];
}

function matchVetoFinalMaps(array $match, array $rows): array {
    $final = [];

    foreach ($rows as $row) {
        if (!in_array($row['action'], ['pick', 'decider'], true)) {
            continue;
        }

        $sideRow = null;

        if ($row['action'] === 'pick') {
            foreach ($rows as $candidate) {
                if (
                    $candidate['action'] === 'side'
                    && (int)$candidate['step_number'] > (int)$row['step_number']
                    && $candidate['map_name'] === $row['map_name']
                ) {
                    $sideRow = $candidate;
                    break;
                }
            }
        }

        $final[] = [
            'map_name' => $row['map_name'],
            'source' => $row['action'],
            'picked_by_team_id' => $row['actor_team_id'],
            'picked_by_label' => $row['action'] === 'pick'
                ? matchVetoTeamLabel($match, $row['actor_team_id'])
                : 'Decider',
            'side_team_id' => $sideRow['actor_team_id'] ?? null,
            'side_team_label' => $sideRow
                ? matchVetoTeamLabel($match, $sideRow['actor_team_id'])
                : null,
            'side_choice' => $sideRow['side_choice'] ?? null
        ];
    }

    return $final;
}

function matchVetoUserCanAct(PDO $pdo, array $match, int $userId, bool $isAdmin, ?int $viewerTeamId, int $actorTeamId): bool {
    if ($isAdmin) {
        return true;
    }

    if (!$viewerTeamId || $viewerTeamId !== $actorTeamId) {
        return false;
    }

    $stmt = $pdo->prepare("
        SELECT captain_id
        FROM teams
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->execute([$actorTeamId]);

    return (int)$stmt->fetchColumn() === $userId;
}

function matchVetoStartIfReady(PDO $pdo, array $match, array $readySummary): bool {
    if (!$readySummary['all_ready']) {
        return false;
    }

    if (($match['veto_status'] ?? 'not_started') !== 'not_started') {
        return false;
    }

    if (!$match['team_a_id'] || !$match['team_b_id']) {
        return false;
    }

    $stmt = $pdo->prepare("
        UPDATE tournament_matches
        SET veto_status = 'active',
            veto_started_at = NOW(),
            veto_turn_started_at = NOW(),
            status = IF(status = 'pending', 'ready_check', status)
        WHERE id = ?
          AND veto_status = 'not_started'
    ");
    $stmt->execute([(int)$match['id']]);

    return $stmt->rowCount() > 0;
}

function matchVetoCompleteIfDecider(PDO $pdo, array $match): bool {
    $rows = matchVetoRows($pdo, (int)$match['id']);
    $current = matchVetoCurrentFromPlan($match, $rows);

    if (!$current || $current['action'] !== 'decider') {
        return false;
    }

    $pool = matchVetoMapPool();
    $used = matchVetoUsedMaps($rows);
    $available = array_values(array_diff($pool, $used));

    if (count($available) !== 1) {
        return false;
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO match_map_veto (
                match_id,
                step_number,
                action,
                actor_team_id,
                map_name,
                side_choice,
                is_auto,
                created_by
            )
            VALUES (?, ?, 'decider', NULL, ?, NULL, 1, NULL)
        ");
        $stmt->execute([
            (int)$match['id'],
            (int)$current['step_number'],
            $available[0]
        ]);
    } catch (Throwable $e) {
        /**
         * Możliwe, że inny request już dodał decider.
         */
    }

    $stmt = $pdo->prepare("
        UPDATE tournament_matches
        SET veto_status = 'completed',
            veto_completed_at = NOW(),
            veto_turn_started_at = NULL
        WHERE id = ?
          AND veto_status = 'active'
    ");
    $stmt->execute([(int)$match['id']]);

    return true;
}

function matchVetoAutoStartMatchIfCompleted(PDO $pdo, array $match): bool {
    $fresh = matchLobbyGetMatch($pdo, (int)$match['id']);

    if (!$fresh || ($fresh['veto_status'] ?? '') !== 'completed') {
        return false;
    }

    if (($fresh['status'] ?? '') === 'live') {
        return false;
    }

    try {
        return matchzyLoadMatchOnServer($pdo, $fresh);
    } catch (Throwable $e) {
        $stmt = $pdo->prepare("
            UPDATE tournament_matches
            SET matchzy_load_error = ?
            WHERE id = ?
        ");
        $stmt->execute([
            $e->getMessage(),
            (int)$fresh['id']
        ]);

        error_log('[Clutchify] MatchZy load failed for match #' . $fresh['id'] . ': ' . $e->getMessage());

        return false;
    }
}

function matchVetoAfterAction(PDO $pdo, int $matchId): array {
    $match = matchLobbyGetMatch($pdo, $matchId);

    if (!$match) {
        return [
            'completed' => false,
            'match_started' => false
        ];
    }

    $completedNow = matchVetoCompleteIfDecider($pdo, $match);

    $fresh = matchLobbyGetMatch($pdo, $matchId);

    if (!$fresh) {
        return [
            'completed' => $completedNow,
            'match_started' => false
        ];
    }

    if (($fresh['veto_status'] ?? '') === 'completed') {
        $started = matchVetoAutoStartMatchIfCompleted($pdo, $fresh);

        return [
            'completed' => true,
            'match_started' => $started
        ];
    }

    /**
     * Następny ruch zaczyna licznik od nowa.
     */
    $stmt = $pdo->prepare("
        UPDATE tournament_matches
        SET veto_turn_started_at = NOW()
        WHERE id = ?
          AND veto_status = 'active'
    ");
    $stmt->execute([$matchId]);

    return [
        'completed' => false,
        'match_started' => false
    ];
}
function matchVetoAutoResolve(PDO $pdo, array $match): array {
    $state = matchVetoState($pdo, $match, 0, true, null);
    $current = $state['current'];

    if (!$current || ($match['veto_status'] ?? '') !== 'active') {
        return [
            'resolved' => false,
            'message' => 'Brak aktywnego ruchu veto.'
        ];
    }

    if (empty($state['turn']['timed_out'])) {
        return [
            'resolved' => false,
            'message' => 'Czas jeszcze nie minął.'
        ];
    }

    $vetoAction = $current['action'];
    $actorTeamId = (int)$current['actor_team_id'];
    $mapName = '';
    $sideChoice = null;

    if (in_array($vetoAction, ['ban', 'pick'], true)) {
        $available = $state['available_maps'] ?? [];

        if (!$available) {
            return [
                'resolved' => false,
                'message' => 'Brak dostępnych map.'
            ];
        }

        $mapName = $available[array_rand($available)];
    } elseif ($vetoAction === 'side') {
        $mapName = (string)($current['map_name'] ?? '');
        $sideChoice = random_int(0, 1) === 1 ? 'ct' : 't';
    } else {
        return [
            'resolved' => false,
            'message' => 'Nieprawidłowa akcja auto veto.'
        ];
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO match_map_veto (
                match_id,
                step_number,
                action,
                actor_team_id,
                map_name,
                side_choice,
                is_auto,
                created_by
            )
            VALUES (?, ?, ?, ?, ?, ?, 1, NULL)
        ");

        $stmt->execute([
            (int)$match['id'],
            (int)$current['step_number'],
            $vetoAction,
            $actorTeamId,
            $mapName,
            $sideChoice
        ]);
    } catch (Throwable $e) {
        return [
            'resolved' => false,
            'message' => 'Ten ruch został już rozwiązany.'
        ];
    }

    $after = matchVetoAfterAction($pdo, (int)$match['id']);

    return [
        'resolved' => true,
        'message' => 'System wykonał automatyczny ruch veto.',
        'veto_completed' => $after['completed'],
        'match_started' => $after['match_started']
    ];
}

function matchLobbyBuildResponse(PDO $pdo, array $match, int $viewerId, bool $isAdmin, ?int $viewerTeamId): array {
    $teamAPlayers = matchLobbyTeamPlayers($pdo, $match['team_a_id'], (int)$match['id']);
    $teamBPlayers = matchLobbyTeamPlayers($pdo, $match['team_b_id'], (int)$match['id']);
    $readySummary = matchLobbyReadySummary($teamAPlayers, $teamBPlayers);
    $vetoState = matchVetoState($pdo, $match, $viewerId, $isAdmin, $viewerTeamId);

    $viewerReady = false;

    foreach (array_merge($teamAPlayers, $teamBPlayers) as $player) {
        if ((int)$player['id'] === $viewerId) {
            $viewerReady = (bool)$player['is_ready'];
            break;
        }
    }

    $canReady = $viewerTeamId !== null
        && in_array($viewerTeamId, [$match['team_a_id'], $match['team_b_id']], true)
        && in_array($match['status'], ['pending', 'ready_check'], true);

    $match['status_label'] = matchLobbyStatusLabel((string)$match['status']);

    return [
        'match' => $match,
        'teams' => [
            'a' => [
                'id' => $match['team_a_id'],
                'name' => $match['team_a_name'],
                'tag' => $match['team_a_tag'],
                'logo' => $match['team_a_logo'],
                'players' => $teamAPlayers
            ],
            'b' => [
                'id' => $match['team_b_id'],
                'name' => $match['team_b_name'],
                'tag' => $match['team_b_tag'],
                'logo' => $match['team_b_logo'],
                'players' => $teamBPlayers
            ]
        ],
        'ready_summary' => $readySummary,
        'veto' => $vetoState,
        'viewer' => [
            'id' => $viewerId,
            'team_id' => $viewerTeamId,
            'is_admin' => $isAdmin,
            'can_ready' => $canReady,
            'is_ready' => $viewerReady
        ]
    ];
}

if ($action === 'get_my_matches') {
    $userId = requireUserId();
    $teamId = matchLobbyGetUserTeamId($pdo, $userId);

    if (!$teamId) {
        jsonSuccess([
            'team_id' => null,
            'matches' => []
        ]);
    }

    $stmt = $pdo->prepare("
        SELECT
            tm.id,
            tm.tournament_id,
            tm.round_number,
            tm.match_number,
            tm.team_a_id,
            tm.team_b_id,
            tm.status,
            tm.started_at,
            tm.finished_at,
            tm.match_source,
            tm.match_format,
            tm.game_server_id,

            t.title AS tournament_title,
            t.status AS tournament_status,

            ta.name AS team_a_name,
            ta.tag AS team_a_tag,
            ta.logo AS team_a_logo,

            tb.name AS team_b_name,
            tb.tag AS team_b_tag,
            tb.logo AS team_b_logo
        FROM tournament_matches tm
        JOIN tournaments t ON t.id = tm.tournament_id
        LEFT JOIN teams ta ON ta.id = tm.team_a_id
        LEFT JOIN teams tb ON tb.id = tm.team_b_id
        WHERE (tm.team_a_id = ? OR tm.team_b_id = ?)
          AND (
                t.status IN ('in_progress', 'finished')
                OR tm.match_source = 'scrim'
            )
          AND tm.status IN ('pending', 'ready_check', 'live', 'finished')
        ORDER BY
            FIELD(tm.status, 'live', 'ready_check', 'pending', 'finished'),
            tm.round_number ASC,
            tm.match_number ASC
        LIMIT 30
    ");
    $stmt->execute([$teamId, $teamId]);

    $matches = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($matches as &$match) {
        $match['id'] = (int)$match['id'];
        $match['tournament_id'] = (int)$match['tournament_id'];
        $match['round_number'] = (int)$match['round_number'];
        $match['match_number'] = (int)$match['match_number'];
        $match['team_a_id'] = $match['team_a_id'] !== null ? (int)$match['team_a_id'] : null;
        $match['team_b_id'] = $match['team_b_id'] !== null ? (int)$match['team_b_id'] : null;
        $match['status_label'] = matchLobbyStatusLabel((string)$match['status']);
        $match['match_source'] = $match['match_source'] ?? 'tournament';
        $match['match_format'] = $match['match_format'] ?? 'bo1';
        $match['game_server_id'] = $match['game_server_id'] !== null ? (int)$match['game_server_id'] : null;

        $teamAPlayers = matchLobbyTeamPlayers($pdo, $match['team_a_id'], (int)$match['id']);
        $teamBPlayers = matchLobbyTeamPlayers($pdo, $match['team_b_id'], (int)$match['id']);
        $match['ready_summary'] = matchLobbyReadySummary($teamAPlayers, $teamBPlayers);
    }

    jsonSuccess([
        'team_id' => $teamId,
        'matches' => $matches
    ]);
}

if ($action === 'get_match_lobby') {
    $userId = requireUserId();
    $matchId = (int)($_GET['id'] ?? 0);

    if (!$matchId) {
        jsonError('Brak ID meczu.');
    }

    $match = matchLobbyGetMatch($pdo, $matchId);

    if (!$match) {
        jsonError('Mecz nie istnieje.', 404);
    }

    $isAdmin = matchLobbyIsAdmin($pdo, $userId);
    $viewerTeamId = matchLobbyGetUserTeamId($pdo, $userId);

    if (!matchLobbyCanView($match, $isAdmin, $viewerTeamId)) {
        jsonError('Nie masz dostępu do tego lobby.', 403);
    }

    jsonSuccess(matchLobbyBuildResponse($pdo, $match, $userId, $isAdmin, $viewerTeamId));
}

if ($action === 'set_player_ready') {
    $userId = requireUserId();
    $input = getJsonInput();

    $matchId = (int)($input['match_id'] ?? 0);
    $isReady = !empty($input['is_ready']) ? 1 : 0;

    if (!$matchId) {
        jsonError('Brak ID meczu.');
    }

    $match = matchLobbyGetMatch($pdo, $matchId);

    if (!$match) {
        jsonError('Mecz nie istnieje.', 404);
    }

    if (!in_array($match['status'], ['pending', 'ready_check'], true)) {
        jsonError('Nie można zmienić gotowości dla tego meczu.');
    }

    $viewerTeamId = matchLobbyGetUserTeamId($pdo, $userId);

    if (!$viewerTeamId || !in_array($viewerTeamId, [$match['team_a_id'], $match['team_b_id']], true)) {
        jsonError('Nie grasz w tym meczu.', 403);
    }

    $stmt = $pdo->prepare("
        INSERT INTO match_ready_checks (match_id, user_id, team_id, is_ready, ready_at)
        VALUES (?, ?, ?, ?, IF(? = 1, NOW(), NULL))
        ON DUPLICATE KEY UPDATE
            team_id = VALUES(team_id),
            is_ready = VALUES(is_ready),
            ready_at = IF(VALUES(is_ready) = 1, NOW(), NULL)
    ");
    $stmt->execute([$matchId, $userId, $viewerTeamId, $isReady, $isReady]);

    $teamAPlayers = matchLobbyTeamPlayers($pdo, $match['team_a_id'], $matchId);
    $teamBPlayers = matchLobbyTeamPlayers($pdo, $match['team_b_id'], $matchId);
    $readySummary = matchLobbyReadySummary($teamAPlayers, $teamBPlayers);
    $vetoStarted = matchVetoStartIfReady($pdo, $match, $readySummary);
    $freshMatch = matchLobbyGetMatch($pdo, (int)$match['id']);

    $newStatus = $readySummary['total_ready'] > 0 ? 'ready_check' : 'pending';

    $stmt = $pdo->prepare("
        UPDATE tournament_matches
        SET status = ?
        WHERE id = ?
          AND status IN ('pending', 'ready_check')
    ");
    $stmt->execute([$newStatus, $matchId]);

    $freshMatch = matchLobbyGetMatch($pdo, $matchId);

    jsonSuccess([
        'message' => $isReady ? 'Oznaczono gotowość.' : 'Cofnięto gotowość.',
        'ready_summary' => $readySummary,
        'veto_started' => $vetoStarted,
        'target_ids' => $freshMatch ? matchLobbyTargetUserIds($pdo, $freshMatch) : [],
    ]);
}

if ($action === 'reset_ready_check') {
    $userId = requireUserId();

    if (!matchLobbyIsAdmin($pdo, $userId)) {
        jsonError('Brak uprawnień administratora.', 403);
    }

    $input = getJsonInput();
    $matchId = (int)($input['match_id'] ?? 0);

    if (!$matchId) {
        jsonError('Brak ID meczu.');
    }

    $match = matchLobbyGetMatch($pdo, $matchId);

    if (!$match) {
        jsonError('Mecz nie istnieje.', 404);
    }

    if (!in_array($match['status'], ['pending', 'ready_check'], true)) {
        jsonError('Ready check można zresetować tylko przed startem meczu.');
    }

    $stmt = $pdo->prepare("DELETE FROM match_ready_checks WHERE match_id = ?");
    $stmt->execute([$matchId]);

    $stmt = $pdo->prepare("UPDATE tournament_matches SET status = 'pending' WHERE id = ?");
    $stmt->execute([$matchId]);

    jsonSuccess([
        'message' => 'Ready check został zresetowany.',
        'target_ids' => matchLobbyTargetUserIds($pdo, $match)
    ]);
}

if ($action === 'start_match') {
    $userId = requireUserId();

    if (!matchLobbyIsAdmin($pdo, $userId)) {
        jsonError('Brak uprawnień administratora.', 403);
    }

    $input = getJsonInput();
    $matchId = (int)($input['match_id'] ?? 0);

    if (!$matchId) {
        jsonError('Brak ID meczu.');
    }

    $match = matchLobbyGetMatch($pdo, $matchId);

    if (!$match) {
        jsonError('Mecz nie istnieje.', 404);
    }

    if (!$match['team_a_id'] || !$match['team_b_id']) {
        jsonError('Nie można wystartować meczu z TBD.');
    }

    if (!in_array($match['status'], ['pending', 'ready_check'], true)) {
        jsonError('Ten mecz nie może zostać wystartowany.');
    }

    $teamAPlayers = matchLobbyTeamPlayers($pdo, $match['team_a_id'], $matchId);
    $teamBPlayers = matchLobbyTeamPlayers($pdo, $match['team_b_id'], $matchId);
    $readySummary = matchLobbyReadySummary($teamAPlayers, $teamBPlayers);

    if (!$readySummary['all_ready']) {
        jsonError('Nie wszyscy wymagani gracze są gotowi.');
    }

    $vetoState = matchVetoState($pdo, $match, $userId, true, null);

    if (!$vetoState['completed']) {
        jsonError('Najpierw zakończ veto map.');
    }

    $stmt = $pdo->prepare("
        UPDATE tournament_matches
        SET status = 'live', started_at = COALESCE(started_at, NOW())
        WHERE id = ?
    ");
    $stmt->execute([$matchId]);

    logActivity(
        $pdo,
        'match_started',
        'Mecz wystartował',
        'Wystartował mecz ' . ($match['team_a_name'] ?? 'Team A') . ' vs ' . ($match['team_b_name'] ?? 'Team B') . ' w turnieju ' . $match['tournament_title'] . '.',
        $userId,
        'match',
        $matchId,
        [
            'tournament_id' => $match['tournament_id'],
            'tournament_title' => $match['tournament_title'],
            'team_a_id' => $match['team_a_id'],
            'team_a_name' => $match['team_a_name'],
            'team_b_id' => $match['team_b_id'],
            'team_b_name' => $match['team_b_name']
        ],
        'public'
    );

    jsonSuccess([
        'message' => 'Mecz został wystartowany.',
        'target_ids' => matchLobbyTargetUserIds($pdo, $match)
    ]);
}
if ($action === 'submit_map_veto') {
    $userId = requireUserId();
    $input = getJsonInput();

    $matchId = (int)($input['match_id'] ?? 0);
    $vetoAction = (string)($input['veto_action'] ?? '');
    $mapName = trim((string)($input['map_name'] ?? ''));
    $sideChoice = strtolower(trim((string)($input['side_choice'] ?? '')));

    if (!$matchId) {
        jsonError('Brak ID meczu.');
    }

    $match = matchLobbyGetMatch($pdo, $matchId);

    if (!$match) {
        jsonError('Mecz nie istnieje.', 404);
    }

    if (($match['veto_status'] ?? 'not_started') !== 'active') {
        jsonError('Veto nie jest aktualnie aktywne.');
    }

    $isAdmin = matchLobbyIsAdmin($pdo, $userId);
    $viewerTeamId = matchLobbyGetUserTeamId($pdo, $userId);

    if (!matchLobbyCanView($match, $isAdmin, $viewerTeamId)) {
        jsonError('Nie masz dostępu do tego lobby.', 403);
    }

    $state = matchVetoState($pdo, $match, $userId, $isAdmin, $viewerTeamId);
    $current = $state['current'];

    if (!$current) {
        jsonError('Brak aktywnego ruchu veto.');
    }

    /**
     * Gdy gracz kliknie po czasie, nie przyjmujemy spóźnionej akcji.
     */
    if (!empty($state['turn']['timed_out'])) {
        jsonError('Czas na ten ruch minął. System zaraz wybierze automatycznie.');
    }

    if ($vetoAction !== $current['action']) {
        jsonError('To nie jest aktualna akcja veto.');
    }

    $actorTeamId = (int)$current['actor_team_id'];

    if (!matchVetoUserCanAct($pdo, $match, $userId, $isAdmin, $viewerTeamId, $actorTeamId)) {
        jsonError('Teraz ruch ma ' . $current['actor_label'] . '.', 403);
    }

    if (in_array($vetoAction, ['ban', 'pick'], true)) {
        if (!in_array($mapName, $state['available_maps'], true)) {
            jsonError('Ta mapa nie jest dostępna.');
        }

        $sideChoice = null;
    } elseif ($vetoAction === 'side') {
        if (!in_array($sideChoice, ['ct', 't'], true)) {
            jsonError('Wybierz stronę CT albo T.');
        }

        $mapName = (string)($current['map_name'] ?? '');

        if ($mapName === '') {
            jsonError('Nie udało się ustalić mapy dla wyboru strony.');
        }
    } else {
        jsonError('Nieprawidłowa akcja veto.');
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO match_map_veto (
                match_id,
                step_number,
                action,
                actor_team_id,
                map_name,
                side_choice,
                is_auto,
                created_by
            )
            VALUES (?, ?, ?, ?, ?, ?, 0, ?)
        ");

        $stmt->execute([
            $matchId,
            (int)$current['step_number'],
            $vetoAction,
            $actorTeamId,
            $mapName,
            $sideChoice,
            $userId
        ]);
    } catch (Throwable $e) {
        jsonError('Nie udało się zapisać akcji veto. Odśwież lobby.');
    }

    $after = matchVetoAfterAction($pdo, $matchId);
    $freshMatch = matchLobbyGetMatch($pdo, $matchId);

    jsonSuccess([
        'message' => match ($vetoAction) {
            'ban' => 'Mapa została zbanowana.',
            'pick' => 'Mapa została wybrana.',
            'side' => 'Strona została wybrana.',
            default => 'Akcja veto zapisana.'
        },
        'veto_completed' => $after['completed'],
        'match_started' => $after['match_started'],
        'target_ids' => $freshMatch ? matchLobbyTargetUserIds($pdo, $freshMatch) : []
    ]);
}
if ($action === 'reset_map_veto') {
    $userId = requireAdminUserId($pdo);
    $input = getJsonInput();

    $matchId = (int)($input['match_id'] ?? 0);

    if (!$matchId) {
        jsonError('Brak ID meczu.');
    }

    $match = matchLobbyGetMatch($pdo, $matchId);

    if (!$match) {
        jsonError('Mecz nie istnieje.', 404);
    }

    if (!in_array($match['status'], ['pending', 'ready_check'], true)) {
        jsonError('Veto można zresetować tylko przed startem meczu.');
    }

    $stmt = $pdo->prepare("DELETE FROM match_map_veto WHERE match_id = ?");
    $stmt->execute([$matchId]);

    jsonSuccess([
        'message' => 'Veto map zostało zresetowane.',
        'target_ids' => matchLobbyTargetUserIds($pdo, $match)
    ]);
}
if ($action === 'set_match_veto_format') {
    $userId = requireAdminUserId($pdo);
    $input = getJsonInput();

    $matchId = (int)($input['match_id'] ?? 0);
    $format = matchVetoNormalizeFormat((string)($input['format'] ?? 'bo1'));

    if (!$matchId) {
        jsonError('Brak ID meczu.');
    }

    $match = matchLobbyGetMatch($pdo, $matchId);

    if (!$match) {
        jsonError('Mecz nie istnieje.', 404);
    }

    if (!in_array($match['status'], ['pending', 'ready_check'], true)) {
        jsonError('Format można zmienić tylko przed startem meczu.');
    }

    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare("UPDATE tournament_matches SET match_format = ? WHERE id = ?");
        $stmt->execute([$format, $matchId]);

        $stmt = $pdo->prepare("DELETE FROM match_map_veto WHERE match_id = ?");
        $stmt->execute([$matchId]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        jsonError('Nie udało się zmienić formatu veto.');
    }

    $freshMatch = matchLobbyGetMatch($pdo, $matchId);

    jsonSuccess([
        'message' => 'Format meczu został zmieniony. Veto zostało zresetowane.',
        'target_ids' => $freshMatch ? matchLobbyTargetUserIds($pdo, $freshMatch) : []
    ]);
}
if ($action === 'auto_resolve_map_veto') {
    requireUserId();

    $input = getJsonInput();
    $matchId = (int)($input['match_id'] ?? 0);

    if (!$matchId) {
        jsonError('Brak ID meczu.');
    }

    $match = matchLobbyGetMatch($pdo, $matchId);

    if (!$match) {
        jsonError('Mecz nie istnieje.', 404);
    }

    $result = matchVetoAutoResolve($pdo, $match);
    $freshMatch = matchLobbyGetMatch($pdo, $matchId);

    jsonSuccess([
        'message' => $result['message'],
        'resolved' => $result['resolved'],
        'veto_completed' => $result['veto_completed'] ?? false,
        'match_started' => $result['match_started'] ?? false,
        'target_ids' => $freshMatch ? matchLobbyTargetUserIds($pdo, $freshMatch) : []
    ]);
}