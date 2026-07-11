<?php

return function (PDO $pdo, array $db): void {
    if ($db['tableExists']('tournament_matches')) {
        $db['addColumnIfMissing'](
            'tournament_matches',
            'match_format',
            "ENUM('bo1','bo3','bo5') NOT NULL DEFAULT 'bo1' AFTER `match_number`"
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
};