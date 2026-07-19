import { AppState } from '../state.js';

export const matchLobbyController = {
    currentId: null,
    lastData: null,
    vetoTimer: null,
    vetoAutoResolveInFlight: false,
    serverTimer: null,
    pollTimer: null,
    serverJoinCheckInFlight: false,

    init: async () => {
        window.matchLobbyController = matchLobbyController;

        let id = window.history.state?.id;

        if (!id) {
            const urlParams = new URLSearchParams(window.location.search);
            id = urlParams.get('id');
        }

        if (!id) {
            window.Toast.show('Brak ID meczu.', 'error');
            window.router.navigate('play');
            return;
        }

        matchLobbyController.currentId = Number(id);
        await matchLobbyController.load();
    },

    load: async () => {
        try {
            const response = await fetch(`api.php?action=get_match_lobby&id=${Number(matchLobbyController.currentId)}`);
            const data = await response.json();

            if (!data.success) {
                window.Toast.show(data.message || 'Nie udało się pobrać lobby.', 'error');
                window.router.navigate('play');
                return;
            }

            matchLobbyController.lastData = data;
            matchLobbyController.render(data);
        } catch (error) {
            console.error(error);
            window.Toast.show('Błąd podczas ładowania lobby meczu.', 'error');
        }
    },

    render: (data) => {
        const match = data.match;
        const teams = data.teams;
        const ready = data.ready_summary || {};

        matchLobbyController.setText('match-lobby-title', `${matchLobbyController.teamLabel(teams.a)} vs ${matchLobbyController.teamLabel(teams.b)}`);
        matchLobbyController.setText('match-lobby-tournament', match.tournament_title || 'Turniej');
        matchLobbyController.setText('match-lobby-status', match.status_label || match.status);
        matchLobbyController.setText('match-lobby-round', `Runda ${Number(match.round_number)} • Mecz #${Number(match.match_number)}`);
        matchLobbyController.setText('match-lobby-ready-count', `${Number(ready.total_ready || 0)}/${Number(ready.total_required || 0)}`);

        const statusEl = document.getElementById('match-lobby-status');

        if (statusEl) {
            statusEl.className = `match-lobby-status status-${window.escapeHTML(match.status)}`;
        }

        matchLobbyController.renderReadyBar(ready);
        matchLobbyController.renderVeto(data);
        matchLobbyController.renderServerConnect(data);
        matchLobbyController.startLobbyPolling(data);
        matchLobbyController.renderTeam('a', teams.a, ready.team_a || {});
        matchLobbyController.renderTeam('b', teams.b, ready.team_b || {});
        matchLobbyController.renderPlayerControls(data);
        matchLobbyController.renderAdminControls(data);
    },

    renderReadyBar: (ready) => {
        const bar = document.getElementById('match-lobby-ready-bar');
        if (!bar) return;

        const totalReady = Number(ready.total_ready || 0);
        const totalRequired = Number(ready.total_required || 0);
        const percent = totalRequired > 0 ? Math.round((totalReady / totalRequired) * 100) : 0;

        bar.style.width = `${percent}%`;
    },

    renderTeam: (side, team, summary) => {
        const container = document.getElementById(`match-lobby-team-${side}`);
        if (!container) return;

        const logo = team.logo || `https://api.dicebear.com/7.x/identicon/svg?seed=${encodeURIComponent(team.name || side)}`;
        const players = team.players || [];

        container.innerHTML = `
            <div class="match-team-head">
                <img src="${window.escapeHTML(logo)}" alt="Logo drużyny">

                <div>
                    <h2>${window.escapeHTML(matchLobbyController.teamLabel(team))}</h2>
                    <p>Gotowi: ${Number(summary.ready || 0)}/${Number(summary.required || 0)} wymaganych</p>
                </div>
            </div>

            <div class="match-player-list">
                ${players.length ? players.map(player => matchLobbyController.renderPlayer(player)).join('') : `
                    <div class="empty-state">Brak graczy w tej drużynie.</div>
                `}
            </div>
        `;
    },
    clearVetoTimer: () => {
        if (matchLobbyController.vetoTimer) {
            clearInterval(matchLobbyController.vetoTimer);
            matchLobbyController.vetoTimer = null;
        }
    },

    startVetoTimer: (veto) => {
        matchLobbyController.clearVetoTimer();

        if (!veto?.turn || veto.status !== 'active' || !veto.current) {
            return;
        }

        const tick = () => {
            const timerEl = document.getElementById('match-veto-timer');
            if (!timerEl) return;

            const deadline = veto.turn.deadline_at
                ? new Date(veto.turn.deadline_at.replace(' ', 'T')).getTime()
                : null;

            if (!deadline) {
                timerEl.textContent = `${Number(veto.turn.remaining_seconds || 0)}s`;
                return;
            }

            const remaining = Math.max(0, Math.ceil((deadline - Date.now()) / 1000));
            timerEl.textContent = `${remaining}s`;

            if (remaining <= 0) {
                matchLobbyController.clearVetoTimer();
                matchLobbyController.autoResolveVeto();
            }
        };

        tick();
        matchLobbyController.vetoTimer = setInterval(tick, 300);
    },

    autoResolveVeto: async () => {
        if (matchLobbyController.vetoAutoResolveInFlight) {
            return;
        }

        matchLobbyController.vetoAutoResolveInFlight = true;

        try {
            const response = await window.apiFetch('api.php?action=auto_resolve_map_veto', {
                method: 'POST',
                body: JSON.stringify({
                    match_id: matchLobbyController.currentId
                })
            });

            const data = await response.json();

            if (data.success && data.resolved) {
                window.Toast.show(data.message || 'System wykonał automatyczne veto.', 'info');
                matchLobbyController.notifyLobbyUpdate(data.target_ids || []);
            }

            await matchLobbyController.load();
        } catch (error) {
            console.error(error);
        } finally {
            matchLobbyController.vetoAutoResolveInFlight = false;
        }
    },
    renderVeto: (data) => {
        const veto = data.veto || {};
        const current = veto.current || null;

        const status = document.getElementById('match-veto-status');
        const currentBox = document.getElementById('match-veto-current');
        const mapsBox = document.getElementById('match-veto-maps');
        const historyBox = document.getElementById('match-veto-history');

        matchLobbyController.setText('match-veto-format', String(veto.format || 'bo1').toUpperCase());

        if (!status || !currentBox || !mapsBox || !historyBox) return;

        matchLobbyController.clearVetoTimer();

        if (veto.status === 'not_started') {
            status.textContent = 'Veto wystartuje automatycznie, gdy wszyscy wymagani gracze dadzą READY.';

            currentBox.innerHTML = `
                <div class="match-lobby-callout">
                    <strong>Oczekiwanie na ready</strong>
                    <span>Najpierw gracze muszą potwierdzić gotowość.</span>
                </div>
            `;

            mapsBox.innerHTML = '';
            historyBox.innerHTML = matchLobbyController.renderVetoHistory(data);
            return;
        }

        if (veto.completed) {
            status.textContent = data.match?.status === 'live'
                ? 'Veto zakończone. Mecz wystartował.'
                : 'Veto zakończone. Mecz zaraz wystartuje.';

            currentBox.innerHTML = `
                <div class="match-lobby-callout is-live">
                    <strong>Mapy meczu</strong>
                    <span>${matchLobbyController.renderFinalMapsText(veto.final_maps || [])}</span>
                </div>
            `;

            mapsBox.innerHTML = '';
            historyBox.innerHTML = matchLobbyController.renderVetoHistory(data);
            return;
        }

        if (!current) {
            status.textContent = 'Veto aktywne.';
            currentBox.innerHTML = '';
            mapsBox.innerHTML = '';
            historyBox.innerHTML = matchLobbyController.renderVetoHistory(data);
            return;
        }

        const actionLabel = matchLobbyController.vetoActionLabel(current.action);

        status.textContent = `Ruch: ${current.actor_label} — ${actionLabel}.`;

        currentBox.innerHTML = `
            <div class="match-veto-turn">
                <div>
                    <strong>${window.escapeHTML(current.actor_label)}</strong>
                    <span>${current.action === 'side'
                        ? `wybiera stronę na ${window.escapeHTML(matchLobbyController.cleanMapName(current.map_name || '-'))}`
                        : actionLabel}
                    </span>
                </div>

                <div class="match-veto-clock">
                    <b id="match-veto-timer">${Number(veto.turn?.remaining_seconds || 0)}s</b>
                    <small>na decyzję</small>
                </div>
            </div>
        `;

        if (current.action === 'side') {
            mapsBox.innerHTML = veto.viewer_can_act ? `
                <button class="match-veto-map is-side" onclick="matchLobbyController.submitVeto('side', null, 'ct')" style="--map-image: url('public/img/maps/${window.escapeHTML(current.map_name)}.jpg')">
                    <strong>CT</strong>
                    <span>Wybierz stronę</span>
                </button>

                <button class="match-veto-map is-side" onclick="matchLobbyController.submitVeto('side', null, 't')" style="--map-image: url('public/img/maps/${window.escapeHTML(current.map_name)}.jpg')">
                    <strong>T</strong>
                    <span>Wybierz stronę</span>
                </button>
            ` : `
                <div class="match-lobby-callout">
                    <strong>Oczekiwanie</strong>
                    <span>Teraz decyzję podejmuje ${window.escapeHTML(current.actor_label)}.</span>
                </div>
            `;
        } else {
            mapsBox.innerHTML = (veto.available_maps || []).map(map => `
                <button
                    class="match-veto-map ${current.action === 'ban' ? 'is-ban' : 'is-pick'}"
                    ${veto.viewer_can_act ? '' : 'disabled'}
                    onclick="matchLobbyController.submitVeto('${current.action}', '${window.escapeHTML(map)}')"
                    style="--map-image: url('public/img/maps/${window.escapeHTML(map)}.jpg')"
                >
                    <strong>${window.escapeHTML(matchLobbyController.cleanMapName(map))}</strong>
                    <span>${current.action === 'ban' ? 'Ban' : 'Pick'}</span>
                </button>
            `).join('');
        }

        historyBox.innerHTML = matchLobbyController.renderVetoHistory(data);
        matchLobbyController.startVetoTimer(veto);
    },

    renderVetoHistory: (data) => {
        const veto = data.veto || {};
        const actions = veto.actions || [];

        if (!actions.length) {
            return '<div class="empty-state">Jeszcze nie wykonano żadnej akcji veto.</div>';
        }

        return `
            <div class="match-veto-timeline">
                ${actions.map(action => `
                    <div class="match-veto-step action-${window.escapeHTML(action.action)}">
                        <span>#${Number(action.step_number)}</span>

                        <div>
                            <strong>${window.escapeHTML(matchLobbyController.vetoHistoryLabel(action))}</strong>
                            <small>${window.escapeHTML(action.actor_label || 'System')}</small>
                        </div>
                    </div>
                `).join('')}
            </div>
        `;
    },

    vetoHistoryLabel: (action) => {
        const map = matchLobbyController.cleanMapName(action.map_name || '');

        if (action.action === 'ban') {
            return `Ban: ${map}`;
        }

        if (action.action === 'pick') {
            return `Pick: ${map}`;
        }

        if (action.action === 'side') {
            return `Strona: ${(action.side_choice || '').toUpperCase()} na ${map}`;
        }

        if (action.action === 'decider') {
            return `Decider: ${map}`;
        }

        return map;
    },

    renderFinalMapsText: (maps = []) => {
        if (!maps.length) {
            return 'Brak map.';
        }

        return maps.map(item => {
            const map = matchLobbyController.cleanMapName(item.map_name);

            if (item.source === 'decider') {
                return `${map} jako decider`;
            }

            const side = item.side_choice
                ? `, ${item.side_team_label} wybiera ${(item.side_choice || '').toUpperCase()}`
                : '';

            return `${map} — pick ${item.picked_by_label}${side}`;
        }).join(' • ');
    },

    vetoActionLabel: (action) => {
        const labels = {
            ban: 'banuje mapę',
            pick: 'wybiera mapę',
            side: 'wybiera stronę',
            decider: 'decider'
        };

        return labels[action] || action;
    },

    cleanMapName: (map) => {
        return String(map || '')
            .replace(/^de_/, '')
            .replace(/_/g, ' ')
            .replace(/\b\w/g, char => char.toUpperCase());
    },

    submitVeto: async (action, mapName = null, sideChoice = null) => {
        const response = await window.apiFetch('api.php?action=submit_map_veto', {
            method: 'POST',
            body: JSON.stringify({
                match_id: matchLobbyController.currentId,
                veto_action: action,
                map_name: mapName,
                side_choice: sideChoice
            })
        });

        const data = await response.json();

        if (!data.success) {
            window.Toast.show(data.message || 'Nie udało się zapisać veto.', 'error');
            return;
        }

        window.Toast.show(data.message || 'Veto zapisane.', 'success');

        matchLobbyController.notifyLobbyUpdate(data.target_ids || []);
        await matchLobbyController.load();
    },

    resetVeto: () => {
        window.Popout.create(
            'Zresetować veto?',
            'Wszystkie bany, picki i wybory stron dla tego meczu zostaną usunięte.',
            null,
            async () => {
                const response = await window.apiFetch('api.php?action=reset_map_veto', {
                    method: 'POST',
                    body: JSON.stringify({
                        match_id: matchLobbyController.currentId
                    })
                });

                const data = await response.json();

                if (!data.success) {
                    window.Toast.show(data.message || 'Nie udało się zresetować veto.', 'error');
                    return;
                }

                window.Toast.show(data.message || 'Veto zresetowane.', 'success');
                matchLobbyController.notifyLobbyUpdate(data.target_ids || []);
                await matchLobbyController.load();
            },
            'confirm'
        );
    },

    setVetoFormat: async () => {
        const format = document.getElementById('match-veto-format-select')?.value || 'bo1';

        const response = await window.apiFetch('api.php?action=set_match_veto_format', {
            method: 'POST',
            body: JSON.stringify({
                match_id: matchLobbyController.currentId,
                format
            })
        });

        const data = await response.json();

        if (!data.success) {
            window.Toast.show(data.message || 'Nie udało się zmienić formatu.', 'error');
            return;
        }

        window.Toast.show(data.message || 'Format zmieniony.', 'success');
        matchLobbyController.notifyLobbyUpdate(data.target_ids || []);
        await matchLobbyController.load();
    },

    renderPlayer: (player) => {
        const avatar = player.avatar || `https://ui-avatars.com/api/?name=${encodeURIComponent(player.username || 'Gracz')}&background=121212&color=ff002b`;
        const isMe = Number(player.id) === Number(AppState.getUser()?.id);

        return `
            <div class="match-player-row ${player.is_ready ? 'is-ready' : ''} ${isMe ? 'is-me' : ''}">
                <img src="${window.escapeHTML(avatar)}" alt="Avatar gracza">

                <div>
                    <strong>${window.escapeHTML(player.username || 'Gracz')}</strong>
                    <span>${player.is_substitute ? 'Rezerwa' : 'Podstawowy'}${isMe ? ' • Ty' : ''}</span>
                </div>

                <em>${player.is_ready ? 'READY' : 'NOT READY'}</em>
            </div>
        `;
    },
    checkServerJoin: async () => {
        if (matchLobbyController.serverJoinCheckInFlight) {
            return;
        }

        matchLobbyController.serverJoinCheckInFlight = true;

        try {
            const response = await window.apiFetch('api.php?action=check_match_server_join', {
                method: 'POST',
                body: JSON.stringify({
                    match_id: Number(matchLobbyController.currentId)
                })
            });

            const data = await response.json();

            if (data.success && data.loaded) {
                window.Toast.show('Wykryto gracza na serwerze. MatchZy został wczytany.', 'success');

                matchLobbyController.notifyLobbyUpdate(data.target_ids || []);
            }
        } catch (error) {
            console.error(error);
        } finally {
            matchLobbyController.serverJoinCheckInFlight = false;
        }
    },
    clearServerTimer: () => {
        if (matchLobbyController.serverTimer) {
            clearInterval(matchLobbyController.serverTimer);
            matchLobbyController.serverTimer = null;
        }
    },

    clearPolling: () => {
        if (matchLobbyController.pollTimer) {
            clearInterval(matchLobbyController.pollTimer);
            matchLobbyController.pollTimer = null;
        }
    },

    startLobbyPolling: (data) => {
        matchLobbyController.clearPolling();

        const status = data.match?.status;

        if (!['server_ready', 'live'].includes(status)) {
            return;
        }

        matchLobbyController.pollTimer = setInterval(async () => {
            if (matchLobbyController.lastData?.match?.status === 'server_ready') {
                await matchLobbyController.checkServerJoin();
            }

            await matchLobbyController.load();
        }, 5000);
    },

    renderServerConnect: (data) => {
        const card = document.getElementById('match-server-card');
        const status = document.getElementById('match-server-status');
        const countdown = document.getElementById('match-server-countdown');
        const connectBox = document.getElementById('match-server-connect');

        if (!card || !status || !countdown || !connectBox) return;

        matchLobbyController.clearServerTimer();

        const match = data.match || {};
        const server = data.server_connect || null;

        if (!server || !['server_ready', 'live', 'finished'].includes(match.status)) {
            card.style.display = 'none';
            return;
        }

        card.style.display = 'block';

        if (match.status === 'finished') {
            status.textContent = 'Mecz zakończony. Serwer został zresetowany.';
            countdown.textContent = 'GG';
            connectBox.innerHTML = `
                <div class="match-lobby-callout">
                    <strong>Wynik</strong>
                    <span>${Number(match.team_a_score || 0)}:${Number(match.team_b_score || 0)}</span>
                </div>
            `;
            return;
        }

        if (match.status === 'live') {
            status.textContent = 'Mecz jest live na serwerze.';
            countdown.textContent = 'LIVE';
        } else {
            status.textContent = 'Serwer czeka na pierwszego gracza. Po wejściu MatchZy wczyta konfigurację automatycznie.';
        }

        connectBox.innerHTML = `
            <code>${window.escapeHTML(server.connect || '')}</code>

            <div class="match-server-actions">
                <button class="btn-ok compact" onclick="matchLobbyController.copyConnect()">
                    Kopiuj connect
                </button>

                ${window.ClutchifyDesktop?.isDesktop ? `
                    <button class="btn-confirm compact" onclick="matchLobbyController.desktopConnect()">
                        Połącz
                    </button>
                ` : ''}
            </div>

            ${server.server_name ? `<small>${window.escapeHTML(server.server_name)}</small>` : ''}
        `;

        if (match.status !== 'server_ready' || !server.deadline_at) {
            return;
        }

        const tick = () => {
            const deadline = new Date(String(server.deadline_at).replace(' ', 'T')).getTime();
            const remaining = Math.max(0, Math.ceil((deadline - Date.now()) / 1000));

            const minutes = Math.floor(remaining / 60);
            const seconds = String(remaining % 60).padStart(2, '0');

            countdown.textContent = `${minutes}:${seconds}`;

            if (remaining <= 0) {
                status.textContent = 'Czas na dołączenie minął. Jeżeli mecz nie ruszył, skontaktuj się z adminem.';
                matchLobbyController.clearServerTimer();
            }
        };

        tick();
        matchLobbyController.serverTimer = setInterval(tick, 500);
    },

    copyConnect: async () => {
        const connect = matchLobbyController.lastData?.server_connect?.connect;

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

    desktopConnect: async () => {
        const connect = matchLobbyController.lastData?.server_connect?.connect || '';
        const match = connect.match(/^connect\s+([^;\s]+)(?:\s*;\s*password\s+([A-Za-z0-9_-]+))?/i);

        if (!match || !window.ClutchifyDesktop?.connectToServer) {
            window.Toast.show('Desktop connect niedostępny.', 'error');
            return;
        }

        const result = await window.ClutchifyDesktop.connectToServer({
            address: match[1],
            password: match[2] || ''
        });

        if (result?.success) {
            window.Toast.show('Uruchamiam CS2...', 'success');
        } else {
            window.Toast.show(result?.message || 'Nie udało się uruchomić CS2.', 'error');
        }
    },

    renderPlayerControls: (data) => {
        const container = document.getElementById('match-lobby-player-controls');
        if (!container) return;

        const match = data.match;
        const viewer = data.viewer || {};

        if (match.status === 'server_ready') {
            container.innerHTML = `
                <div class="match-lobby-callout is-live">
                    <strong>Serwer gotowy.</strong>
                    <span>Skopiuj connect wyżej, wejdź na serwer i daj READY w grze.</span>
                </div>
            `;
            return;
        }

        if (match.status === 'live') {
            container.innerHTML = `
                <div class="match-lobby-callout is-live">
                    <strong>Mecz jest live.</strong>
                    <span>Powodzenia!</span>
                </div>
            `;
            return;
        }

        if (match.status === 'finished') {
            container.innerHTML = `
                <div class="match-lobby-callout">
                    <strong>Mecz zakończony.</strong>
                    <span>Wynik zostanie pokazany w bracketcie.</span>
                </div>
            `;
            return;
        }

        if (!viewer.can_ready) {
            container.innerHTML = `
                <div class="match-lobby-callout">
                    <strong>Podgląd lobby.</strong>
                    <span>Tylko gracze przypisani do tego meczu mogą oznaczyć gotowość.</span>
                </div>
            `;
            return;
        }

        const nextReady = !viewer.is_ready;

        container.innerHTML = `
            <button
                class="${viewer.is_ready ? 'btn-cancel' : 'btn-confirm'} match-ready-button"
                onclick="matchLobbyController.setReady(${nextReady ? 'true' : 'false'})"
            >
                ${viewer.is_ready ? 'Cofnij gotowość' : 'Jestem gotowy'}
            </button>

            <p>${viewer.is_ready ? 'Jesteś oznaczony jako gotowy.' : 'Kliknij, gdy możesz grać.'}</p>
        `;
    },

    renderAdminControls: (data) => {
        const container = document.getElementById('match-lobby-admin-controls');
        if (!container) return;

        const viewer = data.viewer || {};
        const match = data.match;
        const allReady = !!data.ready_summary?.all_ready;

        if (!viewer.is_admin) {
            container.innerHTML = '';
            return;
        }

        const canStart = ['pending', 'ready_check'].includes(match.status) && allReady;
        const canReset = ['pending', 'ready_check'].includes(match.status);

        container.innerHTML = `
            <section class="match-admin-card">
                <span class="eyebrow">Admin</span>
                <h2>Kontrola meczu</h2>
                <p>${allReady ? 'Wszyscy wymagani gracze są gotowi.' : 'Start będzie dostępny, gdy wszyscy podstawowi gracze klikną ready.'}</p>

                <div class="match-admin-actions">
                    <button class="btn-confirm" ${canStart ? '' : 'disabled'} onclick="matchLobbyController.startMatch()">
                        Start match
                    </button>

                    <button class="btn-cancel" ${canReset ? '' : 'disabled'} onclick="matchLobbyController.resetReady()">
                        Reset ready
                    </button>
                </div>
            </section>
        `;
    },

    setReady: async (isReady) => {
        const response = await window.apiFetch('api.php?action=set_player_ready', {
            method: 'POST',
            body: JSON.stringify({
                match_id: matchLobbyController.currentId,
                is_ready: isReady
            })
        });

        const data = await response.json();
        console.log('set_player_ready response:', data);
        if (data.success) {
            window.Toast.show(data.message || 'Zaktualizowano gotowość.', 'success');
            matchLobbyController.notifyLobbyUpdate(data.target_ids || []);
            await matchLobbyController.load();
        } else {
            window.Toast.show(data.message || 'Nie udało się zmienić gotowości.', 'error');
        }
    },

    resetReady: async () => {
        const response = await window.apiFetch('api.php?action=reset_ready_check', {
            method: 'POST',
            body: JSON.stringify({
                match_id: matchLobbyController.currentId
            })
        });

        const data = await response.json();

        if (data.success) {
            window.Toast.show(data.message || 'Zresetowano ready check.', 'success');
            matchLobbyController.notifyLobbyUpdate(data.target_ids || []);
            await matchLobbyController.load();
        } else {
            window.Toast.show(data.message || 'Nie udało się zresetować ready check.', 'error');
        }
    },

    startMatch: async () => {
        const response = await window.apiFetch('api.php?action=start_match', {
            method: 'POST',
            body: JSON.stringify({
                match_id: matchLobbyController.currentId
            })
        });

        const data = await response.json();

        if (data.success) {
            window.Toast.show(data.message || 'Mecz wystartował.', 'success');
            matchLobbyController.notifyLobbyUpdate(data.target_ids || []);
            await matchLobbyController.load();
        } else {
            window.Toast.show(data.message || 'Nie udało się wystartować meczu.', 'error');
        }
    },

    notifyLobbyUpdate: (targetIds = []) => {
        if (window.wsClient?.readyState !== WebSocket.OPEN) {
            return;
        }

        const normalizedTargetIds = (targetIds || [])
            .map(id => Number(id))
            .filter(id => Number.isInteger(id) && id > 0);

        window.wsClient.send(JSON.stringify({
            type: 'match_lobby_update',
            matchId: Number(matchLobbyController.currentId),
            targetIds: normalizedTargetIds
        }));
    },

    openTournament: () => {
        const tournamentId = matchLobbyController.lastData?.match?.tournament_id;
        if (!tournamentId) return;

        window.router.navigate('tournament', true, { id: Number(tournamentId) });
    },

    teamLabel: (team) => {
        if (!team?.name) return 'TBD';
        return `${team.tag ? `[${team.tag}] ` : ''}${team.name}`;
    },

    setText: (id, value) => {
        const el = document.getElementById(id);
        if (el) el.textContent = value;
    }
};