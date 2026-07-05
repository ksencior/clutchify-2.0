<?php

function roleLabel(?string $role): string {
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

if ($action === 'get_profile') {
    $viewerId = requireUserId();

    $targetId = (isset($_GET['id']) && is_numeric($_GET['id']))
        ? (int)$_GET['id']
        : $viewerId;

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

            t.name AS team_name,
            t.tag AS team_tag,
            t.logo AS team_logo
        FROM users u
        LEFT JOIN players p ON u.id = p.user_id
        LEFT JOIN teams t ON p.team_id = t.id
        WHERE u.id = ?
        LIMIT 1
    ");
    $stmt->execute([$targetId]);

    $userProfile = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$userProfile) {
        jsonError('Gracz nie istnieje.', 404);
    }

    $userProfile['is_me'] = ($targetId === $viewerId);

    $friendInfo = $userProfile['is_me']
        ? ['status' => 'me', 'friendship_id' => null]
        : getFriendStatusForViewer($pdo, $viewerId, $targetId);

    $userProfile['friend_status'] = $friendInfo['status'];
    $userProfile['friendship_id'] = $friendInfo['friendship_id'];
    $userProfile['preferred_role_label'] = roleLabel($userProfile['preferred_role'] ?? null);

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
            p.discord_id
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

    if (!preg_match('/^[a-zA-Z0-9_.-]{3,24}$/', $username)) {
        jsonError('Nick może mieć 3-24 znaki i zawierać tylko litery, cyfry, _, . oraz -.');
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