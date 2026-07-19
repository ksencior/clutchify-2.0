<?php

return function (PDO $pdo, array $db): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `scrim_posts` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `team_id` INT UNSIGNED NOT NULL,
            `created_by` BIGINT UNSIGNED NOT NULL,

            `title` VARCHAR(140) NOT NULL,
            `description` TEXT NULL,

            `status` ENUM('active','matched','closed','cancelled') NOT NULL DEFAULT 'active',

            `match_format` ENUM('bo1','bo3','bo5') NOT NULL DEFAULT 'bo1',
            `friendly_fire` TINYINT(1) NOT NULL DEFAULT 0,
            `overtime_enabled` TINYINT(1) NOT NULL DEFAULT 1,
            `knife_round` TINYINT(1) NOT NULL DEFAULT 1,
            `mr` TINYINT UNSIGNED NOT NULL DEFAULT 12,

            `scheduled_for` TIMESTAMP NULL DEFAULT NULL,
            `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

            PRIMARY KEY (`id`),
            KEY `idx_scrim_posts_team_status` (`team_id`, `status`),
            KEY `idx_scrim_posts_status_created` (`status`, `created_at`)
        ) ENGINE=InnoDB
        DEFAULT CHARSET=utf8mb4
        COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `scrim_offers` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `scrim_post_id` BIGINT UNSIGNED NOT NULL,
            `challenger_team_id` INT UNSIGNED NOT NULL,
            `created_by` BIGINT UNSIGNED NOT NULL,

            `message` TEXT NULL,
            `status` ENUM('pending','accepted','rejected','cancelled') NOT NULL DEFAULT 'pending',

            `match_id` BIGINT UNSIGNED NULL,
            `reviewed_by` BIGINT UNSIGNED NULL,
            `reviewed_at` TIMESTAMP NULL DEFAULT NULL,
            `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,

            PRIMARY KEY (`id`),
            KEY `idx_scrim_offers_post_status` (`scrim_post_id`, `status`),
            KEY `idx_scrim_offers_team_status` (`challenger_team_id`, `status`),
            KEY `idx_scrim_offers_match` (`match_id`)
        ) ENGINE=InnoDB
        DEFAULT CHARSET=utf8mb4
        COLLATE=utf8mb4_unicode_ci
    ");

    if ($db['tableExists']('tournament_matches')) {
        $db['addColumnIfMissing'](
            'tournament_matches',
            'match_source',
            "ENUM('tournament','scrim') NOT NULL DEFAULT 'tournament' AFTER `tournament_id`"
        );

        $db['addColumnIfMissing'](
            'tournament_matches',
            'scrim_post_id',
            'BIGINT UNSIGNED NULL AFTER `match_source`'
        );

        $db['addColumnIfMissing'](
            'tournament_matches',
            'scrim_offer_id',
            'BIGINT UNSIGNED NULL AFTER `scrim_post_id`'
        );

        $db['addColumnIfMissing'](
            'tournament_matches',
            'match_settings_json',
            'JSON NULL AFTER `match_format`'
        );

        $db['addIndexIfMissing'](
            'tournament_matches',
            'idx_tournament_matches_scrim',
            'KEY `idx_tournament_matches_scrim` (`match_source`, `scrim_post_id`, `scrim_offer_id`)'
        );
    }
};