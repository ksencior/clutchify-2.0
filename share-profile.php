<?php

require_once __DIR__ . '/db.php';

function e(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function absoluteUrl(?string $url, string $baseUrl): string {
    $url = trim((string)$url);

    if ($url === '') {
        return rtrim($baseUrl, '/') . '/public/img/clutchify-w-text.png';
    }

    if (preg_match('/^https?:\/\//i', $url)) {
        return $url;
    }

    if (str_starts_with($url, '//')) {
        return 'https:' . $url;
    }

    return rtrim($baseUrl, '/') . '/' . ltrim($url, '/');
}

function shareProfileRoleLabel(?string $role): string {
    return match ($role) {
        'entry' => 'Entry Fragger',
        'rifler' => 'Rifler',
        'awper' => 'AWPer',
        'igl' => 'IGL',
        'lurker' => 'Lurker',
        'support' => 'Support',
        default => 'Gracz CS'
    };
}

function appBaseUrl(): string {
    $envUrl = env('APP_URL', '');

    if ($envUrl) {
        return rtrim($envUrl, '/');
    }

    $isHttps =
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    $scheme = $isHttps ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

    return "{$scheme}://{$host}";
}

$username = trim((string)($_GET['username'] ?? ''));

if (!preg_match('/^[a-zA-Z0-9_.-]{3,24}$/', $username)) {
    http_response_code(404);
    $username = 'Clutchify';
}

$profile = null;

if ($username !== 'Clutchify') {
    $stmt = $pdo->prepare("
        SELECT
            u.id,
            u.username,
            p.avatar,
            p.bio,
            p.preferred_role,
            p.faceit_level,
            p.region,
            t.name AS team_name,
            t.tag AS team_tag,
            t.logo AS team_logo
        FROM users u
        LEFT JOIN players p ON p.user_id = u.id
        LEFT JOIN teams t ON t.id = p.team_id
        WHERE u.username = ?
        LIMIT 1
    ");
    $stmt->execute([$username]);
    $profile = $stmt->fetch(PDO::FETCH_ASSOC);
}

$baseUrl = appBaseUrl();

if (!$profile) {
    $title = 'Clutchify.gg';
    $description = 'Platforma turniejowa CS — profile graczy, drużyny, lobby meczowe i ready check.';
    $image = absoluteUrl('/public/img/clutchify-w-text.png', $baseUrl);
    $profileUrl = $baseUrl . '/';
    $displayUsername = 'Clutchify';
} else {
    $displayUsername = (string)$profile['username'];
    $profileUrl = $baseUrl . '/u/' . rawurlencode($displayUsername);

    $role = shareProfileRoleLabel($profile['preferred_role'] ?? null);

    $parts = [];

    if (!empty($profile['team_name'])) {
        $parts[] = 'Team: ' . (!empty($profile['team_tag']) ? '[' . $profile['team_tag'] . '] ' : '') . $profile['team_name'];
    }

    if (!empty($profile['faceit_level'])) {
        $parts[] = 'Faceit ' . (int)$profile['faceit_level'];
    }

    if (!empty($profile['region'])) {
        $parts[] = 'Region: ' . $profile['region'];
    }

    $fallbackDescription = $role . ($parts ? ' • ' . implode(' • ', $parts) : '');

    $bio = trim(strip_tags((string)($profile['bio'] ?? '')));
    $description = $bio !== ''
        ? mb_substr($bio, 0, 180)
        : $fallbackDescription;

    $title = $displayUsername . ' | Clutchify.gg';

    $image = absoluteUrl(
        $profile['avatar'] ?: ($profile['team_logo'] ?: '/public/img/clutchify-w-text.png'),
        $baseUrl
    );
}

?><!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <title><?= e($title) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <meta name="description" content="<?= e($description) ?>">

    <meta property="og:type" content="profile">
    <meta property="og:site_name" content="Clutchify.gg">
    <meta property="og:title" content="<?= e($title) ?>">
    <meta property="og:description" content="<?= e($description) ?>">
    <meta property="og:image" content="<?= e($image) ?>">
    <meta property="og:url" content="<?= e($profileUrl) ?>">

    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= e($title) ?>">
    <meta name="twitter:description" content="<?= e($description) ?>">
    <meta name="twitter:image" content="<?= e($image) ?>">

    <meta http-equiv="refresh" content="1;url=<?= e($profileUrl) ?>">

    <style>
        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            background: #080808;
            color: #fff;
            font-family: Inter, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }

        .card {
            width: min(440px, calc(100vw - 32px));
            border: 1px solid rgba(255,255,255,.1);
            border-radius: 24px;
            padding: 28px;
            background:
                radial-gradient(circle at top right, rgba(255,0,43,.22), transparent 35%),
                #121212;
            text-align: center;
        }

        img {
            width: 108px;
            height: 108px;
            border-radius: 24px;
            object-fit: cover;
            background: #222;
            margin-bottom: 18px;
        }

        h1 {
            margin: 0 0 8px;
            font-size: 30px;
        }

        p {
            margin: 0 0 20px;
            color: rgba(255,255,255,.68);
            line-height: 1.5;
        }

        a {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            height: 42px;
            padding: 0 18px;
            border-radius: 999px;
            background: #ff002b;
            color: #fff;
            text-decoration: none;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .8px;
            font-size: 12px;
        }
    </style>
</head>
<body>
    <main class="card">
        <img src="<?= e($image) ?>" alt="Profil">
        <h1><?= e($displayUsername) ?></h1>
        <p><?= e($description) ?></p>
        <a href="<?= e($profileUrl) ?>">Otwórz profil</a>
    </main>
</body>
</html>