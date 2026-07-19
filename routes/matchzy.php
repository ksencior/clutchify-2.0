<?php

require_once __DIR__ . '/../helpers/matchzy.php';

function matchzyRequestHeader(string $headerName): string {
    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $headerName));

    return trim((string)($_SERVER[$key] ?? ''));
}

if ($action === 'get_matchzy_config') {
    $matchId = (int)($_GET['match_id'] ?? 0);

    if ($matchId <= 0) {
        http_response_code(404);
        echo json_encode([
            'error' => 'Match not found.'
        ]);
        exit;
    }

    $match = matchLobbyGetMatch($pdo, $matchId);

    if (!$match) {
        http_response_code(404);
        echo json_encode([
            'error' => 'Match not found.'
        ]);
        exit;
    }

    $expectedToken = trim((string)($match['matchzy_config_token'] ?? ''));

    if ($expectedToken === '') {
        http_response_code(403);
        echo json_encode([
            'error' => 'MatchZy token not generated.'
        ]);
        exit;
    }

    $headerName = matchzyConfigHeaderName();
    $providedToken = matchzyRequestHeader($headerName);

    /**
     * Awaryjny fallback do testów w przeglądarce:
     * /matchzy/match_123.json?token=...
     */
    if ($providedToken === '') {
        $providedToken = trim((string)($_GET['token'] ?? ''));
    }

    if (!hash_equals($expectedToken, $providedToken)) {
        http_response_code(403);
        echo json_encode([
            'error' => 'Invalid MatchZy token.'
        ]);
        exit;
    }

    try {
        $json = matchzyReadConfigFile($matchId);

        /**
         * Fallback:
         * gdyby plik jeszcze nie istniał, endpoint sam go wygeneruje.
         * Przy normalnym flow plik powstaje przed RCON.
         */
        if ($json === null) {
            matchzyWriteConfigFile($pdo, $match);
            $json = matchzyReadConfigFile($matchId);
        }

        if ($json === null) {
            throw new RuntimeException('Nie udało się odczytać pliku MatchZy JSON.');
        }

        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('X-Content-Type-Options: nosniff');

        echo $json;
        exit;
    } catch (Throwable $e) {
        http_response_code(500);

        echo json_encode([
            'error' => $e->getMessage()
        ]);
        exit;
    }
}