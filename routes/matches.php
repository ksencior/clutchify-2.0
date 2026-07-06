<?php

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

function matchLobbyBuildResponse(PDO $pdo, array $match, int $viewerId, bool $isAdmin, ?int $viewerTeamId): array {
    $teamAPlayers = matchLobbyTeamPlayers($pdo, $match['team_a_id'], (int)$match['id']);
    $teamBPlayers = matchLobbyTeamPlayers($pdo, $match['team_b_id'], (int)$match['id']);
    $readySummary = matchLobbyReadySummary($teamAPlayers, $teamBPlayers);

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
          AND t.status IN ('in_progress', 'finished')
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
        'target_ids' => $freshMatch ? matchLobbyTargetUserIds($pdo, $freshMatch) : []
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