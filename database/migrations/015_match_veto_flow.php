<?php

return function (PDO $pdo, array $db): void {
    if ($db['tableExists']('tournament_matches')) {
        $db['addColumnIfMissing'](
            'tournament_matches',
            'match_format',
            "ENUM('bo1','bo3','bo5') NOT NULL DEFAULT 'bo1' AFTER `match_number`"
        );

        $db['addColumnIfMissing'](
            'tournament_matches',
            'veto_status',
            "ENUM('not_started','active','completed') NOT NULL DEFAULT 'not_started' AFTER `match_format`"
        );

        $db['addColumnIfMissing'](
            'tournament_matches',
            'veto_started_at',
            'TIMESTAMP NULL DEFAULT NULL AFTER `veto_status`'
        );

        $db['addColumnIfMissing'](
            'tournament_matches',
            'veto_turn_started_at',
            'TIMESTAMP NULL DEFAULT NULL AFTER `veto_started_at`'
        );

        $db['addColumnIfMissing'](
            'tournament_matches',
            'veto_completed_at',
            'TIMESTAMP NULL DEFAULT NULL AFTER `veto_turn_started_at`'
        );
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `match_map_veto` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `match_id` BIGINT UNSIGNED NOT NULL,
            `step_number` INT UNSIGNED NOT NULL,
            `action` ENUM('ban','pick','side','decider') NOT NULL,
            `actor_team_id` INT UNSIGNED NULL,
            `map_name` VARCHAR(64) NOT NULL,
            `side_choice` ENUM('ct','t') NULL,
            `is_auto` TINYINT(1) NOT NULL DEFAULT 0,
            `created_by` BIGINT UNSIGNED NULL,
            `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,

            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_match_veto_step` (`match_id`, `step_number`),
            KEY `idx_match_veto_match` (`match_id`),
            KEY `idx_match_veto_action` (`match_id`, `action`)
        ) ENGINE=InnoDB
        DEFAULT CHARSET=utf8mb4
        COLLATE=utf8mb4_unicode_ci
    ");

    if ($db['tableExists']('match_map_veto')) {
        $db['addColumnIfMissing'](
            'match_map_veto',
            'is_auto',
            'TINYINT(1) NOT NULL DEFAULT 0 AFTER `side_choice`'
        );
    }
};