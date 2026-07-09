<?php

return function (PDO $pdo, array $db): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `game_servers` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name` VARCHAR(120) NOT NULL,
            `purpose` ENUM('practice','match','both') NOT NULL DEFAULT 'both',

            `public_address` VARCHAR(190) NOT NULL,
            `connect_password` VARCHAR(120) NULL,

            `rcon_host` VARCHAR(190) NOT NULL DEFAULT '127.0.0.1',
            `rcon_port` INT UNSIGNED NOT NULL DEFAULT 27015,
            `rcon_password_env` VARCHAR(120) NOT NULL,

            `is_enabled` TINYINT(1) NOT NULL DEFAULT 1,

            `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

            PRIMARY KEY (`id`),
            KEY `idx_game_servers_enabled_purpose` (`is_enabled`, `purpose`)
        ) ENGINE=InnoDB
        DEFAULT CHARSET=utf8mb4
        COLLATE=utf8mb4_unicode_ci
    ");

    if ($db['tableExists']('practice_sessions')) {
        $db['addColumnIfMissing'](
            'practice_sessions',
            'game_server_id',
            'INT UNSIGNED NULL AFTER user_id'
        );

        $db['addIndexIfMissing'](
            'practice_sessions',
            'idx_practice_server_status',
            'KEY `idx_practice_server_status` (`game_server_id`, `status`)'
        );
    }

    if ($db['tableExists']('tournament_matches')) {
        $db['addColumnIfMissing'](
            'tournament_matches',
            'game_server_id',
            'INT UNSIGNED NULL AFTER tournament_id'
        );

        $db['addColumnIfMissing'](
            'tournament_matches',
            'server_assigned_at',
            'TIMESTAMP NULL DEFAULT NULL AFTER game_server_id'
        );

        $db['addIndexIfMissing'](
            'tournament_matches',
            'idx_tournament_matches_server',
            'KEY `idx_tournament_matches_server` (`game_server_id`, `status`)'
        );
    }

    /**
     * Seed pierwszego serwera z obecnych zmiennych PRACTICE_*,
     * żeby nie stracić działającej konfiguracji.
     */
    $existing = (int)$pdo->query("SELECT COUNT(*) FROM game_servers")->fetchColumn();

    if ($existing === 0 && env('PRACTICE_RCON_PASSWORD', '') !== '') {
        $stmt = $pdo->prepare("
            INSERT INTO game_servers (
                name,
                purpose,
                public_address,
                connect_password,
                rcon_host,
                rcon_port,
                rcon_password_env,
                is_enabled
            )
            VALUES (?, 'practice', ?, ?, ?, ?, 'PRACTICE_RCON_PASSWORD', 1)
        ");

        $public = env('PRACTICE_SERVER_PUBLIC', '');

        if ($public === '') {
            $public = env('PRACTICE_SERVER_HOST', '127.0.0.1')
                . ':'
                . env('PRACTICE_SERVER_PORT', '27015');
        }

        $stmt->execute([
            env('PRACTICE_SERVER_NAME', 'Practice Server #1'),
            $public,
            env('PRACTICE_SERVER_PASSWORD', '') ?: null,
            env('PRACTICE_RCON_HOST', '127.0.0.1'),
            (int)env('PRACTICE_RCON_PORT', '27015')
        ]);
    }
};