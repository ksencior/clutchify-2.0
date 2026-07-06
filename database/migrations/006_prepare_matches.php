<?php

return function (PDO $pdo, array $db): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `tournament_matches` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

            `tournament_id` INT UNSIGNED NOT NULL,

            `round_number` INT UNSIGNED NOT NULL DEFAULT 1,
            `match_number` INT UNSIGNED NOT NULL DEFAULT 1,

            `team_a_id` INT UNSIGNED NULL,
            `team_b_id` INT UNSIGNED NULL,
            `winner_team_id` INT UNSIGNED NULL,

            `status` ENUM('pending','ready_check','live','finished','cancelled') NOT NULL DEFAULT 'pending',

            `scheduled_at` TIMESTAMP NULL DEFAULT NULL,
            `started_at` TIMESTAMP NULL DEFAULT NULL,
            `finished_at` TIMESTAMP NULL DEFAULT NULL,

            `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

            PRIMARY KEY (`id`),
            KEY `idx_tournament_matches_tournament` (`tournament_id`),
            KEY `idx_tournament_matches_round` (`tournament_id`, `round_number`),
            KEY `idx_tournament_matches_status` (`status`)
        ) ENGINE=InnoDB
        DEFAULT CHARSET=utf8mb4
        COLLATE=utf8mb4_unicode_ci
    ");
};