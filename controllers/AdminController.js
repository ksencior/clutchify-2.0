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
            }
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