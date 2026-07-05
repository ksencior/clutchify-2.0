<?php

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
        'server_time' => date('Y-m-d H:i:s')
    ]);
}