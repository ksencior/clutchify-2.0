<?php

if ($action === 'register') {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!empty($data['username']) && !empty($data['email']) && !empty($data['password'])) {
        $result = $auth->register($data['username'], $data['email'], $data['password']);
        echo json_encode($result);
    } else {
        echo json_encode(['success' => false, 'message' => 'Wszystkie pola są wymagane.']);
    }
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