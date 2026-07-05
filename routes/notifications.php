<?php
if ($action === 'get_notifications') {
    $userId = requireUserId();

    /**
     * Lista:
     * - zaproszenia pokazujemy tylko pending, bo wymagają akcji
     * - systemowe pokazujemy pending + seen, żeby nie znikały po przeczytaniu
     */
    $stmt = $pdo->prepare("
        SELECT id, type, reference_id, message, status, created_at
        FROM notifications
        WHERE user_id = ?
          AND (
                (type IN ('team_invite', 'friend_request') AND status = 'pending')
             OR (type = 'system' AND status IN ('pending', 'seen'))
          )
        ORDER BY created_at DESC
        LIMIT 50
    ");

    $stmt->execute([$userId]);
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

    /**
     * Badge:
     * - liczy pending zaproszenia
     * - liczy pending systemowe
     * - NIE liczy seen systemowych
     */
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS unread_count
        FROM notifications
        WHERE user_id = ?
          AND status = 'pending'
          AND type IN ('team_invite', 'friend_request', 'system')
    ");

    $stmt->execute([$userId]);
    $countRow = $stmt->fetch(PDO::FETCH_ASSOC);

    jsonSuccess([
        'notifications' => $notifications,
        'unread_count' => (int)($countRow['unread_count'] ?? 0)
    ]);
}
if ($action === 'respond_notification') {
    if (!isset($_SESSION['user_id'])) exit;
    $input = json_decode(file_get_contents('php://input'), true);
    $notifId = (int)($input['notification_id'] ?? 0);
    $action = $input['action'] ?? ''; // 'accept' lub 'reject'

    // Pobierz powiadomienie upewniając się, że należy do usera i jest pending
    $stmt = $pdo->prepare("SELECT * FROM notifications WHERE id = ? AND user_id = ? AND status = 'pending'");
    $stmt->execute([$notifId, $_SESSION['user_id']]);
    $notif = $stmt->fetch();

    if (!$notif) {
        echo json_encode(['success' => false, 'message' => 'Powiadomienie wygasło lub nie istnieje.']);
        exit;
    }

    if ($action === 'reject') {
        if ($notif['type'] === 'friend_request') {
            $stmt = $pdo->prepare("
                UPDATE friendships
                SET status = 'rejected'
                WHERE id = ? AND addressee_id = ? AND status = 'pending'
            ");
            $stmt->execute([(int)$notif['reference_id'], (int)$_SESSION['user_id']]);
        }

        $stmt = $pdo->prepare("UPDATE notifications SET status = 'rejected' WHERE id = ?");
        $stmt->execute([$notifId]);

        echo json_encode(['success' => true, 'message' => 'Zaproszenie zostało odrzucone.']);
        exit;
    }

    if ($action === 'accept' && $notif['type'] === 'friend_request') {
        $friendshipId = (int)$notif['reference_id'];

        $stmt = $pdo->prepare("
            SELECT *
            FROM friendships
            WHERE id = ? AND addressee_id = ? AND status = 'pending'
        ");
        $stmt->execute([$friendshipId, $_SESSION['user_id']]);
        $friendship = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$friendship) {
            echo json_encode(['success' => false, 'message' => 'Zaproszenie wygasło albo nie istnieje.']);
            exit;
        }

        $stmt = $pdo->prepare("UPDATE friendships SET status = 'accepted' WHERE id = ?");
        $stmt->execute([$friendshipId]);

        $stmt = $pdo->prepare("UPDATE notifications SET status = 'accepted' WHERE id = ?");
        $stmt->execute([$notifId]);

        echo json_encode(['success' => true, 'message' => 'Dodano do znajomych.']);
        exit;
    }

    if ($action === 'accept' && $notif['type'] === 'team_invite') {
        $teamId = $notif['reference_id'];
        
        // Jeszcze raz weryfikujemy, czy gracz magicznie nie dołączył do innej drużyny w międzyczasie
        $stmt = $pdo->prepare("SELECT team_id FROM players WHERE user_id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $player = $stmt->fetch();
        
        if ($player['team_id'] !== null) {
            echo json_encode(['success' => false, 'message' => 'Należysz już do innej drużyny! Najpierw ją opuść.']);
            exit;
        }

        // Liczymy limit w drużynie (maks 6)
        $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM players WHERE team_id = ?");
        $stmt->execute([$teamId]);
        $count = $stmt->fetch();

        if ($count['total'] >= 6) {
            // Jeśli lider spamował zaproszeniami i limit się wyczerpał
            echo json_encode(['success' => false, 'message' => 'Niestety, skład tej drużyny jest już pełny.']);
            exit;
        }

        $isSub = ($count['total'] === 5) ? 1 : 0;

        try {
            $pdo->beginTransaction();
            // Dodanie gracza
            $stmt = $pdo->prepare("UPDATE players SET team_id = ?, is_substitute = ? WHERE user_id = ?");
            $stmt->execute([$teamId, $isSub, $_SESSION['user_id']]);
            
            // Zmiana statusu na accepted
            $stmt = $pdo->prepare("UPDATE notifications SET status = 'accepted' WHERE id = ?");
            $stmt->execute([$notifId]);
            
            $pdo->commit();
            echo json_encode(['success' => true, 'message' => 'Dołączyłeś do drużyny!']);
        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Wystąpił błąd krytyczny.']);
        }
        exit;
    }
    exit; }
if ($action === 'mark_system_notifications_seen') {
    $userId = requireUserId();

    $stmt = $pdo->prepare("
        UPDATE notifications
        SET status = 'seen'
        WHERE user_id = ?
          AND type = 'system'
          AND status = 'pending'
    ");

    $stmt->execute([$userId]);

    jsonSuccess([
        'marked' => $stmt->rowCount()
    ]);
}