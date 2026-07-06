<?php

if ($action === 'register') {
    $data = getJsonInput();

    $username = normalizeUsername((string)($data['username'] ?? ''));
    $email = trim((string)($data['email'] ?? ''));
    $password = (string)($data['password'] ?? '');

    if ($username === '' || $email === '' || $password === '') {
        echo json_encode([
            'success' => false,
            'message' => 'Wszystkie pola są wymagane.'
        ]);
        exit;
    }

    $result = $auth->register($username, $email, $password);

    echo json_encode($result);
    exit;
}
if ($action ===  'login') {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!empty($data['email']) && !empty($data['password'])) {
        $result = $auth->login($data['email'], $data['password']);
        echo json_encode($result);
    } else {
        echo json_encode(['success' => false, 'message' => 'Email i hasło są wymagane.']);
    }
    exit;
}
if ($action === 'logout') {
    requireUserId();

    $auth->logout();

    echo json_encode([
        'success' => true,
        'message' => 'Wylogowano.'
    ]);
    exit;
}
if ($action === 'get_current_user') {
    if ($auth->isLoggedIn()) {
        $userId = $auth->getLoggedInUserId();
        $user = new User($pdo, $userId);
        $userData = $user->toArray();

        echo json_encode([
            'logged_in' => true,
            'user' => $userData,
            'csrf_token' => getCsrfToken(),
            'ws_token' => create_ws_token(
                (int)$userId,
                $userData['username'] ?? ''
            ),
            'ws_port' => (int) env('WEBSOCKET_PORT', 8080)
        ]);
    } else {
        echo json_encode([
            'logged_in' => false
        ]);
    }
    exit;
}
if ($action === 'change_password') {
    $userId = requireUserId();
    $input = getJsonInput();

    $currentPassword = (string)($input['current_password'] ?? '');
    $newPassword = (string)($input['new_password'] ?? '');
    $newPasswordConfirm = (string)($input['new_password_confirm'] ?? '');

    if ($currentPassword === '' || $newPassword === '' || $newPasswordConfirm === '') {
        jsonError('Uzupełnij wszystkie pola.');
    }

    if (mb_strlen($newPassword) < 8) {
        jsonError('Nowe hasło musi mieć minimum 8 znaków.');
    }

    if (mb_strlen($newPassword) > 255) {
        jsonError('Nowe hasło jest za długie.');
    }

    if ($newPassword !== $newPasswordConfirm) {
        jsonError('Nowe hasła nie są takie same.');
    }

    if ($currentPassword === $newPassword) {
        jsonError('Nowe hasło musi różnić się od aktualnego.');
    }

    $stmt = $pdo->prepare("
        SELECT id, username, password
        FROM users
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->execute([$userId]);

    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        jsonError('Użytkownik nie istnieje.', 404);
    }

    if (!password_verify($currentPassword, $user['password'])) {
        jsonError('Aktualne hasło jest nieprawidłowe.');
    }

    $newHash = password_hash($newPassword, PASSWORD_DEFAULT);

    $stmt = $pdo->prepare("
        UPDATE users
        SET password = ?
        WHERE id = ?
    ");
    $stmt->execute([$newHash, $userId]);

    logActivity(
        $pdo,
        'password_changed',
        'Hasło zmienione',
        'Użytkownik zmienił hasło do konta.',
        $userId,
        'user',
        $userId,
        [],
        'admin',
        60
    );

    jsonSuccess([
        'message' => 'Hasło zostało zmienione.'
    ]);
}