<?php

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../config/env.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$apiKey = env('STEAM_API_KEY');
$userId = $_SESSION['user_id'] ?? null;

if (!$apiKey || !$userId) {
    return;
}

$stmt = $pdo->prepare("SELECT steam_id FROM players WHERE user_id = ?");
$stmt->execute([$userId]);

$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row || empty($row['steam_id'])) {
    return;
}

$steamId = $row['steam_id'];

$url = "https://api.steampowered.com/ISteamUser/GetPlayerSummaries/v2/?key=" . urlencode($apiKey) . "&steamids=" . urlencode($steamId);

$response = @file_get_contents($url);

if ($response === false) {
    return;
}

$data = json_decode($response, true);

if (empty($data['response']['players'][0])) {
    return;
}

$player = $data['response']['players'][0];
$avatar = $player['avatarfull'] ?? null;

if (!$avatar) {
    return;
}

try {
    $stmt = $pdo->prepare("UPDATE players SET avatar = :avatar WHERE user_id = :id");
    $stmt->execute([
        ':avatar' => $avatar,
        ':id' => $userId
    ]);
} catch (PDOException $e) {
    return;
}