<?php
return function (PDO $pdo, array $db): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `team_join_requests` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `team_id` INT UNSIGNED NOT NULL,
            `requester_user_id` BIGINT UNSIGNED NOT NULL,
            `status` ENUM('pending','accepted','rejected','cancelled') NOT NULL DEFAULT 'pending',
            `reviewed_by` BIGINT UNSIGNED NULL,
            `reviewed_at` TIMESTAMP NULL DEFAULT NULL,
            `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,

            PRIMARY KEY (`id`),
            KEY `idx_team_join_requests_team_status` (`team_id`, `status`),
            KEY `idx_team_join_requests_user_status` (`requester_user_id`, `status`)
        ) ENGINE=InnoDB
        DEFAULT CHARSET=utf8mb4
        COLLATE=utf8mb4_unicode_ci
    ");
};