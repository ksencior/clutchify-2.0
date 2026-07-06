export const playController = {
    matches: [],

    init: async () => {
        window.playController = playController;
        await playController.load();
    },

    load: async () => {
        const container = document.getElementById('play-matches-list');

        if (container) {
            container.innerHTML = '<div class="empty-state">Ładowanie meczów...</div>';
        }

        try {
            const response = await fetch('api.php?action=get_my_matches');
            const data = await response.json();

            if (!data.success) {
                window.Toast.show(data.message || 'Nie udało się pobrać meczów.', 'error');
                return;
            }

            playController.matches = data.matches || [];
            playController.render(data);
        } catch (error) {
            console.error(error);
            window.Toast.show('Błąd podczas ładowania centrum gry.', 'error');
        }
    },

    render: (data) => {
        const container = document.getElementById('play-matches-list');
        const countEl = document.getElementById('play-active-count');
        const hintEl = document.getElementById('play-team-hint');

        if (!container) return;

        const matches = data.matches || [];
        const activeCount = matches.filter(match => ['pending', 'ready_check', 'live'].includes(match.status)).length;

        if (countEl) {
            countEl.textContent = activeCount;
        }

        if (hintEl) {
            hintEl.textContent = data.team_id
                ? 'Tu zobaczysz mecze swojej drużyny z wygenerowanych bracketów.'
                : 'Dołącz do drużyny, żeby pojawiły się tutaj Twoje mecze.';
        }

        if (!matches.length) {
            container.innerHTML = `
                <div class="play-empty-card">
                    <h2>Nie masz jeszcze aktywnych meczów</h2>
                    <p>Gdy Twoja drużyna trafi do bracketu, lobby meczu pojawi się właśnie tutaj.</p>
                    <button class="btn-ok" onclick="router.navigate('tournaments')">Zobacz turnieje</button>
                </div>
            `;
            return;
        }

        container.innerHTML = matches
            .map(match => playController.renderMatchCard(match, data.team_id))
            .join('');
    },

    renderMatchCard: (match, myTeamId) => {
        const teamA = playController.teamLabel(match.team_a_tag, match.team_a_name, 'TBD');
        const teamB = playController.teamLabel(match.team_b_tag, match.team_b_name, 'TBD');

        const opponent = Number(match.team_a_id) === Number(myTeamId)
            ? playController.teamLabel(match.team_b_tag, match.team_b_name, 'TBD')
            : playController.teamLabel(match.team_a_tag, match.team_a_name, 'TBD');

        const ready = match.ready_summary || {};
        const totalReady = Number(ready.total_ready || 0);
        const totalRequired = Number(ready.total_required || 0);
        const percent = totalRequired > 0 ? Math.round((totalReady / totalRequired) * 100) : 0;

        return `
            <article class="play-match-card status-${window.escapeHTML(match.status)}">
                <div class="play-match-main">
                    <span class="eyebrow">${window.escapeHTML(match.tournament_title || 'Turniej')}</span>
                    <h2>${window.escapeHTML(teamA)} <span>vs</span> ${window.escapeHTML(teamB)}</h2>
                    <p>Przeciwnik: ${window.escapeHTML(opponent)}</p>

                    <div class="play-match-meta">
                        <span>${window.escapeHTML(match.status_label || match.status)}</span>
                        <span>Runda ${Number(match.round_number)}, mecz #${Number(match.match_number)}</span>
                        <span>Ready: ${totalReady}/${totalRequired}</span>
                    </div>

                    <div class="play-ready-track">
                        <div style="width: ${percent}%"></div>
                    </div>
                </div>

                <div class="play-match-actions">
                    <button class="btn-confirm" onclick="playController.openMatch(${Number(match.id)})">
                        Otwórz lobby
                    </button>

                    <button class="btn-ok" onclick="playController.openTournament(${Number(match.tournament_id)})">
                        Turniej
                    </button>
                </div>
            </article>
        `;
    },

    teamLabel: (tag, name, fallback = 'TBD') => {
        if (!name) return fallback;
        return `${tag ? `[${tag}] ` : ''}${name}`;
    },

    openMatch: (id) => {
        window.router.navigate('match', true, { id: Number(id) });
    },

    openTournament: (id) => {
        window.router.navigate('tournament', true, { id: Number(id) });
    }
};