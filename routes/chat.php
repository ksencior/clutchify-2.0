<?php
if ($action === 'get_chat') {
    $userId = requireUserId();
    $friendId = (int)($_GET['friend_id'] ?? 0);

    if (!$friendId || !areFriends($pdo, $userId, $friendId)) {
        echo json_encode(['success' => false, 'message' => 'Możesz pisać tylko ze znajomymi.']);
        exit;
    }

    $stmt = $pdo->prepare("
        UPDATE private_messages
        SET is_read = 1
        WHERE sender_id = ? AND receiver_id = ?
    ");
    $stmt->execute([$friendId, $userId]);

    $stmt = $pdo->prepare("
        SELECT id, sender_id, receiver_id, body, created_at
        FROM private_messages
        WHERE (sender_id = ? AND receiver_id = ?)
            OR (sender_id = ? AND receiver_id = ?)
        ORDER BY created_at ASC
        LIMIT 100
    ");
    $stmt->execute([$userId, $friendId, $friendId, $userId]);

    echo json_encode([
        'success' => true,
        'messages' => $stmt->fetchAll(PDO::FETCH_ASSOC)
    ]);
    exit; }

if ($action === 'send_message') {
    $userId = requireUserId();
    $input = json_decode(file_get_contents('php://input'), true);

    $receiverId = (int)($input['receiver_id'] ?? 0);
    $body = trim($input['body'] ?? '');

    if (!$receiverId || strlen($body) === 0) {
        echo json_encode(['success' => false, 'message' => 'Wiadomość nie może być pusta.']);
        exit;
    }

    if (strlen($body) > 1000) {
        echo json_encode(['success' => false, 'message' => 'Wiadomość jest za długa.']);
        exit;
    }

    if (!areFriends($pdo, $userId, $receiverId)) {
        echo json_encode(['success' => false, 'message' => 'Możesz pisać tylko ze znajomymi.']);
        exit;
    }

    $stmt = $pdo->prepare("
        INSERT INTO private_messages (sender_id, receiver_id, body)
        VALUES (?, ?, ?)
    ");
    $stmt->execute([$userId, $receiverId, $body]);

    echo json_encode([
        'success' => true,
        'message' => 'Wysłano.',
        'message_id' => (int)$pdo->lastInsertId(),
        'targetId' => $receiverId
    ]);
    exit; }
if ($action === 'get_unread_message_threads') {
    $userId = requireUserId();

    $stmt = $pdo->prepare("
        SELECT
            u.id,
            u.username,
            p.avatar,
            unread.unread_count,
            unread.last_message_at,
            (
                SELECT m2.body
                FROM private_messages m2
                WHERE m2.sender_id = unread.sender_id
                    AND m2.receiver_id = ?
                    AND m2.is_read = 0
                ORDER BY m2.created_at DESC
                LIMIT 1
            ) AS last_message
        FROM (
            SELECT
                sender_id,
                COUNT(*) AS unread_count,
                MAX(created_at) AS last_message_at
            FROM private_messages
            WHERE receiver_id = ?
                AND is_read = 0
            GROUP BY sender_id
        ) unread
        JOIN users u ON u.id = unread.sender_id
        LEFT JOIN players p ON p.user_id = u.id
        ORDER BY unread.last_message_at DESC
    ");

    $stmt->execute([$userId, $userId]);

    $threads = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $unreadTotal = 0;

    foreach ($threads as &$thread) {
        $thread['id'] = (int)$thread['id'];
        $thread['unread_count'] = (int)$thread['unread_count'];
        $unreadTotal += $thread['unread_count'];
    }

    echo json_encode([
        'success' => true,
        'unread_total' => $unreadTotal,
        'threads' => $threads
    ]);
    exit; }