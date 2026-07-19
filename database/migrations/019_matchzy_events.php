<?php

return function (PDO $pdo, array $db): void {
    if ($db['tableExists']('tournament_matches')) {
        $db['modifyColumn'](
            'tournament_matches',
            'status',
            "ENUM('pending','ready_check','server_ready','live','finished','cancelled') NOT NULL DEFAULT 'pending'"
        );

        $db['addColumnIfMissing'](
            'tournament_matches',
            'matchzy_event_token',
            'VARCHAR(128) NULL AFTER `matchzy_config_token`'
        );

        $db['addColumnIfMissing'](
            'tournament_matches',
            'matchzy_event_url',
            'TEXT NULL AFTER `matchzy_config_url`'
        );

        $db['addColumnIfMissing'](
            'tournament_matches',
            'connect_password_encrypted',
            'TEXT NULL AFTER `match_settings_json`'
        );

        $db['addColumnIfMissing'](
            'tournament_matches',
            'server_ready_at',
            'TIMESTAMP NULL DEFAULT NULL AFTER `server_assigned_at`'
        );

        $db['addColumnIfMissing'](
            'tournament_matches',
            'join_deadline_at',
            'TIMESTAMP NULL DEFAULT NULL AFTER `server_ready_at`'
        );

        $db['addColumnIfMissing'](
            'tournament_matches',
            'matchzy_series_started_at',
            'TIMESTAMP NULL DEFAULT NULL AFTER `matchzy_loaded_at`'
        );

        $db['addColumnIfMissing'](
            'tournament_matches',
            'matchzy_going_live_at',
            'TIMESTAMP NULL DEFAULT NULL AFTER `matchzy_series_started_at`'
        );

        $db['addColumnIfMissing'](
            'tournament_matches',
            'team_a_score',
            'INT UNSIGNED NOT NULL DEFAULT 0 AFTER `winner_team_id`'
        );

        $db['addColumnIfMissing'](
            'tournament_matches',
            'team_b_score',
            'INT UNSIGNED NOT NULL DEFAULT 0 AFTER `team_a_score`'
        );

        $db['addIndexIfMissing'](
            'tournament_matches',
            'idx_tournament_matches_matchzy_event_token',
            'KEY `idx_tournament_matches_matchzy_event_token` (`matchzy_event_token`)'
        );
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `matchzy_events` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `match_id` BIGINT UNSIGNED NULL,
            `event_name` VARCHAR(80) NOT NULL,
            `map_number` INT NULL,
            `payload_json` JSON NOT NULL,
            `processed_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,

            PRIMARY KEY (`id`),
            KEY `idx_matchzy_events_match` (`match_id`, `processed_at`),
            KEY `idx_matchzy_events_event` (`event_name`, `processed_at`)
        ) ENGINE=InnoDB
        DEFAULT CHARSET=utf8mb4
        COLLATE=utf8mb4_unicode_ci
    ");
};