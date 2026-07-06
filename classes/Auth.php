<?php

class Auth {
    private $db;

    public function __construct(PDO $pdo) {
        $this->db = $pdo;
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    public function register(string $username, string $email, string $password): array {
        $username = normalizeUsername($username);
        $email = trim($email);

        if (!isValidUsername($username)) {
            return [
                'success' => false,
                'message' => usernameValidationMessage()
            ];
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return [
                'success' => false,
                'message' => 'Podaj prawidłowy adres e-mail.'
            ];
        }

        if (mb_strlen($password) < 8) {
            return [
                'success' => false,
                'message' => 'Hasło musi mieć minimum 8 znaków.'
            ];
        }

        if (mb_strlen($password) > 255) {
            return [
                'success' => false,
                'message' => 'Hasło jest za długie.'
            ];
        }

        $stmt = $this->db->prepare("
            SELECT id
            FROM users
            WHERE username = ? OR email = ?
            LIMIT 1
        ");
        $stmt->execute([$username, $email]);

        if ($stmt->fetch()) {
            return [
                'success' => false,
                'message' => 'Użytkownik z podanym nickiem / e-mailem już istnieje!'
            ];
        }

        $hashedPassword = password_hash($password, PASSWORD_BCRYPT);

        try {
            $this->db->beginTransaction();

            $stmtUser = $this->db->prepare("
                INSERT INTO users (username, email, password)
                VALUES (?, ?, ?)
            ");
            $stmtUser->execute([$username, $email, $hashedPassword]);

            $userID = $this->db->lastInsertId();

            $stmtPlayer = $this->db->prepare("
                INSERT INTO players (user_id)
                VALUES (?)
            ");
            $stmtPlayer->execute([$userID]);

            $this->db->commit();

            return [
                'success' => true,
                'message' => 'Rejestracja przebiegła pomyślnie.'
            ];
        } catch (PDOException $e) {
            $this->db->rollBack();

            return [
                'success' => false,
                'message' => 'Wystąpił błąd. Spróbuj ponownie później.'
            ];
        }
    }

    public function login(string $email, string $password) : array {
        $stmt = $this->db->prepare("SELECT id, password FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            return ['success' => true, 'message' => 'Zalogowano pomyślnie!'];
        }

        return ['success' => false, 'message' => 'Nieprawidłowy email lub hasło.'];
    }

    public function logout(): void {
        unset($_SESSION['user_id']);
        session_destroy();
    }

    public function isLoggedIn(): bool {
        return isset($_SESSION['user_id']);
    }

    public function getLoggedInUserId(): ?int {
        return $_SESSION['user_id'] ?? null;
    }
}