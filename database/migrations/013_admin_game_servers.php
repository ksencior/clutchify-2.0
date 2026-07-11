<?php

return function (PDO $pdo, array $db): void {
    if (!$db['tableExists']('game_servers')) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `game_servers` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `name` VARCHAR(120) NOT NULL,
                `purpose` ENUM('practice','match','both') NOT NULL DEFAULT 'both',

                `public_address` VARCHAR(190) NOT NULL,
                `connect_password` VARCHAR(120) NULL,
                `rotate_password_per_session` TINYINT(1) NOT NULL DEFAULT 1,

                `rcon_host` VARCHAR(190) NOT NULL DEFAULT '127.0.0.1',
                `rcon_port` INT UNSIGNED NOT NULL DEFAULT 27015,
                `rcon_password_env` VARCHAR(120) NULL,
                `rcon_password_encrypted` TEXT NULL,

                `is_enabled` TINYINT(1) NOT NULL DEFAULT 1,

                `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                PRIMARY KEY (`id`),
                KEY `idx_game_servers_enabled_purpose` (`is_enabled`, `purpose`)
            ) ENGINE=InnoDB
            DEFAULT CHARSET=utf8mb4
            COLLATE=utf8mb4_unicode_ci
        ");

        return;
    }

    $db['addColumnIfMissing'](
        'game_servers',
        'rotate_password_per_session',
        'TINYINT(1) NOT NULL DEFAULT 1 AFTER `connect_password`'
    );

    $db['addColumnIfMissing'](
        'game_servers',
        'rcon_password_encrypted',
        'TEXT NULL AFTER `rcon_password_env`'
    );

    if ($db['columnExists']('game_servers', 'rcon_password_env')) {
        $db['modifyColumn'](
            'game_servers',
            'rcon_password_env',
            'VARCHAR(120) NULL'
        );
    }
};