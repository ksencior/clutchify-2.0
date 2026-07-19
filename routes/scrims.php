<?php

function scrimUserTeam(PDO $pdo, int $userId): ?array {
    $stmt = $pdo->prepare("
        SELECT
            p.team_id,
            p.isAdmin,
            t.name AS team_name,
            t.tag AS team_tag,
            t.logo AS team_logo,
            t.captain_id
        FROM players p
        LEFT JOIN teams t ON t.id = p.team_id
        WHERE p.user_id = ?
        LIMIT 1
    ");
    $stmt->execute([$userId]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row || !$row['team_id']) {
        return null;
    }

    $row['team_id'] = (int)$row['team_id'];
    $row['captain_id'] = (int)$row['captain_id'];
    $row['is_captain'] = (int)$userId === (int)$row['captain_id'];
    $row['is_admin'] = (bool)$row['isAdmin'];

    return $row;
}

function scrimRequireCaptain(PDO $pdo, int $userId): array {
    $team = scrimUserTeam($pdo, $userId);

    if (!$team) {
        jsonError('Musisz należeć do drużyny.', 403);
    }

    if (empty($team['is_captain']) && empty($team['is_admin'])) {
        jsonError('Tylko lider drużyny może zarządzać scrimami.', 403);
    }

    return $team;
}

function scrimFormat(string $format): string {
    return in_array($format, ['bo1', 'bo3', 'bo5'], true) ? $format : 'bo1';
}

function scrimSettingsFromInput(array $input): array {
    $mr = (int)($input['mr'] ?? 12);

    if (!in_array($mr, [12, 15], true)) {
        $mr = 12;
    }

    return [
        'match_format' => scrimFormat((string)($input['match_format'] ?? 'bo1')),
        'friendly_fire' => !empty($input['friendly_fire']) ? 1 : 0,
        'overtime_enabled' => !empty($input['overtime_enabled']) ? 1 : 0,
        'knife_round' => !empty($input['knife_round']) ? 1 : 0,
        'mr' => $mr
    ];
}

function scrimSettingsJson(array $settings): string {
    return json_encode([
        'friendly_fire' => (bool)$settings['friendly_fire'],
        'overtime_enabled' => (bool)$settings['overtime_enabled'],
        'knife_round' => (bool)$settings['knife_round'],
        'mr' => (int)$settings['mr']
    ], JSON_UNESCAPED_UNICODE);
}

function scrimTeamHasActiveMatch(PDO $pdo, int $teamId): bool {
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM tournament_matches
        WHERE status IN ('pending', 'ready_check', 'live')
          AND (team_a_id = ? OR team_b_id = ?)
    ");
    $stmt->execute([$teamId, $teamId]);

    return (int)$stmt->fetchColumn() > 0;
}

function scrimFindFreeGameServer(PDO $pdo): ?array {
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

    return $server;
}

function scrimSystemTournamentId(PDO $pdo): int {
    $stmt = $pdo->prepare("
        SELECT id
        FROM tournaments
        WHERE join_code = 'SCRIMS'
        LIMIT 1
    ");
    $stmt->execute();

    $existing = $stmt->fetchColumn();

    if ($existing) {
        return (int)$existing;
    }

    $stmt = $pdo->prepare("
        INSERT INTO tournaments (
            join_code,
            is_open,
            status,
            creator,
            title
        )
        VALUES ('SCRIMS', 0, 'in_progress', 'system', 'Clutchify Scrims')
    ");
    $stmt->execute();

    return (int)$pdo->lastInsertId();
}

function scrimGetPost(PDO $pdo, int $postId): ?array {
    $stmt = $pdo->prepare("
        SELECT
            sp.*,

            t.name AS team_name,
            t.tag AS team_tag,
            t.logo AS team_logo,
            t.captain_id,

            u.username AS captain_username
        FROM scrim_posts sp
        JOIN teams t ON t.id = sp.team_id
        JOIN users u ON u.id = t.captain_id
        WHERE sp.id = ?
        LIMIT 1
    ");
    $stmt->execute([$postId]);

    $post = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$post) {
        return null;
    }

    $post['id'] = (int)$post['id'];
    $post['team_id'] = (int)$post['team_id'];
    $post['captain_id'] = (int)$post['captain_id'];
    $post['friendly_fire'] = (bool)$post['friendly_fire'];
    $post['overtime_enabled'] = (bool)$post['overtime_enabled'];
    $post['knife_round'] = (bool)$post['knife_round'];
    $post['mr'] = (int)$post['mr'];

    return $post;
}

function scrimOfferTargetIds(PDO $pdo, int $teamA, int $teamB): array {
    $stmt = $pdo->prepare("
        SELECT user_id
        FROM players
        WHERE team_id IN (?, ?)
    ");
    $stmt->execute([$teamA, $teamB]);

    return array_values(array_unique(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN))));
}

function scrimFormatPost(array $post): array {
    return [
        'id' => (int)$post['id'],
        'team_id' => (int)$post['team_id'],
        'team_name' => $post['team_name'],
        'team_tag' => $post['team_tag'],
        'team_logo' => $post['team_logo'],
        'title' => $post['title'],
        'description' => $post['description'],
        'status' => $post['status'],
        'match_format' => $post['match_format'],
        'friendly_fire' => (bool)$post['friendly_fire'],
        'overtime_enabled' => (bool)$post['overtime_enabled'],
        'knife_round' => (bool)$post['knife_round'],
        'mr' => (int)$post['mr'],
        'scheduled_for' => $post['scheduled_for'],
        'created_at' => $post['created_at']
    ];
}

if ($action === 'get_scrim_center') {
    $userId = requireUserId();
    $team = scrimUserTeam($pdo, $userId);

    if (!$team) {
        jsonSuccess([
            'has_team' => false,
            'my_team' => null,
            'open_posts' => [],
            'incoming_offers' => [],
            'outgoing_offers' => []
        ]);
    }

    $teamId = (int)$team['team_id'];

    $stmt = $pdo->prepare("
        SELECT
            sp.*,
            t.name AS team_name,
            t.tag AS team_tag,
            t.logo AS team_logo
        FROM scrim_posts sp
        JOIN teams t ON t.id = sp.team_id
        WHERE sp.status = 'active'
          AND sp.team_id != ?
        ORDER BY sp.created_at DESC
        LIMIT 30
    ");
    $stmt->execute([$teamId]);

    $openPosts = array_map('scrimFormatPost', $stmt->fetchAll(PDO::FETCH_ASSOC));

    $stmt = $pdo->prepare("
        SELECT
            sp.*,
            t.name AS team_name,
            t.tag AS team_tag,
            t.logo AS team_logo
        FROM scrim_posts sp
        JOIN teams t ON t.id = sp.team_id
        WHERE sp.team_id = ?
          AND sp.status = 'active'
        ORDER BY sp.id DESC
        LIMIT 1
    ");
    $stmt->execute([$teamId]);

    $myPostRow = $stmt->fetch(PDO::FETCH_ASSOC);
    $myPost = $myPostRow ? scrimFormatPost($myPostRow) : null;

    $stmt = $pdo->prepare("
        SELECT
            so.*,
            challenger.name AS challenger_name,
            challenger.tag AS challenger_tag,
            challenger.logo AS challenger_logo,
            sp.title AS post_title,
            sp.team_id AS owner_team_id
        FROM scrim_offers so
        JOIN scrim_posts sp ON sp.id = so.scrim_post_id
        JOIN teams challenger ON challenger.id = so.challenger_team_id
        WHERE sp.team_id = ?
          AND so.status = 'pending'
        ORDER BY so.created_at ASC
    ");
    $stmt->execute([$teamId]);
    $incomingOffers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($incomingOffers as &$offer) {
        $offer['id'] = (int)$offer['id'];
        $offer['scrim_post_id'] = (int)$offer['scrim_post_id'];
        $offer['challenger_team_id'] = (int)$offer['challenger_team_id'];
    }

    $stmt = $pdo->prepare("
        SELECT
            so.*,
            owner.name AS owner_name,
            owner.tag AS owner_tag,
            owner.logo AS owner_logo,
            sp.title AS post_title,
            sp.match_format,
            sp.friendly_fire,
            sp.overtime_enabled,
            sp.knife_round,
            sp.mr
        FROM scrim_offers so
        JOIN scrim_posts sp ON sp.id = so.scrim_post_id
        JOIN teams owner ON owner.id = sp.team_id
        WHERE so.challenger_team_id = ?
          AND so.status IN ('pending', 'accepted')
        ORDER BY so.created_at DESC
        LIMIT 20
    ");
    $stmt->execute([$teamId]);
    $outgoingOffers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($outgoingOffers as &$offer) {
        $offer['id'] = (int)$offer['id'];
        $offer['scrim_post_id'] = (int)$offer['scrim_post_id'];
        $offer['match_id'] = $offer['match_id'] !== null ? (int)$offer['match_id'] : null;
    }

    jsonSuccess([
        'has_team' => true,
        'my_team' => [
            'id' => $teamId,
            'name' => $team['team_name'],
            'tag' => $team['team_tag'],
            'is_captain' => (bool)$team['is_captain'],
            'is_admin' => (bool)$team['is_admin']
        ],
        'my_post' => $myPost,
        'open_posts' => $openPosts,
        'incoming_offers' => $incomingOffers,
        'outgoing_offers' => $outgoingOffers
    ]);
}

if ($action === 'create_scrim_post') {
    $userId = requireUserId();
    $team = scrimRequireCaptain($pdo, $userId);

    $input = getJsonInput();
    $settings = scrimSettingsFromInput($input);

    $title = trim((string)($input['title'] ?? ''));
    $description = trim((string)($input['description'] ?? ''));

    if (mb_strlen($title) < 3 || mb_strlen($title) > 140) {
        jsonError('Tytuł scrima musi mieć od 3 do 140 znaków.');
    }

    if (scrimTeamHasActiveMatch($pdo, (int)$team['team_id'])) {
        jsonError('Twoja drużyna ma już aktywny mecz lub scrim.');
    }

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM scrim_posts
        WHERE team_id = ?
          AND status = 'active'
    ");
    $stmt->execute([(int)$team['team_id']]);

    if ((int)$stmt->fetchColumn() > 0) {
        jsonError('Twoja drużyna ma już aktywne ogłoszenie scrima.');
    }

    $stmt = $pdo->prepare("
        INSERT INTO scrim_posts (
            team_id,
            created_by,
            title,
            description,
            match_format,
            friendly_fire,
            overtime_enabled,
            knife_round,
            mr
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->execute([
        (int)$team['team_id'],
        $userId,
        $title,
        $description !== '' ? $description : null,
        $settings['match_format'],
        $settings['friendly_fire'],
        $settings['overtime_enabled'],
        $settings['knife_round'],
        $settings['mr']
    ]);

    logActivity(
        $pdo,
        'scrim_post_created',
        'Nowy scrim',
        '[' . $team['team_tag'] . '] ' . $team['team_name'] . ' szuka przeciwnika na scrim.',
        $userId,
        'team',
        (int)$team['team_id'],
        [
            'team_tag' => $team['team_tag'],
            'team_name' => $team['team_name'],
            'format' => $settings['match_format']
        ],
        'public'
    );

    jsonSuccess([
        'message' => 'Ogłoszenie scrima zostało utworzone.'
    ]);
}

if ($action === 'close_scrim_post') {
    $userId = requireUserId();
    $team = scrimRequireCaptain($pdo, $userId);

    $input = getJsonInput();
    $postId = (int)($input['post_id'] ?? 0);

    if (!$postId) {
        jsonError('Brak ID ogłoszenia.');
    }

    $stmt = $pdo->prepare("
        UPDATE scrim_posts
        SET status = 'closed'
        WHERE id = ?
          AND team_id = ?
          AND status = 'active'
    ");
    $stmt->execute([$postId, (int)$team['team_id']]);

    jsonSuccess([
        'message' => 'Ogłoszenie scrima zostało zamknięte.'
    ]);
}

if ($action === 'send_scrim_offer') {
    $userId = requireUserId();
    $team = scrimRequireCaptain($pdo, $userId);

    $input = getJsonInput();
    $postId = (int)($input['post_id'] ?? 0);
    $message = trim((string)($input['message'] ?? ''));

    if (!$postId) {
        jsonError('Brak ID ogłoszenia.');
    }

    $post = scrimGetPost($pdo, $postId);

    if (!$post || $post['status'] !== 'active') {
        jsonError('To ogłoszenie nie jest już aktywne.');
    }

    if ((int)$post['team_id'] === (int)$team['team_id']) {
        jsonError('Nie możesz złożyć oferty na własny scrim.');
    }

    if (scrimTeamHasActiveMatch($pdo, (int)$team['team_id'])) {
        jsonError('Twoja drużyna ma już aktywny mecz lub scrim.');
    }

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM scrim_offers
        WHERE scrim_post_id = ?
          AND challenger_team_id = ?
          AND status = 'pending'
    ");
    $stmt->execute([$postId, (int)$team['team_id']]);

    if ((int)$stmt->fetchColumn() > 0) {
        jsonError('Twoja drużyna już wysłała ofertę do tego scrima.');
    }

    $stmt = $pdo->prepare("
        INSERT INTO scrim_offers (
            scrim_post_id,
            challenger_team_id,
            created_by,
            message
        )
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([
        $postId,
        (int)$team['team_id'],
        $userId,
        $message !== '' ? $message : null
    ]);

    $offerId = (int)$pdo->lastInsertId();

    $safeChallenger = htmlspecialchars('[' . $team['team_tag'] . '] ' . $team['team_name'], ENT_QUOTES, 'UTF-8');

    $stmt = $pdo->prepare("
        INSERT INTO notifications (user_id, type, reference_id, message)
        VALUES (?, 'system', ?, ?)
    ");
    $stmt->execute([
        (int)$post['captain_id'],
        $offerId,
        "<strong>{$safeChallenger}</strong> złożyło ofertę gry na Twój scrim."
    ]);

    jsonSuccess([
        'message' => 'Oferta scrima została wysłana.',
        'target_ids' => [(int)$post['captain_id']]
    ]);
}

if ($action === 'respond_scrim_offer') {
    $userId = requireUserId();
    $team = scrimRequireCaptain($pdo, $userId);

    $input = getJsonInput();
    $offerId = (int)($input['offer_id'] ?? 0);
    $decision = (string)($input['decision'] ?? '');

    if (!$offerId || !in_array($decision, ['accept', 'reject'], true)) {
        jsonError('Nieprawidłowa decyzja.');
    }

    $stmt = $pdo->prepare("
        SELECT
            so.*,

            sp.team_id AS owner_team_id,
            sp.title,
            sp.status AS post_status,
            sp.match_format,
            sp.friendly_fire,
            sp.overtime_enabled,
            sp.knife_round,
            sp.mr,

            owner.name AS owner_name,
            owner.tag AS owner_tag,

            challenger.name AS challenger_name,
            challenger.tag AS challenger_tag,
            challenger.captain_id AS challenger_captain_id
        FROM scrim_offers so
        JOIN scrim_posts sp ON sp.id = so.scrim_post_id
        JOIN teams owner ON owner.id = sp.team_id
        JOIN teams challenger ON challenger.id = so.challenger_team_id
        WHERE so.id = ?
        LIMIT 1
    ");
    $stmt->execute([$offerId]);
    $offer = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$offer) {
        jsonError('Oferta nie istnieje.', 404);
    }

    if ((int)$offer['owner_team_id'] !== (int)$team['team_id']) {
        jsonError('Nie możesz zarządzać ofertą dla obcej drużyny.', 403);
    }

    if ($offer['status'] !== 'pending' || $offer['post_status'] !== 'active') {
        jsonError('Ta oferta nie jest już aktywna.');
    }

    if ($decision === 'reject') {
        $stmt = $pdo->prepare("
            UPDATE scrim_offers
            SET status = 'rejected',
                reviewed_by = ?,
                reviewed_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$userId, $offerId]);

        jsonSuccess([
            'message' => 'Oferta została odrzucona.',
            'target_ids' => [(int)$offer['challenger_captain_id']]
        ]);
    }

    if (scrimTeamHasActiveMatch($pdo, (int)$offer['owner_team_id'])) {
        jsonError('Twoja drużyna ma już aktywny mecz lub scrim.');
    }

    if (scrimTeamHasActiveMatch($pdo, (int)$offer['challenger_team_id'])) {
        jsonError('Drużyna przeciwnika ma już aktywny mecz lub scrim.');
    }

    $server = scrimFindFreeGameServer($pdo);

    if (!$server) {
        jsonError('Brak wolnych serwerów match/both.');
    }

    $settings = [
        'match_format' => scrimFormat((string)$offer['match_format']),
        'friendly_fire' => (int)$offer['friendly_fire'],
        'overtime_enabled' => (int)$offer['overtime_enabled'],
        'knife_round' => (int)$offer['knife_round'],
        'mr' => (int)$offer['mr']
    ];

    try {
        $pdo->beginTransaction();

        $scrimTournamentId = scrimSystemTournamentId($pdo);

        $stmt = $pdo->prepare("
            INSERT INTO tournament_matches (
                tournament_id,
                match_source,
                scrim_post_id,
                scrim_offer_id,
                game_server_id,
                server_assigned_at,
                round_number,
                match_number,
                match_format,
                match_settings_json,
                team_a_id,
                team_b_id,
                status
            )
            VALUES (?, 'scrim', ?, ?, ?, NOW(), 1, 1, ?, ?, ?, ?, 'pending')
        ");

        $stmt->execute([
            $scrimTournamentId,
            (int)$offer['scrim_post_id'],
            $offerId,
            (int)$server['id'],
            $settings['match_format'],
            scrimSettingsJson($settings),
            (int)$offer['owner_team_id'],
            (int)$offer['challenger_team_id']
        ]);

        $matchId = (int)$pdo->lastInsertId();

        $stmt = $pdo->prepare("
            UPDATE scrim_offers
            SET status = 'accepted',
                match_id = ?,
                reviewed_by = ?,
                reviewed_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$matchId, $userId, $offerId]);

        $stmt = $pdo->prepare("
            UPDATE scrim_offers
            SET status = 'rejected',
                reviewed_by = ?,
                reviewed_at = NOW()
            WHERE scrim_post_id = ?
              AND id != ?
              AND status = 'pending'
        ");
        $stmt->execute([$userId, (int)$offer['scrim_post_id'], $offerId]);

        $stmt = $pdo->prepare("
            UPDATE scrim_posts
            SET status = 'matched'
            WHERE id = ?
        ");
        $stmt->execute([(int)$offer['scrim_post_id']]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        jsonError('Nie udało się utworzyć scrima: ' . $e->getMessage());
    }

    $targetIds = scrimOfferTargetIds($pdo, (int)$offer['owner_team_id'], (int)$offer['challenger_team_id']);

    $safeOwner = htmlspecialchars('[' . $offer['owner_tag'] . '] ' . $offer['owner_name'], ENT_QUOTES, 'UTF-8');
    $safeChallenger = htmlspecialchars('[' . $offer['challenger_tag'] . '] ' . $offer['challenger_name'], ENT_QUOTES, 'UTF-8');

    foreach ($targetIds as $targetId) {
        $stmt = $pdo->prepare("
            INSERT INTO notifications (user_id, type, reference_id, message)
            VALUES (?, 'system', ?, ?)
        ");
        $stmt->execute([
            $targetId,
            $matchId,
            "Scrim {$safeOwner} vs {$safeChallenger} został utworzony. Wejdź do lobby i daj READY."
        ]);
    }

    logActivity(
        $pdo,
        'scrim_created',
        'Scrim utworzony',
        '[' . $offer['owner_tag'] . '] ' . $offer['owner_name'] . ' zagra scrima przeciwko [' . $offer['challenger_tag'] . '] ' . $offer['challenger_name'] . '.',
        $userId,
        'match',
        $matchId,
        [
            'match_id' => $matchId,
            'team_a_id' => (int)$offer['owner_team_id'],
            'team_a_name' => $offer['owner_name'],
            'team_a_tag' => $offer['owner_tag'],
            'team_b_id' => (int)$offer['challenger_team_id'],
            'team_b_name' => $offer['challenger_name'],
            'team_b_tag' => $offer['challenger_tag'],
            'format' => $settings['match_format']
        ],
        'public'
    );

    jsonSuccess([
        'message' => 'Oferta zaakceptowana. Scrim lobby zostało utworzone.',
        'match_id' => $matchId,
        'target_ids' => $targetIds
    ]);
}