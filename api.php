<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once 'db.php';
require_once 'classes/Auth.php';
require_once 'classes/User.php';

$auth = new Auth($pdo);

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'register':
        $data = json_decode(file_get_contents('php://input'), true);
        if (!empty($data['username']) && !empty($data['email']) && !empty($data['password'])) {
            $result = $auth->register($data['username'], $data['email'], $data['password']);
            echo json_encode($result);
        } else {
            echo json_encode(['success' => false, 'message' => 'Wszystkie pola są wymagane.']);
        }
        break;
    case 'login':
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (!empty($data['email']) && !empty($data['password'])) {
            $result = $auth->login($data['email'], $data['password']);
            echo json_encode($result);
        } else {
            echo json_encode(['success' => false, 'message' => 'Email i hasło są wymagane.']);
        }
        break;

    case 'logout':
        $auth->logout();
        echo json_encode(['success' => true, 'message' => 'Wylogowano.']);
        break;
    case 'get_current_user':
        if ($auth->isLoggedIn()) {
            $userId = $auth->getLoggedInUserId();
            $user = new User($pdo, $userId);
            echo json_encode(['logged_in' => true, 'user' => $user->toArray()]);
        } else {
            echo json_encode(['logged_in' => false]);
        }
        break;
    case 'get_teams':
        try {
            $stmt = $pdo->query("SELECT id, name, tag, logo, is_open FROM teams WHERE is_open = 1 ORDER BY id DESC");
            $teams = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode($teams);
        } catch (PDOException $e) {
            echo json_encode(['error' => 'Błąd bazy danych: ' . $e->getMessage()]);
        }
        break;
    case 'get_my_team':
        if (!isset($_SESSION['user_id'])) {
            echo json_encode(['error' => 'Niezalogowany']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT team_id FROM players WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch();

        if (!$user || !$user['team_id']) {
            echo json_encode(['has_team' => false]);
            exit;
        }

        $stmt = $pdo->prepare("SELECT * FROM teams WHERE id = ?");
        $stmt->execute([$user['team_id']]);
        $team = $stmt->fetch(PDO::FETCH_ASSOC);

        $stmt = $pdo->prepare("SELECT u.id, u.username, p.is_substitute FROM users u JOIN players p ON u.id = p.user_id WHERE p.team_id = ?");
        $stmt->execute([$user['team_id']]);
        $members = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($members as &$m) {
            $m['is_captain'] = ((int)$m['id'] === (int)$team['captain_id']);
            $m['is_sub'] = (bool)$m['is_substitute'];
        }

        echo json_encode([
            'has_team' => true,
            'team' => [
                'id' => $team['id'],
                'name' => $team['name'],
                'tag' => $team['tag'],
                'logo' => $team['logo'],
                'is_open' => (bool)$team['is_open'],
                'captain_id' => (int)$team['captain_id'],
                'members' => $members
            ]
        ]);
        break;
    case 'get_notifications':
        if (!isset($_SESSION['user_id'])) exit;
        $stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? AND status = 'pending' ORDER BY created_at DESC");
        $stmt->execute([$_SESSION['user_id']]);
        $notifs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'notifications' => $notifs]);
        break;
    case 'respond_notification':
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
            $stmt = $pdo->prepare("UPDATE notifications SET status = 'rejected' WHERE id = ?");
            $stmt->execute([$notifId]);
            echo json_encode(['success' => true, 'message' => 'Zaproszenie zostało odrzucone.']);
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
        break;
    case 'create_team':
        if (!isset($_SESSION['user_id'])) {
            echo json_encode(['success' => false, 'message' => 'Musisz być zalogowany!']);
            exit;
        }

        $input = json_decode(file_get_contents('php://input'), true);
        $name = trim($input['name'] ?? '');
        $tag = strtoupper(trim($input['tag'] ?? ''));
        $isOpen = $input['is_open'] ? 1 : 0;
        $userId = $_SESSION['user_id'];

        if (empty($name) || empty($tag)) {
            echo json_encode(['success' => false, 'message' => 'Nazwa i tag są wymagane.']);
            exit;
        }

        try {
            $pdo->beginTransaction();

            // Wstawiamy drużynę do bazy
            $stmt = $pdo->prepare("INSERT INTO teams (name, tag, captain_id, is_open) VALUES (?, ?, ?, ?)");
            $stmt->execute([$name, $tag, $userId, $isOpen]);
            $teamId = $pdo->lastInsertId();

            // Przypisujemy kapitana do tej drużyny
            $stmt = $pdo->prepare("UPDATE players SET team_id = ?, is_substitute = 0 WHERE user_id = ?");
            $stmt->execute([$teamId, $userId]);

            $pdo->commit();
            echo json_encode(['success' => true, 'message' => 'Drużyna utworzona pomyślnie!']);
        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Nazwa lub Tag drużyny są już zajęte!']);
        }
        break;
    case 'search_players':
        if (!isset($_SESSION['user_id'])) exit;
        $query = $_GET['q'] ?? '';
        if (strlen($query) < 2) {
            echo json_encode([]);
            exit;
        }

        $stmt = $pdo->prepare("
            SELECT u.id, u.username, p.avatar 
            FROM users u 
            JOIN players p ON u.id = p.user_id 
            WHERE u.username LIKE ? AND p.team_id IS NULL 
            LIMIT 5
        ");
        
        $stmt->execute(['%' . $query . '%']);
        $players = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode($players);
        break;
    case 'update_team_logo':
        if (!isset($_SESSION['user_id'])) exit;
        $input = json_decode(file_get_contents('php://input'), true);
        $logoUrl = trim($input['logo'] ?? '');

        // Sprawdzamy czy użytkownik jest kapitanem jakiejś drużyny
        $stmt = $pdo->prepare("SELECT id FROM teams WHERE captain_id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $team = $stmt->fetch();

        if ($team && !empty($logoUrl)) {
            $stmt = $pdo->prepare("UPDATE teams SET logo = ? WHERE id = ?");
            $stmt->execute([$logoUrl, $team['id']]);
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Nie masz uprawnień lub zły link.']);
        }
        break;

    case 'invite_player':
        if (!isset($_SESSION['user_id'])) exit;
        $input = json_decode(file_get_contents('php://input'), true);
        $targetUsername = trim($input['username'] ?? '');

        $stmt = $pdo->prepare("SELECT id, name FROM teams WHERE captain_id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $team = $stmt->fetch();

        if (!$team) {
            echo json_encode(['success' => false, 'message' => 'Tylko lider może rekrutować!']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT u.id, p.team_id FROM users u JOIN players p ON u.id = p.user_id WHERE username = ?");
        $stmt->execute([$targetUsername]);
        $targetUser = $stmt->fetch();

        if (!$targetUser) {
            echo json_encode(['success' => false, 'message' => 'Gracz o podanym nicku nie istnieje.']);
            exit;
        }

        if ($targetUser['team_id'] !== null) {
            echo json_encode(['success' => false, 'message' => 'Ten gracz należy już do innej drużyny!']);
            exit;
        }

        // Sprawdzamy limit miejsc (max 6 w teamie)
        $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM players WHERE team_id = ?");
        $stmt->execute([$team['id']]);
        $count = $stmt->fetch();

        if ($count['total'] >= 6) {
            echo json_encode(['success' => false, 'message' => 'Skład drużyny jest już pełny (Max 5 + 1 rezerwa)!']);
            exit;
        }

        $isSub = ($count['total'] === 5) ? 1 : 0;

        $stmt = $pdo->prepare("SELECT id FROM notifications WHERE user_id = ? AND reference_id = ? AND status = 'pending' AND type = 'team_invite'");
        $stmt->execute([$targetUser['id'], $team['id']]);
        if ($stmt->fetch()) {
            echo json_encode(['success' => false, 'message' => 'Zaproszenie do tego gracza już oczekuje na akceptację!']);
            exit;
        }

        $message = "Zostałeś zaproszony do drużyny <strong>" . htmlspecialchars($team['name']) . "</strong>.";
        $stmt = $pdo->prepare("INSERT INTO notifications (user_id, type, reference_id, message) VALUES (?, 'team_invite', ?, ?)");
        $stmt->execute([$targetUser['id'], $team['id'], $message]);

        echo json_encode(['success' => true, 'message' => "Zaproszenie zostało wysłane do gracza $targetUsername!"]);
        break;

    case 'leave_team':
        if (!isset($_SESSION['user_id'])) exit;
        $stmt = $pdo->prepare("SELECT t.id, t.captain_id FROM teams t JOIN players p ON t.id = p.team_id WHERE p.user_id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $result = $stmt->fetch();
        $teamId = $result['id'];
        $captainId = $result['captain_id'];

        $stmt = $pdo->prepare("SELECT COUNT(user_id) as 'liczba_graczy' FROM players WHERE team_id = ? AND is_substitute = false;");
        $stmt->execute([$teamId]);
        $result = $stmt->fetch();
        $pCount = $result['liczba_graczy'];

        if ($pCount <= 1) {
            echo json_encode(['success' => true, 'action' => 'popout']);
            exit;
        } else {
            if ($captainId === $_SESSION['user_id']) {
                $stmt = $pdo->prepare("SELECT user_id FROM players WHERE team_id = ? AND user_id != ? AND is_substitute = false ORDER BY RAND()");
                $stmt->execute([$teamId, $captainId]);
                $members = $stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach($members as $m) {
                    $stmt = $pdo->prepare("UPDATE teams SET captain_id = ? WHERE id = ?");
                    $stmt->execute([$m['user_id'], $teamId]);
                    break;
                }
                $stmt = $pdo->prepare("UPDATE players SET team_id = NULL WHERE user_id = ?");
                $stmt->execute([$_SESSION['user_id']]);
            } else {
                // wychodzi
                $stmt = $pdo->prepare("UPDATE players SET team_id = NULL WHERE user_id = ?");
                $stmt->execute([$_SESSION['user_id']]);
            }
            echo json_encode(['success' => true, 'message' => "Opuszczono drużynę."]);
        }

        break;
    case 'delete_team':
        if (!isset($_SESSION['user_id'])) exit;
        $stmt = $pdo->prepare("SELECT t.id, t.captain_id FROM teams t JOIN players p ON t.id = p.team_id WHERE p.user_id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $result = $stmt->fetch();
        $teamId = $result['id'];
        $captainId = $result['captain_id'];

        if ($captainId === $_SESSION['user_id']) {
            $stmt = $pdo->prepare("DELETE FROM teams WHERE id = ?");
            $stmt->execute([$teamId]);
            $stmt = $pdo->prepare("UPDATE players SET team_id = NULL WHERE user_id = ?");
            $stmt->execute([$_SESSION['user_id']]);

            echo json_encode(['success' => true, 'message' => "Opuszczono oraz usunięto drużynę."]);
            exit;
        }
        echo json_encode(['success' => false, 'message' => "Coś poszło nie tak."]);
        break;
    case 'kick_player':
        if (!isset($_SESSION['user_id'])) exit;
        $input = json_decode(file_get_contents('php://input'), true);
        $targetId = (int)($input['player_id'] ?? 0);

        $stmt = $pdo->prepare("SELECT id FROM teams WHERE captain_id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $team = $stmt->fetch();

        if (!$team) {
            echo json_encode(['success' => false, 'message' => 'Tylko lider może zarządzać składem!']);
            exit;
        }

        if ($targetId === (int)$_SESSION['user_id']) {
            echo json_encode(['success' => false, 'message' => 'Nie możesz wyrzucić samego siebie!']);
            exit;
        }

        $stmt = $pdo->prepare("UPDATE players SET team_id = NULL, is_substitute = 0 WHERE user_id = ? AND team_id = ?");
        $stmt->execute([$targetId, $team['id']]);

        echo json_encode(['success' => true, 'message' => 'Gracz został usunięty ze składu.']);
        break;
    case 'check-for-configuration':
        if (!isset($_SESSION['user_id'])) exit;
        $stmt = $pdo->prepare("SELECT steam_id, discord_id FROM players WHERE user_id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $response = $stmt->fetch();
        if ($response['steam_id'] == NULL && $response['discord_id'] == NULL) {
            echo json_encode(['success' => true, 'required' => true]);
            exit;
        }
        echo json_encode(['success' => true, 'required' => false]);
        break;
    case 'ensure_connection':
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
        break;
    default:
        echo json_encode(['message' => 'Nieznana akcja API']);
        break;
}