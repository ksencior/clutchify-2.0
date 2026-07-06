<?php
require 'vendor/autoload.php';
require_once __DIR__ . '/helpers/ws_auth.php';
require_once __DIR__ . '/config/env.php';

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use React\Socket\SocketServer;
use React\EventLoop\Factory;

$WEBSOCKET_HOST =       env('WEBSOCKET_HOST', '0.0.0.0');
$WEBSOCKET_PORT = (int) env('WEBSOCKET_PORT', '8080');

class NotificationServer implements MessageComponentInterface {
    protected $clients;
    protected $users = []; // resourceId => userId
    protected array $userNames;
    protected $connectionsByUser = []; // userId => connection count

    public function __construct()
    {
        $this->clients = new \SplObjectStorage;
        $this->users = [];
        $this->userNames = [];
    }

    public function onOpen(ConnectionInterface $conn)
    {
        $this->clients->attach($conn);
        echo "Nowe połączenie: ({$conn->resourceId})\n";
    }

    public function onMessage(ConnectionInterface $from, $msg) {
        $data = json_decode($msg);

        if (!$data || !isset($data->type)) {
            return;
        }

        // Kiedy gracz otwiera stronę, wysyła swoje ID, żebyśmy wiedzieli kto to
        if ($data->type === 'auth') {
            if (empty($data->token) || !is_string($data->token)) {
                $from->send(json_encode([
                    'action' => 'auth_failed',
                    'message' => 'Brak tokena WebSocket.'
                ]));

                $from->close();
                return;
            }

            try {
                $payload = verify_ws_token($data->token);
            } catch (Throwable $e) {
                $payload = null;
            }

            if (!$payload) {
                $from->send(json_encode([
                    'action' => 'auth_failed',
                    'message' => 'Nieprawidłowy token WebSocket.'
                ]));

                $from->close();
                return;
            }

            $userId = (int)$payload['uid'];
            $username = (string)($payload['username'] ?? 'Znajomy');

            $this->users[$from->resourceId] = $userId;
            $this->userNames[$from->resourceId] = $username;

            $this->broadcastStatus($userId, 'online');

            $from->send(json_encode([
                'action' => 'auth_ok',
                'userId' => $userId
            ]));

            $from->send(json_encode([
                'action' => 'initial_status_list',
                'users' => array_values(array_unique(array_map('strval', array_values($this->users))))
            ]));

            return;
        }

        if (!isset($this->users[$from->resourceId])) {
            $from->send(json_encode([
                'action' => 'auth_required',
                'message' => 'Najpierw musisz uwierzytelnić połączenie WebSocket.'
            ]));

            $from->close();
            return;
        }
        
        // Kiedy ktoś kogoś zaprasza/zaczepia
        if ($data->type === 'notify') {
            if (!isset($data->targetId) || !is_numeric($data->targetId)) {
                return;
            }

            $this->sendToUser((int)$data->targetId, [
                'action' => 'fetch_notifications'
            ]);

            return;
        }

        if ($data->type === 'chat_message') {
            if (!isset($data->targetId) || !is_numeric($data->targetId)) {
                return;
            }

            $senderId = $this->users[$from->resourceId];
            $senderUsername = $this->userNames[$from->resourceId] ?? 'Znajomy';

            $this->sendToUser((int)$data->targetId, [
                'action' => 'fetch_chat',
                'fromId' => $senderId,
                'fromUsername' => $senderUsername
            ]);

            return;
        }

        if ($data->type === 'request_status') {
            $onlineUsers = array_keys(array_filter($this->connectionsByUser, fn($count) => $count > 0));
            $from->send(json_encode([
                'action' => 'initial_status_list',
                'users' => $onlineUsers
            ]));
        }

        if (($data->type ?? null) === 'match_lobby_update') {
            $matchId = isset($data->matchId) && is_numeric($data->matchId)
                ? (int)$data->matchId
                : 0;

            $targetIds = [];

            if (isset($data->targetIds) && is_array($data->targetIds)) {
                $targetIds = $data->targetIds;
            }

            foreach ($targetIds as $targetId) {
                if (!is_numeric($targetId)) {
                    continue;
                }

                $this->sendToUser((int)$targetId, [
                    'action' => 'match_lobby_update',
                    'match_id' => $matchId
                ]);
            }

            return;
        }
    }

    public function onClose(ConnectionInterface $conn) {
        $userId = $this->users[$conn->resourceId] ?? null;

        $this->clients->detach($conn);

        unset($this->users[$conn->resourceId]);
        unset($this->userNames[$conn->resourceId]);

        if ($userId === null) {
            echo "Connection {$conn->resourceId} has disconnected\n";
            return;
        }

        if (!in_array($userId, $this->users, true)) {
            $this->broadcastStatus($userId, 'offline');
        }

        echo "Connection {$conn->resourceId} for user {$userId} has disconnected\n";
    }

    private function sendToUser(int $targetId, array $payload): void {
        $encoded = json_encode($payload);

        foreach ($this->clients as $client) {
            if (isset($this->users[$client->resourceId]) && $this->users[$client->resourceId] === $targetId) {
                $client->send($encoded);
            }
        }
    }

    private function broadcastStatus(int $userId, string $status, ?ConnectionInterface $except = null) {
        $broadcastData = json_encode([
            'action' => 'user_status_change',
            'user_id' => $userId,
            'status' => $status
        ]);

        foreach ($this->clients as $client) {
            if ($except !== null && $client === $except) {
                continue;
            }

            $client->send($broadcastData);
        }
    }

    public function onError(ConnectionInterface $conn, \Exception $e) {
        $conn->close();
    }
}

$loop = Factory::create();

$socket = new SocketServer(
    "{$WEBSOCKET_HOST}:{$WEBSOCKET_PORT}",
    [],
    $loop
);

$server = new IoServer(
    new HttpServer(
        new WsServer(
            new NotificationServer()
        )
    ),
    $socket,
    $loop
);

echo "Serwer WebSocket uruchomiony na {$WEBSOCKET_HOST}:{$WEBSOCKET_PORT}...\n";

$server->run();