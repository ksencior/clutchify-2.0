<?php

require_once __DIR__ . '/config/env.php';

$host = env('DB_HOST', '127.0.0.1');
$db = env('DB_NAME', 'v2_clutchify');
$user = env('DB_USER', 'root');
$pass = env('DB_PASS', '');
$charset = env('DB_CHARSET', 'utf8mb4');

$dsn = "mysql:host={$host};dbname={$db};charset={$charset}";

$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    http_response_code(500);

    if (env('APP_DEBUG', 'false') === 'true') {
        throw new PDOException($e->getMessage(), (int)$e->getCode());
    }

    die('Błąd połączenia z bazą danych.');
}