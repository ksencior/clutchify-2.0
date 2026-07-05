<?php
if ($action === 'get_teams') {
    try {
        $stmt = $pdo->query("SELECT id, name, tag, logo, is_open FROM teams WHERE is_open = 1 ORDER BY id DESC");
        $teams = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode($teams);
    } catch (PDOException $e) {
        echo json_encode(['error' => 'Błąd bazy danych: ' . $e->getMessage()]);
    }
    exit; }
if ($action === 'get_my_team') {
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['error' => 'Niezalogowany']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT team_id FROM players WHERE user_id = ?");
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
    exit; }
if ($action === 'create_team') {
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
        logActivity(
            $pdo,
            'team_created',
            'Nowa drużyna',
            'Powstała nowa drużyna: ' . $name . '.',
            $userId,
            'team',
            (int)$teamId,
            [
                'team_name' => $name,
                'team_tag' => $tag ?? null
            ],
            'public'
        );
        echo json_encode(['success' => true, 'message' => 'Drużyna utworzona pomyślnie!']);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Nazwa lub Tag drużyny są już zajęte!']);
    }
    exit; }
if ($action === 'search_players') {
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
    exit; }
if ($action === 'update_team_logo') {
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
    exit; }

if ($action === 'invite_player') {
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

    echo json_encode(['success' => true, 'message' => "Zaproszenie zostało wysłane do gracza $targetUsername!", 'targetId' => $targetUser['id']]);
    exit; }

if ($action === 'leave_team') {
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

    exit; }
if ($action === 'delete_team') {
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
    exit; }
if ($action === 'kick_player') {
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
    exit; }