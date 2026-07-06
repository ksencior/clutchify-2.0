<?php

return function (PDO $pdo, array $db): void {
    $db['addColumnIfMissing']('players', 'bio', "VARCHAR(500) NULL AFTER `avatar`");

    $db['addColumnIfMissing'](
        'players',
        'preferred_role',
        "ENUM('unknown','entry','rifler','awper','igl','lurker','support') NOT NULL DEFAULT 'unknown' AFTER `bio`"
    );

    $db['addColumnIfMissing']('players', 'faceit_level', "TINYINT UNSIGNED NULL AFTER `preferred_role`");
    $db['addColumnIfMissing']('players', 'region', "VARCHAR(40) NULL DEFAULT 'EU' AFTER `faceit_level`");
    $db['addColumnIfMissing']('players', 'school', "VARCHAR(120) NULL AFTER `region`");
    $db['addColumnIfMissing']('players', 'availability', "VARCHAR(255) NULL AFTER `school`");
};