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
            $stmt = $pdo->query("SELECT id, name, tag, logo FROM teams ORDER BY id DESC");
            $teams = $stmt->fetchAll();
            
            echo json_encode($teams);
        } catch (PDOException $e) {
            echo json_encode(['error' => 'Błąd bazy danych: ' . $e->getMessage()]);
        }
        break;

    default:
        echo json_encode(['message' => 'Nieznana akcja API']);
        break;
}