<?php

return function (PDO $pdo, array $db): void {
    if (!$db['tableExists']('practice_sessions')) {
        return;
    }

    $db['addColumnIfMissing'](
        'practice_sessions',
        'connect_password_encrypted',
        'TEXT NULL AFTER `map_name`'
    );

    if ($db['tableExists']('game_servers')) {
        $db['addColumnIfMissing'](
            'game_servers',
            'rotate_password_per_session',
            'TINYINT(1) NOT NULL DEFAULT 1 AFTER `connect_password`'
        );
    }
};