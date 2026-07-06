<?php

return function (PDO $pdo, array $db): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `tournament_teams` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `tournament_id` INT UNSIGNED NOT NULL,
            `team_id` INT UNSIGNED NOT NULL,
            `registered_by` BIGINT UNSIGNED NOT NULL,

            `status` ENUM('pending','approved','rejected','left') NOT NULL DEFAULT 'pending',
            `verification_note` VARCHAR(500) NULL,
            `admin_note` VARCHAR(500) NULL,
            `reviewed_by` BIGINT UNSIGNED NULL,
            `reviewed_at` TIMESTAMP NULL DEFAULT NULL,

            `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_tournament_team` (`tournament_id`, `team_id`),
            KEY `idx_tournament_teams_status` (`status`),
            KEY `idx_tournament_teams_team` (`team_id`),
            KEY `idx_tournament_teams_registered_by` (`registered_by`)
        ) ENGINE=InnoDB
        DEFAULT CHARSET=utf8mb4
        COLLATE=utf8mb4_unicode_ci
    ");

    $db['addColumnIfMissing']('tournament_teams', 'verification_note', "VARCHAR(500) NULL AFTER `status`");
    $db['addColumnIfMissing']('tournament_teams', 'admin_note', "VARCHAR(500) NULL AFTER `verification_note`");
    $db['addColumnIfMissing']('tournament_teams', 'reviewed_by', "BIGINT UNSIGNED NULL AFTER `admin_note`");
    $db['addColumnIfMissing']('tournament_teams', 'reviewed_at', "TIMESTAMP NULL DEFAULT NULL AFTER `reviewed_by`");
    $db['addColumnIfMissing']('tournament_teams', 'created_at', "TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP AFTER `reviewed_at`");
    $db['addColumnIfMissing']('tournament_teams', 'updated_at', "TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`");

    $db['addIndexIfMissing']('tournament_teams', 'uniq_tournament_team', "UNIQUE KEY `uniq_tournament_team` (`tournament_id`, `team_id`)");
    $db['addIndexIfMissing']('tournament_teams', 'idx_tournament_teams_status', "KEY `idx_tournament_teams_status` (`status`)");
};