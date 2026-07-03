<?php

class User {
    private $db;

    public $id;
    public $username;
    public $email;
    public $playerProfile = null;

    public function __construct(PDO $pdo, int $userId)
    {
        $this->db = $pdo;
        $this->loadUserData($userId);
    }

    private function loadUserData(int $userId): void {
        $sql = "SELECT u.id, u.username, u.email, p.id as player_id, p.team_id, t.name as team_name, p.avatar, p.is_substitute, p.isAdmin 
                FROM users u 
                LEFT JOIN players p ON u.id = p.user_id
                JOIN teams t ON p.team_id = t.id
                WHERE u.id = ?";
                
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$userId]);
        $data = $stmt->fetch();

        if ($data) {
            $this->id = (int)$data['id'];
            $this->username = $data['username'];
            $this->email = $data['email'];

            $this->playerProfile = [
                'player_id' => $data['player_id'],
                'team_id' => $data['team_id'],
                'team_name' => $data['team_name'],
                'avatar' => $data['avatar'],
                'is_substitute' => (bool)$data['is_substitute'],
                'isAdmin' => (bool)$data['isAdmin']
            ];
        }
    }

    public function toArray(): array {
        return [
            'id' => $this->id,
            'username' => $this->username,
            'email' => $this->email,
            'player' => $this->playerProfile
        ];
    }
}