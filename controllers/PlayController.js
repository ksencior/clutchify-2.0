export const playController = {
    matches: [],
    practiceStatus: null,

    init: async () => {
        window.playController = playController;
        await playController.load();
        await playController.loadPracticeStatus();
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
    loadPracticeStatus: async () => {
        try {
            const response = await fetch('api.php?action=get_practice_status');
            const data = await response.json();

            if (!data.success) {
                return;
            }

            playController.practiceStatus = data;
            playController.renderPractice(data);
        } catch (error) {
            console.error(error);
        }
    },

    renderPractice: (data) => {
        const serverSelect = document.getElementById('practice-server');
        const mapSelect = document.getElementById('practice-map');
        const statusText = document.getElementById('practice-status-text');
        const statusPill = document.getElementById('practice-status-pill');
        const activePanel = document.getElementById('practice-active-panel');
        const controls = document.getElementById('practice-controls');
        const connectEl = document.getElementById('practice-connect-string');
        const serverList = document.getElementById('practice-server-list');
        const startPracticeBtn = document.getElementById('btn-practice-start');
        const changePracticeMapBtn = document.getElementById('btn-practice-change-map');
        const desktopConnectBtn = document.getElementById('practice-connect-desktop-btn');

        const quota = data.daily_quota || null;

        if (!serverSelect || !mapSelect || !statusText || !statusPill) return;

        if (!data.enabled) {
            statusText.textContent = 'Practice mode jest wyłączony na serwerze.';
            statusPill.textContent = 'OFF';
            statusPill.className = 'practice-pill is-off';
            return;
        }

        const maps = data.maps || {};
        const servers = data.servers || [];
        const freeServers = servers.filter(server => !server.is_busy);

        serverSelect.innerHTML = `
            <option value="0">Auto — wybierz wolny serwer</option>
            ${servers.map(server => `
                <option
                    value="${Number(server.id)}"
                    ${server.is_busy ? 'disabled' : ''}
                >
                    ${window.escapeHTML(server.name)}
                    ${server.is_busy ? ` — zajęty przez ${window.escapeHTML(server.active_username || 'gracza')}` : ' — wolny'}
                </option>
            `).join('')}
        `;

        mapSelect.innerHTML = Object.entries(maps)
            .map(([value, label]) => `
                <option value="${window.escapeHTML(value)}">
                    ${window.escapeHTML(label)}
                </option>
            `)
            .join('');

        if (data.session?.map_name) {
            mapSelect.value = data.session.map_name;
        }

        if (startPracticeBtn) {
            const hasActiveSession = !!data.session;
            const canStartByQuota = !quota || quota.is_admin || Number(quota.remaining || 0) > 0;

            startPracticeBtn.disabled = !hasActiveSession && !canStartByQuota;
            startPracticeBtn.textContent = !hasActiveSession && !canStartByQuota
                ? 'Limit wykorzystany'
                : 'Start';
        }

        if (data.session) {
            statusText.textContent = `Twoja sesja: ${data.server?.name || 'Serwer'} • ${data.session.map_name}. ${playController.practiceQuotaText(quota)}`;
            statusPill.textContent = 'TWOJA SESJA';
            statusPill.className = 'practice-pill is-live';

            if (activePanel) activePanel.style.display = 'grid';
            if (connectEl) connectEl.textContent = data.connect || 'connect ...';
            if (controls) controls.style.display = 'flex';
            if (startPracticeBtn) startPracticeBtn.style.display = 'none';
            if (changePracticeMapBtn) changePracticeMapBtn.style.display = 'flex';

            if (desktopConnectBtn) {
                const canDesktopConnect = !!window.ClutchifyDesktop?.isDesktop && !!data.desktop_connect?.address;

                desktopConnectBtn.style.display = canDesktopConnect ? 'inline-flex' : 'none';
            }

        } else {
            const quotaText = playController.practiceQuotaText(quota);

            statusText.textContent = freeServers.length
                ? `Wolne serwery: ${freeServers.length}/${servers.length}.`
                : 'Brak wolnych serwerów practice.';

            statusPill.textContent = freeServers.length ? 'WOLNY' : 'ZAJĘTE';
            statusPill.className = freeServers.length ? 'practice-pill is-free' : 'practice-pill is-busy';

            if (activePanel) activePanel.style.display = 'none';
            if (controls) controls.style.display = 'none';
            if (startPracticeBtn) startPracticeBtn.style.display = 'flex';
            if (changePracticeMapBtn) changePracticeMapBtn.style.display = 'none';

            if (desktopConnectBtn) {
                desktopConnectBtn.style.display = 'none';
            }
        }

        if (serverList) {
            serverList.innerHTML = servers.length
                ? servers.map(server => playController.renderPracticeServer(server)).join('')
                : '<div class="empty-state">Brak skonfigurowanych serwerów practice.</div>';
        }
    },

    practiceQuotaText: (quota) => {
        if (!quota) return '';

        if (quota.is_admin) {
            return 'Admin: brak limitu sesji practice.';
        }

        return `Limit dzienny: ${Number(quota.used || 0)}/${Number(quota.limit || 0)} wykorzystane.`;
    },

    renderPracticeServer: (server) => {
        return `
            <div class="practice-server-row ${server.is_busy ? 'is-busy' : 'is-free'}">
                <div>
                    <strong>${window.escapeHTML(server.name || 'Practice Server')}</strong>
                    <span>${window.escapeHTML(server.public_address || '')}</span>
                </div>

                <em>
                    ${server.is_busy
                        ? `Zajęty: ${window.escapeHTML(server.active_username || 'gracz')}`
                        : 'Wolny'}
                </em>
            </div>
        `;
    },

    startPractice: async () => {
        const map = document.getElementById('practice-map')?.value || 'de_mirage';
        const serverId = Number(document.getElementById('practice-server')?.value || 0);
        const startBtn = document.getElementById('btn-practice-start');

        if (startBtn) {
            startBtn.disabled = true;
            startBtn.innerText = 'Ładowanie..';
        }

        const response = await window.apiFetch('api.php?action=start_practice', {
            method: 'POST',
            body: JSON.stringify({
                map,
                server_id: serverId
            })
        });

        const data = await response.json();

        if (!data.success) {
            window.Toast.show(data.message || 'Nie udało się wystartować practice.', 'error');
            return;
        }

        window.Toast.show(data.message || 'Practice wystartował.', 'success');
        if (startBtn) {
            startBtn.disabled = false;
            startBtn.innerText = 'Start';
        }

        if (data.status) {
            playController.practiceStatus = data.status;
            playController.renderPractice(data.status);
        } else {
            await playController.loadPracticeStatus();
        }
    },

    practiceAction: async (practiceAction) => {
        const changeBtn = document.getElementById('btn-practice-change-map');

        const payload = {
            practice_action: practiceAction
        };

        if (practiceAction === 'change_map') {
            payload.map = document.getElementById('practice-map')?.value || 'de_mirage';
            if (changeBtn) {
                changeBtn.disabled = true;
                changeBtn.innerText = 'Ładowanie..';
            }
        }

        const response = await window.apiFetch('api.php?action=practice_action', {
            method: 'POST',
            body: JSON.stringify(payload)
        });

        const data = await response.json();

        if (!data.success) {
            window.Toast.show(data.message || 'Akcja RCON nie powiodła się.', 'error');
            return;
        }

        window.Toast.show(data.message || 'Akcja wykonana.', 'success');
        if (changeBtn) {
            changeBtn.disabled = false;
            changeBtn.innerText = 'Zmień mapę';
        }

        if (data.status) {
            playController.practiceStatus = data.status;
            playController.renderPractice(data.status);
        }
    },

    endPractice: async () => {
        const response = await window.apiFetch('api.php?action=end_practice', {
            method: 'POST',
            body: JSON.stringify({})
        });

        const data = await response.json();

        if (!data.success) {
            window.Toast.show(data.message || 'Nie udało się zakończyć practice.', 'error');
            return;
        }

        window.Toast.show(data.message || 'Practice zakończony.', 'success');

        if (data.status) {
            playController.practiceStatus = data.status;
            playController.renderPractice(data.status);
        }
    },

    copyPracticeConnect: async () => {
        const connect = playController.practiceStatus?.connect;

        if (!connect) {
            window.Toast.show('Brak connect stringa.', 'error');
            return;
        }

        try {
            await navigator.clipboard.writeText(connect);
            window.Toast.show('Skopiowano connect string.', 'success');
        } catch (_) {
            window.Toast.show(connect, 'info');
        }
    },
    desktopConnectPractice: async () => {
        const payload = playController.practiceStatus?.desktop_connect;

        if (!window.ClutchifyDesktop?.isDesktop) {
            window.Toast.show('Automatyczne połączenie działa tylko w aplikacji desktopowej.', 'info');
            return;
        }

        if (!payload?.address) {
            window.Toast.show('Brak danych połączenia z serwerem.', 'error');
            return;
        }

        try {
            const result = await window.ClutchifyDesktop.connectToServer({
                address: payload.address,
                password: payload.password || ''
            });

            if (!result?.success) {
                window.Toast.show(result?.message || 'Nie udało się uruchomić połączenia.', 'error');
                return;
            }

            window.Toast.show('Uruchamiam połączenie przez Steam...', 'success');
        } catch (error) {
            console.error(error);
            window.Toast.show('Nie udało się połączyć przez aplikację desktopową.', 'error');
        }
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