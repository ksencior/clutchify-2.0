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
                (type IN ('team_invite', 'team_join_request', 'friend_request') AND status = 'pending')
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
          AND type IN ('team_invite', 'team_join_request', 'friend_request', 'system')
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
        if ($notif['type'] === 'team_join_request') {
            $requestId = (int)$notif['reference_id'];

            $stmt = $pdo->prepare("
                SELECT
                    tjr.id,
                    tjr.team_id,
                    tjr.requester_user_id,
                    tjr.status,
                    t.name AS team_name,
                    t.captain_id
                FROM team_join_requests tjr
                JOIN teams t ON t.id = tjr.team_id
                WHERE tjr.id = ?
                LIMIT 1
            ");
            $stmt->execute([$requestId]);
            $request = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$request || (int)$request['captain_id'] !== (int)$_SESSION['user_id']) {
                echo json_encode(['success' => false, 'message' => 'Nie masz uprawnień do tej prośby.']);
                exit;
            }

            $stmt = $pdo->prepare("
                UPDATE team_join_requests
                SET status = 'rejected',
                    reviewed_by = ?,
                    reviewed_at = NOW()
                WHERE id = ?
                AND status = 'pending'
            ");
            $stmt->execute([
                (int)$_SESSION['user_id'],
                $requestId
            ]);

            $stmt = $pdo->prepare("UPDATE notifications SET status = 'rejected' WHERE id = ?");
            $stmt->execute([$notifId]);

            $safeTeamName = htmlspecialchars($request['team_name'], ENT_QUOTES, 'UTF-8');

            $stmt = $pdo->prepare("
                INSERT INTO notifications (user_id, type, reference_id, message)
                VALUES (?, 'system', ?, ?)
            ");
            $stmt->execute([
                (int)$request['requester_user_id'],
                (int)$request['team_id'],
                "Twoja prośba o dołączenie do drużyny <strong>{$safeTeamName}</strong> została odrzucona."
            ]);

            echo json_encode([
                'success' => true,
                'message' => 'Prośba została odrzucona.',
                'targetId' => (int)$request['requester_user_id']
            ]);
            exit;
        }
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

    if ($action === 'accept' && $notif['type'] === 'team_join_request') {
        $requestId = (int)$notif['reference_id'];

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                SELECT
                    tjr.id,
                    tjr.team_id,
                    tjr.requester_user_id,
                    tjr.status,
                    t.name AS team_name,
                    t.tag AS team_tag,
                    t.captain_id,
                    u.username AS requester_username
                FROM team_join_requests tjr
                JOIN teams t ON t.id = tjr.team_id
                JOIN users u ON u.id = tjr.requester_user_id
                WHERE tjr.id = ?
                LIMIT 1
            ");
            $stmt->execute([$requestId]);
            $request = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$request || (int)$request['captain_id'] !== (int)$_SESSION['user_id']) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'message' => 'Nie masz uprawnień do tej prośby.']);
                exit;
            }

            if ($request['status'] !== 'pending') {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'message' => 'Ta prośba nie jest już aktywna.']);
                exit;
            }

            $stmt = $pdo->prepare("
                SELECT team_id
                FROM players
                WHERE user_id = ?
                LIMIT 1
            ");
            $stmt->execute([(int)$request['requester_user_id']]);
            $playerTeamId = $stmt->fetchColumn();

            if ($playerTeamId !== false && $playerTeamId !== null) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'message' => 'Ten gracz dołączył już do innej drużyny.']);
                exit;
            }

            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM players
                WHERE team_id = ?
            ");
            $stmt->execute([(int)$request['team_id']]);
            $count = (int)$stmt->fetchColumn();

            if ($count >= 6) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'message' => 'Skład tej drużyny jest już pełny.']);
                exit;
            }

            $isSub = $count === 5 ? 1 : 0;

            $stmt = $pdo->prepare("
                UPDATE players
                SET team_id = ?,
                    is_substitute = ?
                WHERE user_id = ?
            ");
            $stmt->execute([
                (int)$request['team_id'],
                $isSub,
                (int)$request['requester_user_id']
            ]);

            $stmt = $pdo->prepare("
                UPDATE team_join_requests
                SET status = 'accepted',
                    reviewed_by = ?,
                    reviewed_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([
                (int)$_SESSION['user_id'],
                $requestId
            ]);

            $stmt = $pdo->prepare("
                UPDATE notifications
                SET status = 'accepted'
                WHERE id = ?
            ");
            $stmt->execute([$notifId]);

            /**
             * Anulujemy pozostałe pending prośby tego gracza.
             */
            $stmt = $pdo->prepare("
                UPDATE team_join_requests
                SET status = 'cancelled'
                WHERE requester_user_id = ?
                AND status = 'pending'
                AND id != ?
            ");
            $stmt->execute([
                (int)$request['requester_user_id'],
                $requestId
            ]);

            $stmt = $pdo->prepare("
                UPDATE notifications n
                JOIN team_join_requests tjr ON tjr.id = n.reference_id
                SET n.status = 'rejected'
                WHERE n.type = 'team_join_request'
                AND tjr.requester_user_id = ?
                AND tjr.status = 'cancelled'
            ");
            $stmt->execute([(int)$request['requester_user_id']]);

            $safeTeamName = htmlspecialchars($request['team_name'], ENT_QUOTES, 'UTF-8');

            $stmt = $pdo->prepare("
                INSERT INTO notifications (user_id, type, reference_id, message)
                VALUES (?, 'system', ?, ?)
            ");
            $stmt->execute([
                (int)$request['requester_user_id'],
                (int)$request['team_id'],
                "Twoja prośba o dołączenie do drużyny <strong>{$safeTeamName}</strong> została zaakceptowana."
            ]);

            $pdo->commit();

            logActivity(
                $pdo,
                'team_joined',
                'Nowy transfer',
                ($request['requester_username'] ?? 'Gracz') . ' dołączył do drużyny ' . $request['team_name'] . '.',
                (int)$request['requester_user_id'],
                'team',
                (int)$request['team_id'],
                [
                    'team_name' => $request['team_name'],
                    'team_tag' => $request['team_tag'] ?? null,
                    'requester_username' => $request['requester_username'] ?? null
                ],
                'friends'
            );

            echo json_encode([
                'success' => true,
                'message' => 'Gracz został dodany do drużyny.',
                'targetId' => (int)$request['requester_user_id']
            ]);
        } catch (Throwable $e) {
            $pdo->rollBack();

            echo json_encode([
                'success' => false,
                'message' => 'Nie udało się zaakceptować prośby.'
            ]);
        }

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
        $stmt = $pdo->prepare("SELECT p.team_id, u.username FROM players p JOIN users u ON p.user_id = u.id WHERE p.user_id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $player = $stmt->fetch();
        
        if ($player['team_id'] !== null) {
            echo json_encode(['success' => false, 'message' => 'Należysz już do innej drużyny! Najpierw ją opuść.']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT name, tag FROM teams WHERE id = ?");
        $stmt->execute([$teamId]);
        $team = $stmt->fetch();

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
            logActivity(
                $pdo,
                'team_joined',
                'Nowy transfer',
                ($player['username'] ?? 'Gracz') . ' dołączył do drużyny ' . $team['name'] . '.',
                (int)$_SESSION['user_id'],
                'team',
                (int)$teamId,
                [
                    'team_name' => $team['name'],
                    'team_tag' => $team['tag'] ?? null,
                    'requester_username' => $player['username'] ?? null
                ],
                'friends'
            );
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