<?php

require_once __DIR__ . '/bootstrap/api_bootstrap.php';

if ($action === '') {
    jsonError('Brak akcji API.', 400);
}

if (in_array($action, postOnlyActions(), true)) {
    requirePostMethod();
}

if (in_array($action, csrfProtectedActions(), true)) {
    validateCsrfToken();
}

require_once __DIR__ . '/routes/auth.php';
require_once __DIR__ . '/routes/setup.php';
require_once __DIR__ . '/routes/profile.php';
require_once __DIR__ . '/routes/players.php';
require_once __DIR__ . '/routes/teams.php';
require_once __DIR__ . '/routes/friends.php';
require_once __DIR__ . '/routes/chat.php';
require_once __DIR__ . '/routes/notifications.php';
require_once __DIR__ . '/routes/admin.php';
require_once __DIR__ . '/routes/tournaments.php';
require_once __DIR__ . '/routes/activity.php';
require_once __DIR__ . '/routes/matches.php';
require_once __DIR__ . '/routes/practice.php';

jsonError('Nieznana akcja API: ' . $action, 404);