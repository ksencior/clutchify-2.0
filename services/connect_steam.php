<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/steam/openid.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../config/env.php';

$realm = env('APP_HOST', 'clutchify.test');

$returnUrl = env(
    'STEAM_RETURN_URL',
    rtrim(env('APP_URL', 'http://clutchify.test'), '/') . '/services/connect_steam.php'
);

$openid = new LightOpenID($realm, $returnUrl);

if (!isset($_SESSION['user_id'])) {
    die("Błąd: Twoja sesja wygasła. Zaloguj się ponownie w aplikacji.");
}

if (!$openid->mode) {
    $openid->identity = 'http://specs.openid.net/auth/2.0/identifier_select';

    $authUrl = $openid->authUrl();

    session_write_close();

    header('Location: ' . $authUrl);
    exit;
}

if ($openid->mode === 'cancel') {
    echo 'Anulowano';
    exit;
}

if ($openid->validate()) {
    $id = $_GET['openid_claimed_id'] ?? '';

    if (preg_match("#^https://steamcommunity.com/openid/id/([0-9]+)$#", $id, $matches)) {
        $steamid64 = $matches[1];

        $stmt = $pdo->prepare("UPDATE players SET steam_id = :steamid WHERE user_id = :id");
        $stmt->execute([
            ':steamid' => $steamid64,
            ':id' => $_SESSION['user_id']
        ]);

        header("Location: ../setup?action=connected&p=steam");
        exit;
    }

    die('Nie udało się odczytać SteamID.');
}

echo "Błąd logowania przez Steam.";