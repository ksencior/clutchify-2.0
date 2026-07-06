<?php 
return function (PDO $pdo, array $db): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `match_ready_checks` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `match_id` BIGINT UNSIGNED NOT NULL,
            `user_id` INT UNSIGNED NOT NULL,
            `team_id` INT UNSIGNED NOT NULL,
            `is_ready` TINYINT(1) NOT NULL DEFAULT 0,
            `ready_at` TIMESTAMP NULL DEFAULT NULL,
            `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_match_ready_user` (`match_id`, `user_id`),
            KEY `idx_match_ready_match` (`match_id`),
            KEY `idx_match_ready_team` (`match_id`, `team_id`),
            KEY `idx_match_ready_user` (`user_id`)
        ) ENGINE=InnoDB
        DEFAULT CHARSET=utf8mb4
        COLLATE=utf8mb4_unicode_ci
    ");
};