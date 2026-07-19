<?php

return function (PDO $pdo, array $db): void {
    if (!$db['tableExists']('tournament_matches')) {
        return;
    }

    $db['addColumnIfMissing'](
        'tournament_matches',
        'match_settings_json',
        'JSON NULL AFTER `match_format`'
    );

    $db['addColumnIfMissing'](
        'tournament_matches',
        'matchzy_config_token',
        'VARCHAR(128) NULL AFTER `match_settings_json`'
    );

    $db['addColumnIfMissing'](
        'tournament_matches',
        'matchzy_config_url',
        'TEXT NULL AFTER `matchzy_config_token`'
    );

    $db['addColumnIfMissing'](
        'tournament_matches',
        'matchzy_loaded_at',
        'TIMESTAMP NULL DEFAULT NULL AFTER `matchzy_config_url`'
    );

    $db['addColumnIfMissing'](
        'tournament_matches',
        'matchzy_load_error',
        'TEXT NULL AFTER `matchzy_loaded_at`'
    );

    $db['addIndexIfMissing'](
        'tournament_matches',
        'idx_tournament_matches_matchzy_token',
        'KEY `idx_tournament_matches_matchzy_token` (`matchzy_config_token`)'
    );
};