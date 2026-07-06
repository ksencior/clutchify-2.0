<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

error_reporting(E_ALL);
ini_set('display_errors', 1);

require __DIR__ . "/discord/oauth.php";
require __DIR__ . "/discord/config.php";
require_once __DIR__ . "/../db.php";

if (!isset($_SESSION['user_id'])) {
    die("Błąd: Twoja sesja wygasła. Zaloguj się ponownie w aplikacji.");
}

/**
 * Pierwsze wejście:
 * Nie mamy jeszcze ?code=...
 * więc generujemy state, zapisujemy w sesji i dopiero wtedy lecimy na Discorda.
 */
if (!isset($_GET['code'])) {
    $authUrl = url($client_id, $redirect_url, $scopes);

    session_write_close();

    header("Location: " . $authUrl);
    exit;
}

/**
 * Drugie wejście:
 * Discord wrócił na ten plik z ?code=...&state=...
 */
try {
    init($redirect_url, $client_id, $secret_id);

    $discordId = get_user();

    $stmt = $pdo->prepare("UPDATE players SET discord_id = ? WHERE user_id = ?");
    $stmt->execute([$discordId, $_SESSION['user_id']]);

    header("Location: ../setup?action=connected&p=discord");
    exit;
} catch (Exception $e) {
    die($e->getMessage());
}