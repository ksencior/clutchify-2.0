<?php
if ($action === 'search_users') {
    $userId = requireUserId();
    $query = trim($_GET['q'] ?? '');

    if (strlen($query) < 2) {
        echo json_encode(['success' => true, 'users' => []]);
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT u.id, u.username, p.avatar
        FROM users u
        LEFT JOIN players p ON u.id = p.user_id
        WHERE u.id != ? AND u.username LIKE ?
        ORDER BY u.username ASC
        LIMIT 12
    ");
    $stmt->execute([$userId, '%' . $query . '%']);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($users as &$user) {
        $info = getFriendStatusForViewer($pdo, $userId, (int)$user['id']);
        $user['friend_status'] = $info['status'];
        $user['friendship_id'] = $info['friendship_id'];
    }

    echo json_encode(['success' => true, 'users' => $users]);
    exit; }

if ($action === 'send_friend_request') {
    $userId = requireUserId();
    $input = json_decode(file_get_contents('php://input'), true);
    $targetId = (int)($input['target_id'] ?? 0);

    if (!$targetId || $targetId === $userId) {
        echo json_encode(['success' => false, 'message' => 'Nie możesz zaprosić samego siebie.']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
    $stmt->execute([$targetId]);
    $target = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$target) {
        echo json_encode(['success' => false, 'message' => 'Ten gracz nie istnieje.']);
        exit;
    }

    $friendship = getFriendship($pdo, $userId, $targetId);

    if ($friendship && $friendship['status'] === 'accepted') {
        echo json_encode(['success' => false, 'message' => 'Już jesteście znajomymi.']);
        exit;
    }

    if ($friendship && $friendship['status'] === 'pending') {
        echo json_encode(['success' => false, 'message' => 'Zaproszenie już oczekuje.']);
        exit;
    }

    try {
        $pdo->beginTransaction();

        if ($friendship) {
            $stmt = $pdo->prepare("
                UPDATE friendships
                SET requester_id = ?, addressee_id = ?, status = 'pending'
                WHERE id = ?
            ");
            $stmt->execute([$userId, $targetId, $friendship['id']]);
            $friendshipId = (int)$friendship['id'];
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO friendships (requester_id, addressee_id, status)
                VALUES (?, ?, 'pending')
            ");
            $stmt->execute([$userId, $targetId]);
            $friendshipId = (int)$pdo->lastInsertId();
        }

        $stmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $me = $stmt->fetch(PDO::FETCH_ASSOC);

        $safeUsername = htmlspecialchars($me['username'] ?? 'Ktoś', ENT_QUOTES, 'UTF-8');
        $message = "<strong>{$safeUsername}</strong> chce dodać Cię do znajomych.";

        $stmt = $pdo->prepare("
            INSERT INTO notifications (user_id, type, reference_id, message)
            VALUES (?, 'friend_request', ?, ?)
        ");
        $stmt->execute([$targetId, $friendshipId, $message]);

        $pdo->commit();

        echo json_encode([
            'success' => true,
            'message' => 'Zaproszenie do znajomych wysłane.',
            'targetId' => $targetId
        ]);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Nie udało się wysłać zaproszenia.']);
    }
    exit; }

if ($action === 'get_friend_requests') {
    $userId = requireUserId();

    $stmt = $pdo->prepare("
        SELECT f.id AS friendship_id, u.id, u.username, p.avatar, f.created_at
        FROM friendships f
        JOIN users u ON u.id = f.requester_id
        LEFT JOIN players p ON p.user_id = u.id
        WHERE f.addressee_id = ? AND f.status = 'pending'
        ORDER BY f.created_at DESC
    ");
    $stmt->execute([$userId]);

    echo json_encode([
        'success' => true,
        'requests' => $stmt->fetchAll(PDO::FETCH_ASSOC)
    ]);
    exit; }

if ($action === 'respond_friend_request') {
    $userId = requireUserId();
    $input = json_decode(file_get_contents('php://input'), true);

    $friendshipId = (int)($input['friendship_id'] ?? 0);
    $choice = $input['action'] ?? '';

    if (!in_array($choice, ['accept', 'reject'], true)) {
        echo json_encode(['success' => false, 'message' => 'Nieprawidłowa akcja.']);
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT *
        FROM friendships
        WHERE id = ? AND addressee_id = ? AND status = 'pending'
    ");
    $stmt->execute([$friendshipId, $userId]);
    $friendship = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$friendship) {
        echo json_encode(['success' => false, 'message' => 'Zaproszenie wygasło albo nie istnieje.']);
        exit;
    }

    $newStatus = $choice === 'accept' ? 'accepted' : 'rejected';

    $stmt = $pdo->prepare("UPDATE friendships SET status = ? WHERE id = ?");
    $stmt->execute([$newStatus, $friendshipId]);

    $stmt = $pdo->prepare("
        UPDATE notifications
        SET status = ?
        WHERE user_id = ?
            AND type = 'friend_request'
            AND reference_id = ?
            AND status = 'pending'
    ");
    $stmt->execute([$newStatus, $userId, $friendshipId]);

    echo json_encode([
        'success' => true,
        'message' => $choice === 'accept'
            ? 'Dodano do znajomych.'
            : 'Zaproszenie odrzucone.'
    ]);
    exit; }

if ($action === 'get_friends') {
    $userId = requireUserId();

    $stmt = $pdo->prepare("
        SELECT
            u.id,
            u.username,
            p.avatar,
            (
                SELECT body
                FROM private_messages m
                WHERE (m.sender_id = u.id AND m.receiver_id = ?)
                    OR (m.sender_id = ? AND m.receiver_id = u.id)
                ORDER BY m.created_at DESC
                LIMIT 1
            ) AS last_message
        FROM friendships f
        JOIN users u ON u.id = CASE
            WHEN f.requester_id = ? THEN f.addressee_id
            ELSE f.requester_id
        END
        LEFT JOIN players p ON p.user_id = u.id
        WHERE (f.requester_id = ? OR f.addressee_id = ?)
            AND f.status = 'accepted'
        ORDER BY u.username ASC
    ");
    $stmt->execute([$userId, $userId, $userId, $userId, $userId]);

    echo json_encode([
        'success' => true,
        'friends' => $stmt->fetchAll(PDO::FETCH_ASSOC)
    ]);
    exit; }
