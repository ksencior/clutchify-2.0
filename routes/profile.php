<?php
if ($action === 'get_profile') {
    $viewerId = isset($_SESSION['user_id'])
        ? (int)$_SESSION['user_id']
        : null;

    $targetId = null;
    $targetUsername = null;

    if (isset($_GET['id']) && is_numeric($_GET['id'])) {
        $targetId = (int)$_GET['id'];
    }

    if (isset($_GET['username'])) {
        $targetUsername = trim((string)$_GET['username']);
    }

    if ($targetUsername !== null && $targetUsername !== '') {
        if (!preg_match('/^[a-zA-Z0-9_.-]{3,24}$/', $targetUsername)) {
            jsonError('Nieprawidłowy link profilu.', 400);
        }

        $whereSql = 'u.username = ?';
        $whereParam = $targetUsername;
    } elseif ($targetId !== null && $targetId > 0) {
        $whereSql = 'u.id = ?';
        $whereParam = $targetId;
    } elseif ($viewerId !== null) {
        $whereSql = 'u.id = ?';
        $whereParam = $viewerId;
    } else {
        jsonError('Nie podano profilu do wyświetlenia.', 400);
    }

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
            p.discord_id,
            p.isAdmin,

            t.name AS team_name,
            t.tag AS team_tag,
            t.logo AS team_logo
        FROM users u
        LEFT JOIN players p ON u.id = p.user_id
        LEFT JOIN teams t ON p.team_id = t.id
        WHERE {$whereSql}
        LIMIT 1
    ");

    $stmt->execute([$whereParam]);

    $userProfile = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$userProfile) {
        jsonError('Gracz nie istnieje.', 404);
    }

    $userProfile['id'] = (int)$userProfile['id'];
    $userProfile['is_me'] = $viewerId !== null && $userProfile['id'] === $viewerId;
    $userProfile['viewer_logged_in'] = $viewerId !== null;

    if ($viewerId === null) {
        $userProfile['friend_status'] = 'guest';
        $userProfile['friendship_id'] = null;
    } else {
        $friendInfo = $userProfile['is_me']
            ? ['status' => 'me', 'friendship_id' => null]
            : getFriendStatusForViewer($pdo, $viewerId, $userProfile['id']);

        $userProfile['friend_status'] = $friendInfo['status'];
        $userProfile['friendship_id'] = $friendInfo['friendship_id'];
    }

    $userProfile['preferred_role'] = $userProfile['preferred_role'] ?: 'unknown';
    $userProfile['preferred_role_label'] = profileRoleLabel($userProfile['preferred_role']);

    $userProfile['faceit_level'] = $userProfile['faceit_level'] !== null
        ? (int)$userProfile['faceit_level']
        : null;

    $userProfile['isAdmin'] = (bool)($userProfile['isAdmin'] ?? false);

    $completeness = profileCompleteness($userProfile);

    $userProfile['profile_completeness'] = $completeness;
    $userProfile['badges'] = profileBadges($userProfile, $completeness);

    jsonSuccess([
        'profile' => $userProfile
    ]);
}

if ($action === 'get_profile_settings') {
    $userId = requireUserId();

    $stmt = $pdo->prepare("
        SELECT
            u.id,
            u.username,
            u.email,

            p.avatar,
            p.bio,
            p.preferred_role,
            p.faceit_level,
            p.region,
            p.school,
            p.availability,
            p.steam_id,
            p.discord_id,
            p.isAdmin

        FROM users u
        LEFT JOIN players p ON p.user_id = u.id
        WHERE u.id = ?
        LIMIT 1
    ");

    $stmt->execute([$userId]);
    $settings = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$settings) {
        jsonError('Nie znaleziono profilu.', 404);
    }

    $settings['isAdmin'] = (bool)($settings['isAdmin'] ?? false);

    $completeness = profileCompleteness($settings);

    $settings['profile_completeness'] = $completeness;
    $settings['badges'] = profileBadges($settings, $completeness);

    jsonSuccess([
        'settings' => $settings
    ]);
}

if ($action === 'update_profile_settings') {
    $userId = requireUserId();
    $input = getJsonInput();

    $username = trim($input['username'] ?? '');
    $avatar = trim($input['avatar'] ?? '');
    $bio = trim($input['bio'] ?? '');
    $preferredRole = trim($input['preferred_role'] ?? 'unknown');
    $faceitLevel = $input['faceit_level'] ?? null;
    $region = trim($input['region'] ?? 'EU');
    $school = trim($input['school'] ?? '');
    $availability = trim($input['availability'] ?? '');

    $allowedRoles = [
        'unknown',
        'entry',
        'rifler',
        'awper',
        'igl',
        'lurker',
        'support'
    ];

    if (!isValidUsername($username)) {
        jsonError(usernameValidationMessage());
    }

    if (!in_array($preferredRole, $allowedRoles, true)) {
        jsonError('Nieprawidłowa rola.');
    }

    if (mb_strlen($bio) > 500) {
        jsonError('Bio może mieć maksymalnie 500 znaków.');
    }

    if (mb_strlen($availability) > 255) {
        jsonError('Dostępność może mieć maksymalnie 255 znaków.');
    }

    if (mb_strlen($school) > 120) {
        jsonError('Nazwa szkoły / organizacji może mieć maksymalnie 120 znaków.');
    }

    if (mb_strlen($region) > 40) {
        jsonError('Region może mieć maksymalnie 40 znaków.');
    }

    if ($avatar !== '') {
        if (!filter_var($avatar, FILTER_VALIDATE_URL)) {
            jsonError('Avatar musi być poprawnym adresem URL.');
        }

        if (!preg_match('/^https?:\/\//i', $avatar)) {
            jsonError('Avatar musi zaczynać się od http:// albo https://.');
        }

        if (mb_strlen($avatar) > 255) {
            jsonError('Link do avatara jest za długi.');
        }
    }

    if ($faceitLevel === '' || $faceitLevel === null) {
        $faceitLevel = null;
    } else {
        $faceitLevel = (int)$faceitLevel;

        if ($faceitLevel < 1 || $faceitLevel > 10) {
            jsonError('Faceit level musi być od 1 do 10.');
        }
    }

    $stmt = $pdo->prepare("
        SELECT id
        FROM users
        WHERE username = ? AND id != ?
        LIMIT 1
    ");
    $stmt->execute([$username, $userId]);

    if ($stmt->fetch()) {
        jsonError('Ten nick jest już zajęty.');
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            UPDATE users
            SET username = ?
            WHERE id = ?
        ");
        $stmt->execute([$username, $userId]);

        $stmt = $pdo->prepare("
            UPDATE players
            SET
                avatar = ?,
                bio = ?,
                preferred_role = ?,
                faceit_level = ?,
                region = ?,
                school = ?,
                availability = ?
            WHERE user_id = ?
        ");

        $stmt->execute([
            $avatar !== '' ? $avatar : null,
            $bio !== '' ? $bio : null,
            $preferredRole,
            $faceitLevel,
            $region !== '' ? $region : null,
            $school !== '' ? $school : null,
            $availability !== '' ? $availability : null,
            $userId
        ]);

        $pdo->commit();

        jsonSuccess([
            'message' => 'Profil został zaktualizowany.'
        ]);
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        jsonError('Nie udało się zaktualizować profilu.', 500);
    }
}