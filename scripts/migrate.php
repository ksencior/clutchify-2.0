<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/env.php';

$host = env('DB_HOST', '127.0.0.1');
$port = env('DB_PORT', '3306');
$dbName = env('DB_NAME', 'v2_clutchify');
$user = env('DB_USER', 'root');
$pass = env('DB_PASS', '');
$charset = env('DB_CHARSET', 'utf8mb4');

$dsn = "mysql:host={$host};port={$port};dbname={$dbName};charset={$charset}";

$pdo = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);

$pdo->exec("
    CREATE TABLE IF NOT EXISTS `schema_migrations` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `migration` VARCHAR(190) NOT NULL,
        `batch` INT UNSIGNED NOT NULL DEFAULT 1,
        `executed_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,

        PRIMARY KEY (`id`),
        UNIQUE KEY `uniq_schema_migration` (`migration`)
    ) ENGINE=InnoDB
    DEFAULT CHARSET=utf8mb4
    COLLATE=utf8mb4_unicode_ci
");

function migrationIdentifier(string $name): string {
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $name)) {
        throw new RuntimeException("Nieprawidłowy identyfikator: {$name}");
    }

    return "`{$name}`";
}

$helpers = [];

$helpers['tableExists'] = function (string $table) use ($pdo): bool {
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
    ");
    $stmt->execute([$table]);

    return (int)$stmt->fetchColumn() > 0;
};

$helpers['columnExists'] = function (string $table, string $column) use ($pdo): bool {
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
    ");
    $stmt->execute([$table, $column]);

    return (int)$stmt->fetchColumn() > 0;
};

$helpers['indexExists'] = function (string $table, string $index) use ($pdo): bool {
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND INDEX_NAME = ?
    ");
    $stmt->execute([$table, $index]);

    return (int)$stmt->fetchColumn() > 0;
};

$helpers['addColumnIfMissing'] = function (
    string $table,
    string $column,
    string $definition
) use ($pdo, &$helpers): void {
    if ($helpers['columnExists']($table, $column)) {
        return;
    }

    $pdo->exec(
        "ALTER TABLE "
        . migrationIdentifier($table)
        . " ADD COLUMN "
        . migrationIdentifier($column)
        . " {$definition}"
    );
};

$helpers['modifyColumn'] = function (
    string $table,
    string $column,
    string $definition
) use ($pdo): void {
    $pdo->exec(
        "ALTER TABLE "
        . migrationIdentifier($table)
        . " MODIFY COLUMN "
        . migrationIdentifier($column)
        . " {$definition}"
    );
};

$helpers['addIndexIfMissing'] = function (
    string $table,
    string $index,
    string $definition
) use ($pdo, &$helpers): void {
    if ($helpers['indexExists']($table, $index)) {
        return;
    }

    $pdo->exec(
        "ALTER TABLE "
        . migrationIdentifier($table)
        . " ADD {$definition}"
    );
};

$stmt = $pdo->query("SELECT migration FROM schema_migrations");
$applied = array_flip($stmt->fetchAll(PDO::FETCH_COLUMN));

$currentBatch = (int)$pdo->query("SELECT COALESCE(MAX(batch), 0) FROM schema_migrations")->fetchColumn();
$nextBatch = $currentBatch + 1;

$migrationFiles = glob(__DIR__ . '/../database/migrations/*.php');
sort($migrationFiles);

if (!$migrationFiles) {
    echo "Brak migracji.\n";
    exit(0);
}

$ran = 0;

foreach ($migrationFiles as $file) {
    $migrationName = basename($file, '.php');

    if (isset($applied[$migrationName])) {
        echo "Pominięto: {$migrationName}\n";
        continue;
    }

    echo "Uruchamiam: {$migrationName}\n";

    $migration = require $file;

    if (!is_callable($migration)) {
        throw new RuntimeException("Migracja {$migrationName} nie zwraca funkcji.");
    }

    try {
        /**
         * Uwaga:
         * MySQL robi implicit commit przy DDL, np. CREATE TABLE / ALTER TABLE.
         * Dlatego nie używamy tutaj transakcji.
         */
        $migration($pdo, $helpers);

        $stmt = $pdo->prepare("
            INSERT INTO schema_migrations (migration, batch)
            VALUES (?, ?)
        ");
        $stmt->execute([$migrationName, $nextBatch]);

        echo "OK: {$migrationName}\n";
        $ran++;
    } catch (Throwable $e) {
        echo "BŁĄD: {$migrationName}\n";
        echo $e->getMessage() . "\n";

        exit(1);
    }
}

echo "\nGotowe. Uruchomiono migracji: {$ran}\n";