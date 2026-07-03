<?php
$host = '127.0.0.1';
$db   = 'v2_clutchify';
$user = 'root';
$pass = '';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // Wyświetlaj błędy SQL jako wyjątki
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,       // Zwracaj dane jako tablice asocjacyjne
    PDO::ATTR_EMULATE_PREPARES   => false,                  // Wyłącz emulację prepared statements (bezpieczeństwo!)
];

try {
     $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
     throw new \PDOException($e->getMessage(), (int)$e->getCode());
}