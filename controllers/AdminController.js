import { AppState } from "../state.js";


const esc = (value = '') => window.escapeHTML ? window.escapeHTML(value ?? '') : String(value ?? '');

export const adminController = {
    data: null,

    init: async () => {
        window.adminController = adminController;

        if (!AppState.getUser()?.player?.isAdmin) {
            window.Toast.show('Brak uprawnień administratora.', 'error');
            window.router.navigate('dashboard');
            return;
        }

        await adminController.load();
    },

    load: async () => {
        try {
            const response = await fetch('api.php?action=get_admin_overview');
            const data = await response.json();

            if (!data.success) {
                window.Toast.show(data.message || 'Nie udało się pobrać danych admina.', 'error');
                window.router.navigate('dashboard');
                return;
            }

            adminController.data = data;

            adminController.renderStats(data.stats);
            adminController.renderPendingRequests(data.pending_requests);
            adminController.renderUsers(data.latest_users);
            adminController.renderTeams(data.latest_teams);
            adminController.renderTournaments(data.latest_tournaments);
            adminController.renderActivity(data.latest_activity);
            adminController.renderGameServers(data.game_servers);
        } catch (error) {
            console.error(error);
            window.Toast.show('Nie udało się połączyć z API admina.', 'error');
        }
    },

    renderStats: (stats) => {
        const container = document.getElementById('admin-stats');
        if (!container) return;

        const cards = [
            {
                label: 'Użytkownicy',
                value: stats.users_total,
                hint: `+${stats.users_today} dzisiaj / +${stats.users_week} w 7 dni`
            },
            {
                label: 'Drużyny',
                value: stats.teams_total,
                hint: 'Aktywne i utworzone drużyny'
            },
            {
                label: 'Turnieje',
                value: stats.tournaments_total,
                hint: `${stats.open_tournaments} z otwartymi zapisami`
            },
            {
                label: 'Zgłoszenia',
                value: stats.pending_tournament_requests,
                hint: 'Oczekujące zapisy do turniejów'
            },
            {
                label: 'Zaproszenia',
                value: stats.pending_team_invites,
                hint: 'Oczekujące zaproszenia do teamów'
            },
            {
                label: 'Wiadomości dziś',
                value: stats.messages_today,
                hint: `${stats.activity_today} aktywności dziś`
            },
            {
                label: 'Serwery CS',
                value: stats.game_servers_enabled,
                hint: `${stats.game_servers_total || 0} wszystkich • ${stats.practice_sessions_active || 0} aktywnych practice`
            },
        ];

        container.innerHTML = cards.map(card => `
            <article class="admin-stat-card">
                <span>${esc(card.label)}</span>
                <strong>${Number(card.value || 0).toLocaleString('pl-PL')}</strong>
                <small>${esc(card.hint)}</small>
            </article>
        `).join('');
    },

    renderPendingRequests: (items = []) => {
        const container = document.getElementById('admin-pending-requests');
        if (!container) return;

        if (!items.length) {
            container.innerHTML = `
                <div class="empty-state">
                    Brak oczekujących zgłoszeń do turniejów.
                </div>
            `;
            return;
        }

        container.innerHTML = items.map(item => `
            <article class="admin-list-item important">
                <div>
                    <strong>${esc(item.team_name)} ${item.team_tag ? `<span>[${esc(item.team_tag)}]</span>` : ''}</strong>
                    <p>
                        Turniej: ${esc(item.tournament_title)}
                        ${item.captain_username ? `• Kapitan: ${esc(item.captain_username)}` : ''}
                    </p>
                    <small>${adminController.formatDate(item.created_at)}</small>
                </div>

                <button class="btn-confirm compact" onclick="adminController.openTournament(${Number(item.tournament_id)})">
                    Sprawdź
                </button>
            </article>
        `).join('');
    },

    renderUsers: (items = []) => {
        const container = document.getElementById('admin-latest-users');
        if (!container) return;

        if (!items.length) {
            container.innerHTML = '<div class="empty-state">Brak użytkowników.</div>';
            return;
        }

        container.innerHTML = items.map(user => {
            const avatar = user.avatar
                || `https://ui-avatars.com/api/?name=${encodeURIComponent(user.username || 'U')}&background=121212&color=ff002b`;

            return `
                <article class="admin-list-item">
                    <img src="${esc(avatar)}" alt="Avatar" class="admin-mini-avatar">

                    <div>
                        <strong>
                            ${esc(user.username)}
                            ${user.is_admin ? '<span class="admin-pill">ADMIN</span>' : ''}
                        </strong>
                        <p>${esc(user.email)}</p>
                        <small>
                            ${user.team_name ? `Team: ${esc(user.team_name)} • ` : ''}
                            ${adminController.formatDate(user.created_at)}
                        </small>
                    </div>

                    <button class="btn-ok compact" onclick="adminController.openUser(${Number(user.id)})">
                        Profil
                    </button>
                </article>
            `;
        }).join('');
    },

    renderTeams: (items = []) => {
        const container = document.getElementById('admin-latest-teams');
        if (!container) return;

        if (!items.length) {
            container.innerHTML = '<div class="empty-state">Brak drużyn.</div>';
            return;
        }

        container.innerHTML = items.map(team => `
            <article class="admin-list-item">
                <img src="${esc(team.logo || `https://api.dicebear.com/7.x/identicon/svg?seed=${encodeURIComponent(team.name || 'Team')}`)}" alt="Logo" class="admin-mini-avatar">

                <div>
                    <strong>${esc(team.name)} <span>[${esc(team.tag)}]</span></strong>
                    <p>
                        Kapitan: ${team.captain_username ? esc(team.captain_username) : 'Brak'}
                        • Graczy: ${Number(team.players_count || 0)}
                    </p>
                    <small>${adminController.formatDate(team.created_at)}</small>
                </div>
            </article>
        `).join('');
    },

    renderTournaments: (items = []) => {
        const container = document.getElementById('admin-latest-tournaments');
        if (!container) return;

        if (!items.length) {
            container.innerHTML = '<div class="empty-state">Brak turniejów.</div>';
            return;
        }

        container.innerHTML = items.map(tournament => `
            <article class="admin-list-item">
                <div class="admin-list-icon">🏆</div>

                <div>
                    <strong>${esc(tournament.title)}</strong>
                    <p>
                        ${esc(tournament.creator || 'Brak organizatora')}
                        • ${adminController.tournamentStatusLabel(tournament)}
                    </p>
                    <small>
                        Start: ${adminController.formatDate(tournament.starts_at)}
                    </small>
                </div>

                <button class="btn-ok compact" onclick="adminController.openTournament(${Number(tournament.id)})">
                    Otwórz
                </button>
            </article>
        `).join('');
    },

    renderActivity: (items = []) => {
        const container = document.getElementById('admin-latest-activity');
        if (!container) return;

        if (!items.length) {
            container.innerHTML = `
                <div class="empty-state">
                    Brak aktywności albo tabela activity_events jeszcze nie istnieje.
                </div>
            `;
            return;
        }

        container.innerHTML = items.map(item => `
            <article class="admin-list-item">
                <div class="admin-list-icon">${adminController.activityIcon(item.type)}</div>

                <div>
                    <strong>${esc(item.title)}</strong>
                    <p>${esc(item.message)}</p>
                    <small>
                        ${item.actor_username ? `Autor: ${esc(item.actor_username)} • ` : ''}
                        ${esc(item.visibility)}
                        • ${adminController.formatDate(item.created_at)}
                    </small>
                </div>
            </article>
        `).join('');
    },
    renderGameServers: (items = []) => {
        const container = document.getElementById('admin-game-servers');
        if (!container) return;

        adminController.gameServers = items;

        if (!items.length) {
            container.innerHTML = '<div class="empty-state">Brak skonfigurowanych serwerów CS.</div>';
            return;
        }

        container.innerHTML = items.map(server => `
            <article class="admin-game-server-row ${server.is_enabled ? '' : 'is-disabled'}">
                <div class="admin-game-server-main">
                    <div>
                        <strong>
                            ${esc(server.name)}
                            <span class="admin-server-purpose">${adminController.serverPurposeLabel(server.purpose)}</span>
                        </strong>

                        <p>
                            Public: ${esc(server.public_address)}
                            • RCON: ${esc(server.rcon_host)}:${Number(server.rcon_port || 0)}
                        </p>

                        <small>
                            Hasło RCON: ${adminController.rconModeLabel(server.rcon_password_mode)}
                            • Hasło sesji: ${server.rotate_password_per_session ? 'rotowane' : 'stałe'}
                            ${server.active_practice_session_id
                                ? ` • Practice: ${esc(server.active_practice_username || 'gracz')} / ${esc(server.active_practice_map || '-')}`
                                : ''}
                        </small>
                    </div>
                </div>

                <div class="admin-game-server-status">
                    <span class="${server.is_enabled ? 'is-online' : 'is-off'}">
                        ${server.is_enabled ? 'Aktywny' : 'Wyłączony'}
                    </span>

                    ${server.active_practice_session_id ? '<span class="is-busy">Zajęty</span>' : '<span class="is-free">Wolny</span>'}
                </div>

                <div class="admin-game-server-actions">
                    <button class="btn-ok compact" onclick="adminController.testGameServer(${Number(server.id)})">
                        Test RCON
                    </button>

                    <button class="btn-ok compact" onclick="adminController.editGameServer(${Number(server.id)})">
                        Edytuj
                    </button>

                    <button class="btn-cancel compact" onclick="adminController.deleteGameServer(${Number(server.id)})">
                        Wyłącz
                    </button>
                </div>
            </article>
        `).join('');
    },

    serverPurposeLabel: (purpose) => {
        const labels = {
            practice: 'Practice',
            match: 'Match',
            both: 'Both'
        };

        return labels[purpose] || 'Both';
    },

    rconModeLabel: (mode) => {
        const labels = {
            db: 'DB encrypted',
            env: 'ENV fallback',
            missing: 'brak'
        };

        return labels[mode] || 'brak';
    },

    resetGameServerForm: () => {
        const form = document.getElementById('admin-game-server-form');
        if (!form) return;

        form.reset();

        document.getElementById('admin-server-id').value = '';
        document.getElementById('admin-server-purpose').value = 'practice';
        document.getElementById('admin-server-rcon-host').value = '127.0.0.1';
        document.getElementById('admin-server-rcon-port').value = '27015';
        document.getElementById('admin-server-rotate-password').checked = true;
        document.getElementById('admin-server-enabled').checked = true;
    },

    editGameServer: (id) => {
        const server = (adminController.gameServers || []).find(item => Number(item.id) === Number(id));

        if (!server) {
            window.Toast.show('Nie znaleziono serwera.', 'error');
            return;
        }

        document.getElementById('admin-server-id').value = server.id;
        document.getElementById('admin-server-name').value = server.name || '';
        document.getElementById('admin-server-purpose').value = server.purpose || 'practice';
        document.getElementById('admin-server-public').value = server.public_address || '';
        document.getElementById('admin-server-connect-password').value = server.connect_password || '';
        document.getElementById('admin-server-rcon-host').value = server.rcon_host || '127.0.0.1';
        document.getElementById('admin-server-rcon-port').value = server.rcon_port || 27015;
        document.getElementById('admin-server-rcon-password').value = '';
        document.getElementById('admin-server-rcon-env').value = server.rcon_password_env || '';
        document.getElementById('admin-server-rotate-password').checked = !!server.rotate_password_per_session;
        document.getElementById('admin-server-enabled').checked = !!server.is_enabled;

        document.getElementById('admin-game-server-form')?.scrollIntoView({
            behavior: 'smooth',
            block: 'center'
        });
    },

    gameServerPayload: () => {
        return {
            id: Number(document.getElementById('admin-server-id')?.value || 0),
            name: document.getElementById('admin-server-name')?.value || '',
            purpose: document.getElementById('admin-server-purpose')?.value || 'practice',
            public_address: document.getElementById('admin-server-public')?.value || '',
            connect_password: document.getElementById('admin-server-connect-password')?.value || '',
            rcon_host: document.getElementById('admin-server-rcon-host')?.value || '127.0.0.1',
            rcon_port: Number(document.getElementById('admin-server-rcon-port')?.value || 27015),
            rcon_password: document.getElementById('admin-server-rcon-password')?.value || '',
            rcon_password_env: document.getElementById('admin-server-rcon-env')?.value || '',
            rotate_password_per_session: document.getElementById('admin-server-rotate-password')?.checked ? 1 : 0,
            is_enabled: document.getElementById('admin-server-enabled')?.checked ? 1 : 0
        };
    },

    saveGameServer: async (event) => {
        event.preventDefault();

        try {
            const response = await window.apiFetch('api.php?action=save_admin_game_server', {
                method: 'POST',
                body: JSON.stringify(adminController.gameServerPayload())
            });

            const data = await response.json();

            if (!data.success) {
                window.Toast.show(data.message || 'Nie udało się zapisać serwera.', 'error');
                return;
            }

            window.Toast.show(data.message || 'Serwer zapisany.', 'success');

            adminController.resetGameServerForm();
            await adminController.load();
        } catch (error) {
            console.error(error);
            window.Toast.show('Błąd podczas zapisywania serwera.', 'error');
        }
    },

    testGameServer: async (id) => {
        try {
            const response = await window.apiFetch('api.php?action=test_admin_game_server_rcon', {
                method: 'POST',
                body: JSON.stringify({ id: Number(id) })
            });

            const data = await response.json();

            if (!data.success) {
                window.Toast.show(data.message || 'Test RCON nie powiódł się.', 'error');
                return;
            }

            console.log('RCON test response:', data.response);
            window.Toast.show(`RCON działa • ${Number(data.duration_ms || 0)} ms`, 'success');
        } catch (error) {
            console.error(error);
            window.Toast.show('Błąd testu RCON.', 'error');
        }
    },

    deleteGameServer: (id) => {
        window.Popout.create(
            'Wyłączyć serwer?',
            'Serwer zostanie wyłączony i nie będzie wybierany do nowych sesji. Historia practice zostanie zachowana.',
            null,
            async () => {
                try {
                    const response = await window.apiFetch('api.php?action=delete_admin_game_server', {
                        method: 'POST',
                        body: JSON.stringify({ id: Number(id) })
                    });

                    const data = await response.json();

                    if (!data.success) {
                        window.Toast.show(data.message || 'Nie udało się wyłączyć serwera.', 'error');
                        return;
                    }

                    window.Toast.show(data.message || 'Serwer wyłączony.', 'success');
                    await adminController.load();
                } catch (error) {
                    console.error(error);
                    window.Toast.show('Błąd podczas wyłączania serwera.', 'error');
                }
            },
            'confirm'
        );
    },

    openTournament: (id) => {
        if (!id) return;

        if (window.tournamentController?.openTournament) {
            window.tournamentController.openTournament(id);
            return;
        }

        history.pushState({ view: 'tournament', id }, '', `/tournament?id=${id}`);
        window.router.navigate('tournament', false);
    },

    openUser: (id) => {
        if (!id) return;

        history.pushState({ view: 'profile', id }, '', `/profile?id=${id}`);
        window.router.navigate('profile', false, { id });
    },

    formatDate: (date) => {
        if (!date) return '-';

        try {
            return new Date(date).toLocaleString('pl-PL');
        } catch (_) {
            return String(date);
        }
    },

    tournamentStatusLabel: (tournament) => {
        const labels = {
            registration_open: 'Zapisy otwarte',
            registration_closed: 'Zapisy zamknięte',
            in_progress: 'W trakcie',
            finished: 'Zakończony',
            cancelled: 'Anulowany'
        };

        if (tournament.status && labels[tournament.status]) {
            return labels[tournament.status];
        }

        return tournament.is_open ? 'Otwarty' : 'Zamknięty';
    },

    activityIcon: (type) => {
        const icons = {
            profile_updated: '👤',
            team_created: '🛡️',
            tournament_created: '🏆',
            tournament_team_joined: '✅',
            tournament_team_pending: '🕓',
            tournament_team_approved: '🎟️'
        };

        return icons[type] || '•';
    }
};