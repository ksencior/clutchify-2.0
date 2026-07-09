<?php
if ($action === 'get_teams') {
    $userId = requireUserId();

    $stmt = $pdo->prepare("
        SELECT team_id
        FROM players
        WHERE user_id = ?
        LIMIT 1
    ");
    $stmt->execute([$userId]);
    $viewerTeamId = $stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT
            t.id,
            t.name,
            t.tag,
            t.logo,
            t.is_open,
            t.captain_id,

            captain.username AS captain_username,

            (
                SELECT COUNT(*)
                FROM players p
                WHERE p.team_id = t.id
            ) AS members_count,

            (
                SELECT tjr.status
                FROM team_join_requests tjr
                WHERE tjr.team_id = t.id
                  AND tjr.requester_user_id = ?
                ORDER BY tjr.id DESC
                LIMIT 1
            ) AS join_request_status
        FROM teams t
        JOIN users captain ON captain.id = t.captain_id
        WHERE t.is_open = 1
        ORDER BY t.id DESC
    ");

    $stmt->execute([$userId]);
    $teams = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($teams as &$team) {
        $team['id'] = (int)$team['id'];
        $team['captain_id'] = (int)$team['captain_id'];
        $team['is_open'] = (bool)$team['is_open'];
        $team['members_count'] = (int)$team['members_count'];
        $team['is_full'] = $team['members_count'] >= 6;
        $team['viewer_has_team'] = $viewerTeamId !== false && $viewerTeamId !== null;
        $team['join_request_status'] = $team['join_request_status'] ?: null;
    }

    echo json_encode($teams);
    exit;
}
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
if ($action === 'request_join_team') {
    $userId = requireUserId();
    $input = getJsonInput();

    $teamId = (int)($input['team_id'] ?? 0);

    if ($teamId <= 0) {
        jsonError('Nieprawidłowa drużyna.');
    }

    $stmt = $pdo->prepare("
        SELECT
            u.username,
            p.team_id
        FROM users u
        JOIN players p ON p.user_id = u.id
        WHERE u.id = ?
        LIMIT 1
    ");
    $stmt->execute([$userId]);
    $requester = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$requester) {
        jsonError('Nie znaleziono profilu gracza.', 404);
    }

    if ($requester['team_id'] !== null) {
        jsonError('Należysz już do drużyny. Najpierw ją opuść.');
    }

    $stmt = $pdo->prepare("
        SELECT
            t.id,
            t.name,
            t.tag,
            t.is_open,
            t.captain_id,
            captain.username AS captain_username
        FROM teams t
        JOIN users captain ON captain.id = t.captain_id
        WHERE t.id = ?
        LIMIT 1
    ");
    $stmt->execute([$teamId]);
    $team = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$team) {
        jsonError('Drużyna nie istnieje.', 404);
    }

    if (!(bool)$team['is_open']) {
        jsonError('Ta drużyna nie prowadzi otwartej rekrutacji.');
    }

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM players
        WHERE team_id = ?
    ");
    $stmt->execute([$teamId]);

    if ((int)$stmt->fetchColumn() >= 6) {
        jsonError('Skład tej drużyny jest już pełny.');
    }

    $stmt = $pdo->prepare("
        SELECT id
        FROM team_join_requests
        WHERE team_id = ?
          AND requester_user_id = ?
          AND status = 'pending'
        LIMIT 1
    ");
    $stmt->execute([$teamId, $userId]);

    if ($stmt->fetch()) {
        jsonError('Masz już wysłaną prośbę do tej drużyny.');
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            INSERT INTO team_join_requests (team_id, requester_user_id)
            VALUES (?, ?)
        ");
        $stmt->execute([$teamId, $userId]);

        $requestId = (int)$pdo->lastInsertId();

        $safeUsername = htmlspecialchars($requester['username'], ENT_QUOTES, 'UTF-8');
        $safeTeamName = htmlspecialchars($team['name'], ENT_QUOTES, 'UTF-8');

        $message = "Gracz <strong>{$safeUsername}</strong> chce dołączyć do drużyny <strong>{$safeTeamName}</strong>.";

        $stmt = $pdo->prepare("
            INSERT INTO notifications (user_id, type, reference_id, message)
            VALUES (?, 'team_join_request', ?, ?)
        ");
        $stmt->execute([
            (int)$team['captain_id'],
            $requestId,
            $message
        ]);

        $pdo->commit();

        jsonSuccess([
            'message' => 'Prośba o dołączenie została wysłana do lidera.',
            'targetId' => (int)$team['captain_id']
        ]);
    } catch (Throwable $e) {
        $pdo->rollBack();
        jsonError('Nie udało się wysłać prośby o dołączenie.');
    }
}
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