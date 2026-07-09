<?php

return function (PDO $pdo, array $db): void {
    if (!$db['tableExists']('game_servers')) {
        return;
    }

    $db['addColumnIfMissing'](
        'game_servers',
        'rcon_password_encrypted',
        'TEXT NULL AFTER `rcon_password_env`'
    );

    /**
     * Pozwalamy na NULL, bo nowe serwery mogą mieć hasło tylko w DB,
     * a stare dalej mogą używać rcon_password_env.
     */
    $db['modifyColumn'](
        'game_servers',
        'rcon_password_env',
        'VARCHAR(120) NULL'
    );
};