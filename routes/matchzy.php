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
            'error' => 'Match not found. (Bad ID)'
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

if ($action === 'matchzy_event') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false]);
        exit;
    }

    $raw = file_get_contents('php://input');
    $payload = json_decode($raw ?: '', true);

    if (!is_array($payload)) {
        http_response_code(200);
        echo json_encode(['success' => true, 'ignored' => true]);
        exit;
    }

    $matchId = (int)($payload['matchid'] ?? 0);
    $eventName = (string)($payload['event'] ?? 'unknown');

    try {
        if ($matchId <= 0) {
            throw new RuntimeException('Missing matchid.');
        }

        $match = matchLobbyGetMatch($pdo, $matchId);

        if (!$match) {
            throw new RuntimeException('Match not found.');
        }

        $expectedToken = trim((string)($match['matchzy_event_token'] ?? ''));
        $providedToken = matchzyRequestHeader(matchzyEventHeaderName());

        if ($expectedToken === '' || !hash_equals($expectedToken, $providedToken)) {
            throw new RuntimeException('Invalid event token.');
        }

        $stmt = $pdo->prepare("
            INSERT INTO matchzy_events (
                match_id,
                event_name,
                map_number,
                payload_json
            )
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([
            $matchId,
            $eventName,
            isset($payload['map_number']) ? (int)$payload['map_number'] : null,
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        ]);

        if ($eventName === 'series_start') {
            $stmt = $pdo->prepare("
                UPDATE tournament_matches
                SET matchzy_series_started_at = COALESCE(matchzy_series_started_at, NOW())
                WHERE id = ?
            ");
            $stmt->execute([$matchId]);
        }

        if ($eventName === 'going_live') {
            $stmt = $pdo->prepare("
                UPDATE tournament_matches
                SET status = 'live',
                    started_at = COALESCE(started_at, NOW()),
                    matchzy_going_live_at = COALESCE(matchzy_going_live_at, NOW())
                WHERE id = ?
                  AND status IN ('server_ready', 'ready_check', 'pending')
            ");
            $stmt->execute([$matchId]);
        }

        if ($eventName === 'map_result') {
            $team1SeriesScore = (int)($payload['team1']['series_score'] ?? 0);
            $team2SeriesScore = (int)($payload['team2']['series_score'] ?? 0);

            $stmt = $pdo->prepare("
                UPDATE tournament_matches
                SET team_a_score = GREATEST(team_a_score, ?),
                    team_b_score = GREATEST(team_b_score, ?)
                WHERE id = ?
            ");
            $stmt->execute([
                $team1SeriesScore,
                $team2SeriesScore,
                $matchId
            ]);
        }

        if ($eventName === 'series_end') {
            $winnerTeam = (string)($payload['winner']['team'] ?? '');
            $winnerTeamId = null;

            if ($winnerTeam === 'team1') {
                $winnerTeamId = (int)$match['team_a_id'];
            } elseif ($winnerTeam === 'team2') {
                $winnerTeamId = (int)$match['team_b_id'];
            }

            $team1Score = (int)($payload['team1_series_score'] ?? $payload['team1']['series_score'] ?? 0);
            $team2Score = (int)($payload['team2_series_score'] ?? $payload['team2']['series_score'] ?? 0);

            $stmt = $pdo->prepare("
                UPDATE tournament_matches
                SET status = 'finished',
                    finished_at = COALESCE(finished_at, NOW()),
                    winner_team_id = ?,
                    team_a_score = ?,
                    team_b_score = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $winnerTeamId,
                $team1Score,
                $team2Score,
                $matchId
            ]);

            $freshMatch = matchLobbyGetMatch($pdo, $matchId);

            if ($freshMatch) {
                try {
                    matchzyResetServerAfterMatch($pdo, $freshMatch);
                } catch (Throwable $cleanupError) {
                    error_log('[Clutchify] MatchZy cleanup failed for match #' . $matchId . ': ' . $cleanupError->getMessage());
                }
            }

            logActivity(
                $pdo,
                'match_finished',
                'Mecz zakończony',
                'Mecz ' . ($match['team_a_tag'] ?? 'T1') . ' vs ' . ($match['team_b_tag'] ?? 'T2') . ' został zakończony.',
                null,
                'match',
                $matchId,
                [
                    'match_id' => $matchId,
                    'team_a_tag' => $match['team_a_tag'] ?? null,
                    'team_b_tag' => $match['team_b_tag'] ?? null,
                    'team_a_score' => $team1Score,
                    'team_b_score' => $team2Score,
                    'winner_team_id' => $winnerTeamId
                ],
                'public'
            );
        }
    } catch (Throwable $e) {
        error_log('[Clutchify] MatchZy event error: ' . $e->getMessage() . ' payload=' . substr($raw ?: '', 0, 2000));
    }

    /**
     * Ważne: MatchZy traktuje brak 2xx jako fail, więc zawsze odpowiadamy 200,
     * a błędy logujemy po naszej stronie.
     */
    http_response_code(200);
    echo json_encode([
        'success' => true
    ]);
    exit;
}