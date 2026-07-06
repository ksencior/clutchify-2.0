<?php

return function (PDO $pdo, array $db): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `users` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `username` VARCHAR(255) NOT NULL,
            `email` VARCHAR(255) NOT NULL,
            `password` VARCHAR(255) NOT NULL,
            `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,

            PRIMARY KEY (`id`),
            UNIQUE KEY `users_username_unique` (`username`),
            UNIQUE KEY `users_email_unique` (`email`)
        ) ENGINE=InnoDB
        DEFAULT CHARSET=utf8mb4
        COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `teams` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name` VARCHAR(100) NOT NULL,
            `tag` VARCHAR(5) NOT NULL,
            `logo` VARCHAR(255) NULL DEFAULT 'https://api.dicebear.com/7.x/identicon/svg?seed=Clutch',
            `is_open` TINYINT(1) NOT NULL DEFAULT 1,
            `captain_id` BIGINT UNSIGNED NOT NULL,
            `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,

            PRIMARY KEY (`id`),
            UNIQUE KEY `teams_name_unique` (`name`),
            UNIQUE KEY `teams_tag_unique` (`tag`),
            KEY `idx_teams_captain` (`captain_id`)
        ) ENGINE=InnoDB
        DEFAULT CHARSET=utf8mb4
        COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `players` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` BIGINT UNSIGNED NOT NULL,
            `team_id` INT UNSIGNED NULL,
            `is_substitute` TINYINT(1) NOT NULL DEFAULT 0,
            `steam_id` VARCHAR(255) NULL,
            `avatar` VARCHAR(255) NULL,
            `discord_id` VARCHAR(255) NULL,
            `isAdmin` TINYINT(1) NOT NULL DEFAULT 0,
            `isSpectator` TINYINT(1) NOT NULL DEFAULT 0,
            `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

            PRIMARY KEY (`id`),
            UNIQUE KEY `players_user_id_unique` (`user_id`),
            KEY `idx_players_team` (`team_id`)
        ) ENGINE=InnoDB
        DEFAULT CHARSET=utf8mb4
        COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `notifications` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` BIGINT UNSIGNED NOT NULL,
            `type` VARCHAR(50) NOT NULL,
            `reference_id` BIGINT UNSIGNED NULL,
            `message` TEXT NOT NULL,
            `status` ENUM('pending','accepted','rejected','seen') NOT NULL DEFAULT 'pending',
            `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,

            PRIMARY KEY (`id`),
            KEY `idx_notifications_user_status` (`user_id`, `status`),
            KEY `idx_notifications_type_reference` (`type`, `reference_id`)
        ) ENGINE=InnoDB
        DEFAULT CHARSET=utf8mb4
        COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `tournaments` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `join_code` VARCHAR(16) NULL,
            `is_open` TINYINT(1) NOT NULL DEFAULT 0,
            `status` ENUM(
                'registration_open',
                'registration_closed',
                'in_progress',
                'finished',
                'cancelled'
            ) NOT NULL DEFAULT 'registration_open',
            `creator` VARCHAR(128) NULL,
            `title` VARCHAR(255) NOT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `sign_in_end` TIMESTAMP NULL DEFAULT NULL,
            `starts_at` TIMESTAMP NULL DEFAULT NULL,

            PRIMARY KEY (`id`),
            UNIQUE KEY `tournaments_join_code_unique` (`join_code`),
            KEY `idx_tournaments_status` (`status`),
            KEY `idx_tournaments_is_open` (`is_open`)
        ) ENGINE=InnoDB
        DEFAULT CHARSET=utf8mb4
        COLLATE=utf8mb4_unicode_ci
    ");

    /**
     * Compat dla starszej bazy, która już istnieje.
     */
    $db['addColumnIfMissing']('tournaments', 'status', "ENUM('registration_open','registration_closed','in_progress','finished','cancelled') NOT NULL DEFAULT 'registration_open' AFTER `is_open`");
    $db['addColumnIfMissing']('tournaments', 'join_code', "VARCHAR(16) NULL AFTER `id`");
    $db['addColumnIfMissing']('tournaments', 'is_open', "TINYINT(1) NOT NULL DEFAULT 0 AFTER `join_code`");

    if ($db['columnExists']('notifications', 'status')) {
        $db['modifyColumn']('notifications', 'status', "ENUM('pending','accepted','rejected','seen') NOT NULL DEFAULT 'pending'");
    }

    $db['addIndexIfMissing']('notifications', 'idx_notifications_user_status', "KEY `idx_notifications_user_status` (`user_id`, `status`)");
    $db['addIndexIfMissing']('notifications', 'idx_notifications_type_reference', "KEY `idx_notifications_type_reference` (`type`, `reference_id`)");
};