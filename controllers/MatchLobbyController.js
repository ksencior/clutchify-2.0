import { AppState } from '../state.js';

export const matchLobbyController = {
    currentId: null,
    lastData: null,

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

    renderPlayerControls: (data) => {
        const container = document.getElementById('match-lobby-player-controls');
        if (!container) return;

        const match = data.match;
        const viewer = data.viewer || {};

        if (match.status === 'live') {
            container.innerHTML = `
                <div class="match-lobby-callout is-live">
                    <strong>Mecz jest live.</strong>
                    <span>Ready check został zakończony.</span>
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