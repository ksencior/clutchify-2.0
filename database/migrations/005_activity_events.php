<?php

return function (PDO $pdo, array $db): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `activity_events` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

            `actor_user_id` BIGINT UNSIGNED NULL,

            `type` VARCHAR(60) NOT NULL,
            `title` VARCHAR(120) NOT NULL,
            `message` VARCHAR(500) NOT NULL,

            `target_type` VARCHAR(60) NULL,
            `target_id` BIGINT UNSIGNED NULL,

            `metadata` LONGTEXT NULL,
            `visibility` ENUM('public', 'admin') NOT NULL DEFAULT 'public',

            `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,

            PRIMARY KEY (`id`),
            KEY `idx_activity_visibility_created` (`visibility`, `created_at`),
            KEY `idx_activity_actor` (`actor_user_id`),
            KEY `idx_activity_type` (`type`)
        ) ENGINE=InnoDB
        DEFAULT CHARSET=utf8mb4
        COLLATE=utf8mb4_unicode_ci
    ");
};