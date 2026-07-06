<?php

function tournamentIsAdmin(PDO $pdo, int $userId): bool {
    $stmt = $pdo->prepare("SELECT isAdmin FROM players WHERE user_id = ?");
    $stmt->execute([$userId]);
    $player = $stmt->fetch(PDO::FETCH_ASSOC);

    return $player && (int)$player['isAdmin'] === 1;
}

function getUserTeamForTournament(PDO $pdo, int $userId): ?array {
    $stmt = $pdo->prepare("
        SELECT
            t.*,
            COALESCE(SUM(CASE WHEN p2.is_substitute = 0 THEN 1 ELSE 0 END), 0) AS core_count,
            COUNT(p2.user_id) AS total_count
        FROM players p
        JOIN teams t ON t.id = p.team_id
        LEFT JOIN players p2 ON p2.team_id = t.id
        WHERE p.user_id = ?
        GROUP BY t.id
        LIMIT 1
    ");
    $stmt->execute([$userId]);
    $team = $stmt->fetch(PDO::FETCH_ASSOC);

    return $team ?: null;
}

function getTournamentById(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare("SELECT * FROM tournaments WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $tournament = $stmt->fetch(PDO::FETCH_ASSOC);

    return $tournament ?: null;
}

function getTournamentByJoinCode(PDO $pdo, string $joinCode): ?array {
    $stmt = $pdo->prepare("SELECT * FROM tournaments WHERE join_code = ? LIMIT 1");
    $stmt->execute([$joinCode]);
    $tournament = $stmt->fetch(PDO::FETCH_ASSOC);

    return $tournament ?: null;
}

function tournamentSignupsEnded(array $tournament): bool {
    if (empty($tournament['sign_in_end'])) {
        return false;
    }

    return strtotime($tournament['sign_in_end']) < time();
}

function tournamentStarted(array $tournament): bool {
    if (empty($tournament['starts_at'])) {
        return false;
    }

    return strtotime($tournament['starts_at']) < time();
}

function tournamentStatus(array $tournament): string {
    return $tournament['status'] ?? 'registration_open';
}

function tournamentAllowsRegistration(array $tournament): bool {
    return tournamentStatus($tournament) === 'registration_open'
        && !tournamentSignupsEnded($tournament)
        && !tournamentStarted($tournament);
}

function tournamentStatusLabel(string $status): string {
    return match ($status) {
        'registration_open' => 'Zapisy otwarte',
        'registration_closed' => 'Zapisy zamknięte',
        'in_progress' => 'W trakcie',
        'finished' => 'Zakończony',
        'cancelled' => 'Anulowany',
        default => 'Nieznany status'
    };
}

function getTournamentParticipants(PDO $pdo, int $tournamentId, bool $isAdmin = false, ?int $viewerTeamId = null): array {
    $params = [$tournamentId];

    $visibilitySql = "tt.status = 'approved'";

    if ($isAdmin) {
        $visibilitySql = "tt.status IN ('pending', 'approved', 'rejected', 'left')";
    } elseif ($viewerTeamId) {
        $visibilitySql = "(tt.status = 'approved' OR tt.team_id = ?)";
        $params[] = $viewerTeamId;
    }

    $stmt = $pdo->prepare("
        SELECT
            tt.id AS registration_id,
            tt.status,
            tt.verification_note,
            tt.admin_note,
            tt.created_at AS registered_at,
            tt.reviewed_at,

            t.id AS team_id,
            t.name,
            t.tag,
            t.logo,
            t.captain_id,

            u.username AS captain_username,

            COALESCE(SUM(CASE WHEN p.is_substitute = 0 THEN 1 ELSE 0 END), 0) AS core_count,
            COUNT(p.user_id) AS total_count
        FROM tournament_teams tt
        JOIN teams t ON t.id = tt.team_id
        LEFT JOIN users u ON u.id = t.captain_id
        LEFT JOIN players p ON p.team_id = t.id
        WHERE tt.tournament_id = ?
          AND {$visibilitySql}
        GROUP BY tt.id, t.id, u.username
        ORDER BY
            FIELD(tt.status, 'pending', 'approved', 'rejected', 'left'),
            tt.created_at ASC
    ");

    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function nextPowerOfTwo(int $number): int {
    $power = 1;

    while ($power < $number) {
        $power *= 2;
    }

    return $power;
}

function getTournamentMatches(PDO $pdo, int $tournamentId): array {
    $stmt = $pdo->prepare("
        SELECT
            tm.id,
            tm.tournament_id,
            tm.round_number,
            tm.match_number,
            tm.team_a_id,
            tm.team_b_id,
            tm.winner_team_id,
            tm.status,
            tm.scheduled_at,
            tm.started_at,
            tm.finished_at,
            tm.created_at,

            ta.name AS team_a_name,
            ta.tag AS team_a_tag,
            ta.logo AS team_a_logo,

            tb.name AS team_b_name,
            tb.tag AS team_b_tag,
            tb.logo AS team_b_logo,

            tw.name AS winner_team_name,
            tw.tag AS winner_team_tag
        FROM tournament_matches tm
        LEFT JOIN teams ta ON ta.id = tm.team_a_id
        LEFT JOIN teams tb ON tb.id = tm.team_b_id
        LEFT JOIN teams tw ON tw.id = tm.winner_team_id
        WHERE tm.tournament_id = ?
        ORDER BY tm.round_number ASC, tm.match_number ASC
    ");

    $stmt->execute([$tournamentId]);

    $matches = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($matches as &$match) {
        $match['id'] = (int)$match['id'];
        $match['tournament_id'] = (int)$match['tournament_id'];
        $match['round_number'] = (int)$match['round_number'];
        $match['match_number'] = (int)$match['match_number'];
        $match['team_a_id'] = $match['team_a_id'] !== null ? (int)$match['team_a_id'] : null;
        $match['team_b_id'] = $match['team_b_id'] !== null ? (int)$match['team_b_id'] : null;
        $match['winner_team_id'] = $match['winner_team_id'] !== null ? (int)$match['winner_team_id'] : null;
    }

    return $matches;
}

if ($action === 'get_open_tournaments') {
    try {
        $stmt = $pdo->prepare("
            SELECT id, creator, title, sign_in_end, starts_at, is_open
            FROM tournaments
            WHERE is_open = 1 AND status = 'registration_open'
            ORDER BY sign_in_end ASC, created_at DESC
        ");
        $stmt->execute();

        jsonSuccess([
            'items' => $stmt->fetchAll(PDO::FETCH_ASSOC)
        ]);
    } catch (PDOException $e) {
        jsonError('Błąd bazy danych.', 500);
    }
}

if ($action === 'create_tournament') {
    $userId = requireUserId();

    if (!tournamentIsAdmin($pdo, $userId)) {
        jsonError('Nie masz uprawnień by to zrobić.', 403);
    }

    $input = getJsonInput();

    $title = trim($input['title'] ?? '');
    $creator = trim($input['creator'] ?? '');
    $isOpen = !empty($input['isOpen']) ? 1 : 0;

    $signEndsMs = $input['signEnds'] ?? null;
    $startsAtMs = $input['startsAt'] ?? null;

    if ($title === '' || $creator === '') {
        jsonError('Nazwa i organizator turnieju są wymagane.');
    }

    if (!$signEndsMs || !$startsAtMs) {
        jsonError('Podaj datę zakończenia zapisów i startu turnieju.');
    }

    $signEndsFormatted = date('Y-m-d H:i:s', ((int)$signEndsMs) / 1000);
    $startsAtFormatted = date('Y-m-d H:i:s', ((int)$startsAtMs) / 1000);

    if (strtotime($signEndsFormatted) <= time()) {
        jsonError('Koniec zapisów musi być w przyszłości.');
    }

    if (strtotime($startsAtFormatted) <= strtotime($signEndsFormatted)) {
        jsonError('Start turnieju musi być po zakończeniu zapisów.');
    }

    $joinCode = getRandomHex(8);

    try {
        $stmt = $pdo->prepare("
            INSERT INTO tournaments(join_code, is_open, status, creator, title, sign_in_end, starts_at)
            VALUES (?, ?, 'registration_open', ?, ?, ?, ?)
        ");
        $stmt->execute([
            $joinCode,
            $isOpen,
            $creator,
            $title,
            $signEndsFormatted,
            $startsAtFormatted
        ]);

        $tournamentId = (int)$pdo->lastInsertId();

        logActivity(
            $pdo,
            'tournament_created',
            'Nowy turniej',
            'Utworzono turniej: ' . $title . '.',
            $userId,
            'tournament',
            $tournamentId,
            [
                'title' => $title,
                'creator' => $creator,
                'is_open' => (bool)$isOpen
            ],
            'public'
        );

        jsonSuccess([
            'message' => $isOpen
                ? 'Utworzono otwarty turniej.'
                : 'Utworzono zamknięty turniej. Zapis przez kod wymaga akceptacji admina.',
            'tournament_id' => $tournamentId,
            'join_code' => $isOpen ? null : $joinCode
        ]);
    } catch (PDOException $e) {
        jsonError('Nie udało się utworzyć turnieju.', 500);
    }
}

if ($action === 'get_tournament') {
    $id = (int)($_GET['id'] ?? 0);

    if (!$id) {
        jsonError('Brak ID turnieju.');
    }

    $viewerId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
    $isAdmin = $viewerId ? tournamentIsAdmin($pdo, $viewerId) : false;
    $viewerTeam = $viewerId ? getUserTeamForTournament($pdo, $viewerId) : null;
    $viewerTeamId = $viewerTeam ? (int)$viewerTeam['id'] : null;

    try {
        $tournament = getTournamentById($pdo, $id);

        if (!$tournament) {
            jsonError('Turniej nie istnieje.', 404);
        }

        $participants = getTournamentParticipants($pdo, $id, $isAdmin, $viewerTeamId);
        $matches = getTournamentMatches($pdo, $id);
        $userRegistration = null;

        if ($viewerTeamId) {
            $stmt = $pdo->prepare("
                SELECT id, status, verification_note, admin_note, created_at
                FROM tournament_teams
                WHERE tournament_id = ? AND team_id = ?
                LIMIT 1
            ");
            $stmt->execute([$id, $viewerTeamId]);
            $userRegistration = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        /**
         * Bardzo ważne:
         * join_code pokazujemy tylko adminowi.
         * Normalny user nie powinien podejrzeć kodu w Network tab.
         */
        if (!$isAdmin) {
            unset($tournament['join_code']);
        }

        jsonSuccess([
            'tournament' => $tournament,
            'participants' => $participants,
            'matches' => $matches,
            'user_team' => $viewerTeam,
            'user_registration' => $userRegistration,
            'is_admin' => $isAdmin,
            'signups_ended' => tournamentSignupsEnded($tournament),
            'started' => tournamentStarted($tournament)
        ]);
    } catch (PDOException $e) {
        jsonError('Błąd bazy danych.', 500);
    }
}

if ($action === 'join_tournament') {
    $userId = requireUserId();
    $input = getJsonInput();

    $tournamentId = (int)($input['tournament_id'] ?? 0);
    $joinCode = strtolower(trim($input['join_code'] ?? ''));
    $verificationNote = trim($input['verification_note'] ?? '');

    if (!$tournamentId && $joinCode === '') {
        jsonError('Brak ID turnieju albo kodu dołączenia.');
    }

    $tournament = $tournamentId
        ? getTournamentById($pdo, $tournamentId)
        : getTournamentByJoinCode($pdo, $joinCode);

    if (!$tournament) {
        jsonError('Turniej nie istnieje albo kod jest nieprawidłowy.', 404);
    }

    $isClosed = (int)$tournament['is_open'] !== 1;

    if ($isClosed && ($joinCode === '' || !hash_equals((string)$tournament['join_code'], $joinCode))) {
        jsonError('Ten turniej jest zamknięty. Podaj poprawny kod dołączenia.', 403);
    }

    if (tournamentStatus($tournament) !== 'registration_open') {
        jsonError('Zapisy do tego turnieju są obecnie zamknięte.');
    }

    if (tournamentSignupsEnded($tournament)) {
        jsonError('Termin zapisów do tego turnieju minął.');
    }

    if (tournamentStarted($tournament)) {
        jsonError('Ten turniej już wystartował.');
    }

    $team = getUserTeamForTournament($pdo, $userId);

    if (!$team) {
        jsonError('Musisz należeć do drużyny, żeby zapisać się do turnieju.');
    }

    if ((int)$team['captain_id'] !== $userId) {
        jsonError('Tylko kapitan drużyny może zapisać team do turnieju.', 403);
    }

    if ((int)$team['core_count'] < 5) {
        jsonError('Drużyna musi mieć minimum 5 podstawowych graczy. Rezerwa jest opcjonalna.');
    }

    /**
     * Otwarty turniej:
     * auto-approved.
     *
     * Zamknięty turniej:
     * kod tylko tworzy zgłoszenie pending.
     * Admin musi zatwierdzić.
     */
    $status = $isClosed ? 'pending' : 'approved';

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            SELECT *
            FROM tournament_teams
            WHERE tournament_id = ? AND team_id = ?
            LIMIT 1
        ");
        $stmt->execute([(int)$tournament['id'], (int)$team['id']]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing && $existing['status'] === 'approved') {
            $pdo->rollBack();
            jsonError('Twoja drużyna jest już zapisana do tego turnieju.');
        }

        if ($existing && $existing['status'] === 'pending') {
            $pdo->rollBack();
            jsonError('Zgłoszenie Twojej drużyny już oczekuje na weryfikację.');
        }

        if ($existing) {
            $stmt = $pdo->prepare("
                UPDATE tournament_teams
                SET
                    status = ?,
                    registered_by = ?,
                    verification_note = ?,
                    admin_note = NULL,
                    reviewed_by = NULL,
                    reviewed_at = NULL
                WHERE id = ?
            ");
            $stmt->execute([$status, $userId, $verificationNote, (int)$existing['id']]);
            $registrationId = (int)$existing['id'];
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO tournament_teams (tournament_id, team_id, registered_by, status, verification_note)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([(int)$tournament['id'], (int)$team['id'], $userId, $status, $verificationNote]);
            $registrationId = (int)$pdo->lastInsertId();
        }

        $pdo->commit();

        if ($status === 'approved') {
            logActivity(
                $pdo,
                'tournament_team_joined',
                'Drużyna dołączyła do turnieju',
                'Drużyna ' . $team['name'] . ' zapisała się do turnieju ' . $tournament['title'] . '.',
                $userId,
                'tournament',
                (int)$tournament['id'],
                [
                    'team_id' => (int)$team['id'],
                    'team_name' => $team['name'],
                    'tournament_title' => $tournament['title']
                ],
                'public'
            );
        } else {
            logActivity(
                $pdo,
                'tournament_team_pending',
                'Nowe zgłoszenie do turnieju',
                'Drużyna ' . $team['name'] . ' wysłała zgłoszenie do turnieju ' . $tournament['title'] . '.',
                $userId,
                'tournament',
                (int)$tournament['id'],
                [
                    'team_id' => (int)$team['id'],
                    'team_name' => $team['name'],
                    'tournament_title' => $tournament['title']
                ],
                'admin'
            );
        }

        jsonSuccess([
            'message' => $status === 'approved'
                ? 'Drużyna została zapisana do turnieju.'
                : 'Zgłoszenie wysłane. Admin musi je zatwierdzić.',
            'status' => $status,
            'registration_id' => $registrationId,
            'tournament_id' => (int)$tournament['id']
        ]);
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        jsonError('Nie udało się zapisać drużyny do turnieju.', 500);
    }
}

if ($action === 'leave_tournament') {
    $userId = requireUserId();
    $input = getJsonInput();
    $tournamentId = (int)($input['tournament_id'] ?? 0);

    if (!$tournamentId) {
        jsonError('Brak ID turnieju.');
    }

    $tournament = getTournamentById($pdo, $tournamentId);

    if (!$tournament) {
        jsonError('Turniej nie istnieje.', 404);
    }

    if (tournamentStarted($tournament)) {
        jsonError('Nie możesz opuścić turnieju po jego starcie.');
    }

    $team = getUserTeamForTournament($pdo, $userId);

    if (!$team) {
        jsonError('Nie należysz do żadnej drużyny.');
    }

    if ((int)$team['captain_id'] !== $userId) {
        jsonError('Tylko kapitan drużyny może wycofać team z turnieju.', 403);
    }

    $stmt = $pdo->prepare("
        UPDATE tournament_teams
        SET status = 'left'
        WHERE tournament_id = ?
          AND team_id = ?
          AND status IN ('pending', 'approved')
    ");
    $stmt->execute([$tournamentId, (int)$team['id']]);

    if ($stmt->rowCount() === 0) {
        jsonError('Twoja drużyna nie jest aktywnie zapisana do tego turnieju.');
    }

    jsonSuccess([
        'message' => 'Drużyna została wycofana z turnieju.'
    ]);
}

if ($action === 'review_tournament_team') {
    $userId = requireUserId();

    if (!tournamentIsAdmin($pdo, $userId)) {
        jsonError('Nie masz uprawnień do weryfikacji zgłoszeń.', 403);
    }

    $input = getJsonInput();
    $registrationId = (int)($input['registration_id'] ?? 0);
    $decision = $input['decision'] ?? '';
    $adminNote = trim($input['admin_note'] ?? '');

    if (!$registrationId || !in_array($decision, ['approve', 'reject'], true)) {
        jsonError('Nieprawidłowa decyzja.');
    }

    $newStatus = $decision === 'approve' ? 'approved' : 'rejected';

    $stmt = $pdo->prepare("
        SELECT tt.*, t.name AS team_name, tr.title AS tournament_title
        FROM tournament_teams tt
        JOIN teams t ON t.id = tt.team_id
        JOIN tournaments tr ON tr.id = tt.tournament_id
        WHERE tt.id = ?
        LIMIT 1
    ");
    $stmt->execute([$registrationId]);
    $registration = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$registration) {
        jsonError('Zgłoszenie nie istnieje.', 404);
    }

    if ($registration['status'] !== 'pending') {
        jsonError('To zgłoszenie zostało już rozpatrzone.');
    }

    $stmt = $pdo->prepare("
        UPDATE tournament_teams
        SET status = ?, admin_note = ?, reviewed_by = ?, reviewed_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$newStatus, $adminNote, $userId, $registrationId]);

    if ($newStatus === 'approved') {
        logActivity(
            $pdo,
            'tournament_team_approved',
            'Drużyna zatwierdzona',
            'Drużyna ' . $registration['team_name'] . ' została zatwierdzona w turnieju ' . $registration['tournament_title'] . '.',
            $userId,
            'tournament',
            (int)$registration['tournament_id'],
            [
                'team_id' => (int)$registration['team_id'],
                'team_name' => $registration['team_name'],
                'tournament_title' => $registration['tournament_title']
            ],
            'public'
        );
    }

    $message = $newStatus === 'approved'
        ? "Twoja drużyna <strong>" . htmlspecialchars($registration['team_name'], ENT_QUOTES, 'UTF-8') . "</strong> została zatwierdzona w turnieju <strong>" . htmlspecialchars($registration['tournament_title'], ENT_QUOTES, 'UTF-8') . "</strong>."
        : "Zgłoszenie drużyny <strong>" . htmlspecialchars($registration['team_name'], ENT_QUOTES, 'UTF-8') . "</strong> do turnieju <strong>" . htmlspecialchars($registration['tournament_title'], ENT_QUOTES, 'UTF-8') . "</strong> zostało odrzucone.";

    $stmt = $pdo->prepare("
        INSERT INTO notifications (user_id, type, reference_id, message)
        VALUES (?, 'system', ?, ?)
    ");
    $stmt->execute([(int)$registration['registered_by'], $registrationId, $message]);

    jsonSuccess([
        'message' => $newStatus === 'approved'
            ? 'Zgłoszenie zatwierdzone.'
            : 'Zgłoszenie odrzucone.'
    ]);
}
if ($action === 'generate_bracket') {
    $userId = requireUserId();

    if (!tournamentIsAdmin($pdo, $userId)) {
        jsonError('Nie masz uprawnień do wygenerowania drabinki.', 403);
    }

    $input = getJsonInput();
    $tournamentId = (int)($input['tournament_id'] ?? 0);

    if (!$tournamentId) {
        jsonError('Brak ID turnieju.');
    }

    $tournament = getTournamentById($pdo, $tournamentId);

    if (!$tournament) {
        jsonError('Turniej nie istnieje.', 404);
    }

    if (tournamentStatus($tournament) !== 'registration_closed') {
        jsonError('Bracket można wygenerować dopiero po zamknięciu zapisów.');
    }

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM tournament_teams
        WHERE tournament_id = ?
          AND status = 'pending'
    ");
    $stmt->execute([$tournamentId]);

    if ((int)$stmt->fetchColumn() > 0) {
        jsonError('Najpierw rozpatrz wszystkie oczekujące zgłoszenia.');
    }

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM tournament_matches
        WHERE tournament_id = ?
    ");
    $stmt->execute([$tournamentId]);

    if ((int)$stmt->fetchColumn() > 0) {
        jsonError('Bracket został już wygenerowany.');
    }

    $stmt = $pdo->prepare("
        SELECT
            teams.id,
            teams.name,
            teams.tag
        FROM tournament_teams tt
        JOIN teams ON teams.id = tt.team_id
        WHERE tt.tournament_id = ?
          AND tt.status = 'approved'
        ORDER BY tt.created_at ASC
    ");
    $stmt->execute([$tournamentId]);

    $teams = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($teams) < 2) {
        jsonError('Do wygenerowania bracketu potrzeba minimum 2 zatwierdzonych drużyn.');
    }

    shuffle($teams);

    $teamCount = count($teams);
    $bracketSize = nextPowerOfTwo($teamCount);
    $roundOneMatchCount = intdiv($bracketSize, 2);
    $byeCount = $bracketSize - $teamCount;

    $rounds = [];
    $teamCursor = 0;

    for ($matchNumber = 1; $matchNumber <= $roundOneMatchCount; $matchNumber++) {
        if ($byeCount > 0) {
            $teamA = $teams[$teamCursor++] ?? null;
            $teamB = null;
            $byeCount--;
        } else {
            $teamA = $teams[$teamCursor++] ?? null;
            $teamB = $teams[$teamCursor++] ?? null;
        }

        $teamAId = $teamA ? (int)$teamA['id'] : null;
        $teamBId = $teamB ? (int)$teamB['id'] : null;

        $status = 'pending';
        $winnerTeamId = null;

        if ($teamAId && !$teamBId) {
            $status = 'finished';
            $winnerTeamId = $teamAId;
        } elseif (!$teamAId && $teamBId) {
            $status = 'finished';
            $winnerTeamId = $teamBId;
        }

        $rounds[1][] = [
            'round_number' => 1,
            'match_number' => $matchNumber,
            'team_a_id' => $teamAId,
            'team_b_id' => $teamBId,
            'winner_team_id' => $winnerTeamId,
            'status' => $status
        ];
    }

    $previousRound = $rounds[1];
    $roundNumber = 2;

    while (count($previousRound) > 1) {
        $nextRound = [];
        $nextMatchNumber = 1;

        for ($i = 0; $i < count($previousRound); $i += 2) {
            $leftMatch = $previousRound[$i];
            $rightMatch = $previousRound[$i + 1];

            $leftResolved = $leftMatch['status'] === 'finished';
            $rightResolved = $rightMatch['status'] === 'finished';

            $teamAId = $leftResolved ? $leftMatch['winner_team_id'] : null;
            $teamBId = $rightResolved ? $rightMatch['winner_team_id'] : null;

            $status = 'pending';
            $winnerTeamId = null;

            if ($leftResolved && $rightResolved) {
                if ($teamAId && !$teamBId) {
                    $status = 'finished';
                    $winnerTeamId = $teamAId;
                } elseif (!$teamAId && $teamBId) {
                    $status = 'finished';
                    $winnerTeamId = $teamBId;
                }
            }

            $nextRound[] = [
                'round_number' => $roundNumber,
                'match_number' => $nextMatchNumber,
                'team_a_id' => $teamAId,
                'team_b_id' => $teamBId,
                'winner_team_id' => $winnerTeamId,
                'status' => $status
            ];

            $nextMatchNumber++;
        }

        $rounds[$roundNumber] = $nextRound;
        $previousRound = $nextRound;
        $roundNumber++;
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            INSERT INTO tournament_matches (
                tournament_id,
                round_number,
                match_number,
                team_a_id,
                team_b_id,
                winner_team_id,
                status,
                finished_at
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $createdMatches = 0;

        foreach ($rounds as $roundMatches) {
            foreach ($roundMatches as $match) {
                $stmt->execute([
                    $tournamentId,
                    $match['round_number'],
                    $match['match_number'],
                    $match['team_a_id'],
                    $match['team_b_id'],
                    $match['winner_team_id'],
                    $match['status'],
                    $match['status'] === 'finished' ? date('Y-m-d H:i:s') : null
                ]);

                $createdMatches++;
            }
        }

        $stmt = $pdo->prepare("
            UPDATE tournaments
            SET status = 'in_progress'
            WHERE id = ?
              AND status = 'registration_closed'
        ");
        $stmt->execute([$tournamentId]);

        $pdo->commit();

        logActivity(
            $pdo,
            'bracket_generated',
            'Bracket wygenerowany',
            'Wygenerowano drabinkę dla turnieju ' . $tournament['title'] . '.',
            $userId,
            'tournament',
            $tournamentId,
            [
                'tournament_title' => $tournament['title'],
                'team_count' => $teamCount,
                'bracket_size' => $bracketSize,
                'matches_created' => $createdMatches
            ],
            'public'
        );

        jsonSuccess([
            'message' => 'Bracket został wygenerowany.',
            'matches_created' => $createdMatches,
            'bracket_size' => $bracketSize
        ]);
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        jsonError('Nie udało się wygenerować bracketu.', 500);
    }
}
if ($action === 'close_tournament_registration') {
    $userId = requireUserId();

    if (!tournamentIsAdmin($pdo, $userId)) {
        jsonError('Nie masz uprawnień do zarządzania turniejem.', 403);
    }

    $input = getJsonInput();
    $tournamentId = (int)($input['tournament_id'] ?? 0);

    if (!$tournamentId) {
        jsonError('Brak ID turnieju.');
    }

    $tournament = getTournamentById($pdo, $tournamentId);

    if (!$tournament) {
        jsonError('Turniej nie istnieje.', 404);
    }

    if (tournamentStatus($tournament) !== 'registration_open') {
        jsonError('Zapisy nie są aktualnie otwarte.');
    }

    $stmt = $pdo->prepare("
        UPDATE tournaments
        SET status = 'registration_closed'
        WHERE id = ?
          AND status = 'registration_open'
    ");
    $stmt->execute([$tournamentId]);

    jsonSuccess([
        'message' => 'Zapisy do turnieju zostały zamknięte.'
    ]);
}
if ($action === 'reopen_tournament_registration') {
    $userId = requireUserId();

    if (!tournamentIsAdmin($pdo, $userId)) {
        jsonError('Nie masz uprawnień do zarządzania turniejem.', 403);
    }

    $input = getJsonInput();
    $tournamentId = (int)($input['tournament_id'] ?? 0);

    if (!$tournamentId) {
        jsonError('Brak ID turnieju.');
    }

    $tournament = getTournamentById($pdo, $tournamentId);

    if (!$tournament) {
        jsonError('Turniej nie istnieje.', 404);
    }

    if (tournamentStatus($tournament) !== 'registration_closed') {
        jsonError('Można ponownie otworzyć tylko zamknięte zapisy.');
    }

    if (tournamentSignupsEnded($tournament)) {
        jsonError('Nie można otworzyć zapisów, bo termin zapisów już minął.');
    }

    if (tournamentStarted($tournament)) {
        jsonError('Nie można otworzyć zapisów, bo turniej już wystartował.');
    }

    $stmt = $pdo->prepare("
        UPDATE tournaments
        SET status = 'registration_open'
        WHERE id = ?
          AND status = 'registration_closed'
    ");
    $stmt->execute([$tournamentId]);

    jsonSuccess([
        'message' => 'Zapisy do turnieju zostały ponownie otwarte.'
    ]);
}