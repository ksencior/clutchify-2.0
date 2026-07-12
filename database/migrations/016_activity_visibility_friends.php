<?php

return function (PDO $pdo, array $db): void {
    if (!$db['tableExists']('activity_events')) {
        return;
    }

    $db['modifyColumn'](
        'activity_events',
        'visibility',
        "ENUM('public','friends','admin') NOT NULL DEFAULT 'public'"
    );

    $db['addIndexIfMissing'](
        'friendships',
        'idx_friendships_status_pair',
        'KEY `idx_friendships_status_pair` (`status`, `requester_id`, `addressee_id`)'
    );
};