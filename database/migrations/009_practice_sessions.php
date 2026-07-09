<?php

return function (PDO $pdo, array $db): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `practice_sessions` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` BIGINT UNSIGNED NOT NULL,
            `map_name` VARCHAR(64) NOT NULL,
            `status` ENUM('active','ended','expired') NOT NULL DEFAULT 'active',
            `started_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            `ended_at` TIMESTAMP NULL DEFAULT NULL,
            `last_action_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,

            PRIMARY KEY (`id`),
            KEY `idx_practice_user_status` (`user_id`, `status`),
            KEY `idx_practice_status_started` (`status`, `started_at`)
        ) ENGINE=InnoDB
        DEFAULT CHARSET=utf8mb4
        COLLATE=utf8mb4_unicode_ci
    ");
};