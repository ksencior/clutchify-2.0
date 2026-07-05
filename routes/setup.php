<?php

if ($action === 'check-for-configuration') {
    if (!isset($_SESSION['user_id'])) exit;
    $stmt = $pdo->prepare("SELECT steam_id, discord_id FROM players WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $response = $stmt->fetch();
    if ($response['steam_id'] == NULL || $response['discord_id'] == NULL) {
        echo json_encode(['success' => true, 'required' => true]);
        exit;
    }
    echo json_encode(['success' => true, 'required' => false]);
    exit;
}
if ($action === 'ensure_connection') {
    if (!isset($_SESSION['user_id'])) exit;
    $input = json_decode(file_get_contents('php://input'), true);
    $provider = ($input['provider'] ?? NULL);

    if (!$provider) {
        echo json_encode(['success' => false, 'message' => 'Unknown provider.']);
        exit;
    }

    if ($provider === 'steam') {
        $stmt = $pdo->prepare('SELECT steam_id FROM players WHERE user_id = ?');
        $stmt->execute([$_SESSION['user_id']]);
        $res = $stmt->fetch();

        $steamId = $res['steam_id'];

        if (!$res || $steamId == NULL) {
            echo json_encode(['success' => true, 'connected' => false]);
            exit;
        } else {
            echo json_encode(['success' => true, 'connected' => true, 'debug' => $steamId]);
            exit;
        }
    } else if ($provider === 'discord') {
        $stmt = $pdo->prepare('SELECT discord_id FROM players WHERE user_id = ?');
        $stmt->execute([$_SESSION['user_id']]);
        $res = $stmt->fetch();

        $discord_id = $res['discord_id'];

        if (!$res || $discord_id == NULL) {
            echo json_encode(['success' => true, 'connected' => false]);
            exit;
        } else {
            echo json_encode(['success' => true, 'connected' => true, 'debug' => $discord_id]);
            exit;
        }
    }
    echo json_encode(['success' => false, 'message' => 'Unknown provider.']);
    exit;
}