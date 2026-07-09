<?php

function playerRoleLabel(?string $role): string {
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

if ($action === 'get_players_directory') {
    $viewerId = requireUserId();

    $search = trim($_GET['search'] ?? '');
    $role = trim($_GET['role'] ?? '');
    $region = trim($_GET['region'] ?? '');
    $faceitMin = $_GET['faceit_min'] ?? '';

    $allowedRoles = [
        '',
        'unknown',
        'entry',
        'rifler',
        'awper',
        'igl',
        'lurker',
        'support'
    ];

    if (!in_array($role, $allowedRoles, true)) {
        jsonError('Nieprawidłowy filtr roli.');
    }

    if (mb_strlen($search) > 60) {
        jsonError('Wyszukiwarka może mieć maksymalnie 60 znaków.');
    }

    if (mb_strlen($region) > 40) {
        jsonError('Region może mieć maksymalnie 40 znaków.');
    }

    $faceitMinValue = null;

    if ($faceitMin !== '') {
        $faceitMinValue = (int)$faceitMin;

        if ($faceitMinValue < 1 || $faceitMinValue > 10) {
            jsonError('Minimalny Faceit level musi być od 1 do 10.');
        }
    }

    $where = [];
    $params = [];

    if ($search !== '') {
        $where[] = "u.username LIKE ?";
        $params[] = '%' . $search . '%';
    }

    if ($role !== '') {
        $where[] = "COALESCE(p.preferred_role, 'unknown') = ?";
        $params[] = $role;
    }

    if ($region !== '') {
        $where[] = "p.region LIKE ?";
        $params[] = '%' . $region . '%';
    }

    if ($faceitMinValue !== null) {
        $where[] = "p.faceit_level >= ?";
        $params[] = $faceitMinValue;
    }

    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $stmt = $pdo->prepare("
        SELECT
            u.id,
            u.username,
            u.created_at,

            p.avatar,
            p.bio,
            p.preferred_role,
            p.faceit_level,
            p.region,
            p.school,
            p.availability,
            p.steam_id,
            p.discord_id
        FROM users u
        LEFT JOIN players p ON p.user_id = u.id
        {$whereSql}
        ORDER BY
            CASE WHEN p.avatar IS NULL OR p.avatar = '' THEN 1 ELSE 0 END ASC,
            u.created_at DESC
        LIMIT 60
    ");

    $stmt->execute($params);

    $players = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($players as &$player) {
        $player['id'] = (int)$player['id'];
        $player['faceit_level'] = $player['faceit_level'] !== null
            ? (int)$player['faceit_level']
            : null;

        $player['preferred_role'] = $player['preferred_role'] ?: 'unknown';
        $player['preferred_role_label'] = playerRoleLabel($player['preferred_role']);

        if ($player['bio'] != NULL && $player['bio'] != '') {
            if (mb_strlen($player['bio']) > 60) {
                $player['bio'] = substr($player['bio'], 0, 60) . '...';
            }
        }

        if ((int)$player['id'] === $viewerId) {
            $player['friend_status'] = 'me';
            $player['friendship_id'] = null;
            $completeness = profileCompleteness($player);

            $player['profile_completeness'] = $completeness;
            $player['badges'] = profileBadges($player, $completeness);
        } else {
            $friendInfo = getFriendStatusForViewer($pdo, $viewerId, (int)$player['id']);

            $player['friend_status'] = $friendInfo['status'];
            $player['friendship_id'] = $friendInfo['friendship_id'];
            $completeness = profileCompleteness($player);

            $player['profile_completeness'] = $completeness;
            $player['badges'] = profileBadges($player, $completeness);
        }
    }

    jsonSuccess([
        'players' => $players
    ]);
}