import { AppState } from "../state.js";


export const dashboardController = {

    init: () => {
        window.dashboardController = dashboardController;
        window.authController.checkSession();
        dashboardController.renderPlayer();
        dashboardController.loadTournaments();
        dashboardController.loadActivityFeed();
    },
    renderPlayer: () => {
        const usernameEl = document.getElementById('dashboard-username');
        const avatarEl = document.getElementById('dashboard-avatar');
        const teamEl = document.getElementById('dashboard-team');

        const welcomeEl = document.getElementById('dashboard-welcome-text');

        welcomeEl.innerText = `Witaj, ${AppState.isLoggedIn() ? AppState.getUser().username : "..."}`

        usernameEl.innerText = AppState.isLoggedIn() ? AppState.getUser().username : 'Nowy_Gracz';
        teamEl.innerText = AppState.getUser().player.team_id !== null? AppState.getUser().player.team_name : "Brak drużyny";
        avatarEl.src = `https://ui-avatars.com/api/?name=${AppState.isLoggedIn() ? AppState.getUser().username : 'P'}&background=121212&color=ff002b`;

        if (!localStorage.getItem("startPopoutSeen")) {
            window.Popout.create("Witaj na Clutchify.gg!", `Hej, ${AppState.getUser().username}! Miej na uwadze, że aplikacja dalej jest w wczesnej fazie alpha. Możesz przyczynić się do ulepszania jej,
            zgłaszając błędy na githubie! Miłego korzystania z Clutchify.gg :)`, () => {
                localStorage.setItem('startPopoutSeen', true);
            });
        }
    },
    loadTournaments: async () => {
        const container = document.getElementById('upcoming-tournaments');
        if (!container) return;
        container.innerHTML = '';

        const response = await fetch('api.php?action=get_open_tournaments');
        const data = await response.json();

        if (data.success) {
            if (data.items.length === 0) {
                container.innerHTML = '<div class="empty-state">Brak otwartych turniejów. Wróć później albo utwórz własny event.</div>';
                return;
            }

            data.items.forEach(tournament => {
                const dateFormated = tournament.sign_in_end
                    ? tournament.sign_in_end.toString()
                    : 'Nie ustawiono';

                const tItem = document.createElement('article');

                tItem.classList.add('dashboard-tournament-card');
                tItem.id = `tournament-${tournament.id}`;

                tItem.innerHTML = `
                    <div class="tournament-card-accent">🏆</div>

                    <div>
                        <span class="tournament-card-label">Otwarte zapisy</span>
                        <h3>${window.escapeHTML(tournament.title)}</h3>
                        <p>Organizator: ${window.escapeHTML(tournament.creator)}</p>
                    </div>

                    <div class="tournament-card-footer">
                        <span>Zapisy do: ${window.escapeHTML(dateFormated)}</span>
                        <strong>Zobacz →</strong>
                    </div>
                `;

                container.append(tItem);

                tItem.onclick = () => {
                    window.history.pushState(
                        { view: 'tournament', id: tournament.id },
                        '',
                        `/tournament?id=${tournament.id}`
                    );

                    window.router.navigate('tournament', false);
                };
            });
        } else {
            window.Toast.show('Wystąpił błąd: ' + data.message, 'error');
        }
    },
    loadActivityFeed: async () => {
        const container = document.getElementById('dashboard-activity-feed');
        if (!container) return;

        try {
            const response = await fetch('api.php?action=get_activity_feed&limit=10');
            const data = await response.json();

            if (!data.success) {
                container.innerHTML = `<div class="empty-state">${window.escapeHTML(data.message || 'Nie udało się pobrać aktywności.')}</div>`;
                return;
            }

            if (!data.items.length) {
                container.innerHTML = `
                    <div class="empty-state">
                        Brak aktywności. Utwórz profil, drużynę albo turniej, żeby coś tu zobaczyć.
                    </div>
                `;
                return;
            }

            container.innerHTML = data.items
                .map(item => dashboardController.renderActivityItem(item))
                .join('');
        } catch (error) {
            console.error(error);
            container.innerHTML = '<div class="empty-state">Nie udało się pobrać aktywności.</div>';
        }
    },

    renderActivityItem: (item) => {
        const avatar = item.actor_avatar
            || `https://ui-avatars.com/api/?name=${encodeURIComponent(item.actor_username || 'C')}&background=121212&color=ff002b`;

        const icon = dashboardController.activityIcon(item.type);
        const date = item.created_at
            ? new Date(item.created_at).toLocaleString('pl-PL')
            : '';

        const targetButton = dashboardController.renderActivityTarget(item);

        return `
            <article class="activity-feed-item activity-type-${window.escapeHTML(item.type)}">
                <div class="activity-icon">${icon}</div>

                <img class="activity-avatar" src="${window.escapeHTML(avatar)}" alt="Avatar">

                <div class="activity-main">
                    <div class="activity-topline">
                        <strong>${window.escapeHTML(item.title)}</strong>
                        <span>${window.escapeHTML(date)}</span>
                    </div>

                    <p>${window.escapeHTML(item.message)}</p>
                </div>

                ${targetButton}
            </article>
        `;
    },

    activityIcon: (type) => {
        const icons = {
            profile_updated: '👤',
            team_created: '🛡️',
            tournament_created: '🏆',
            tournament_team_joined: '✅',
            tournament_team_approved: '🎟️'
        };

        return icons[type] || '•';
    },

    renderActivityTarget: (item) => {
        if (item.target_type === 'tournament' && item.target_id) {
            return `
                <button
                    class="btn-ok compact"
                    onclick="window.tournamentController.openTournament(${Number(item.target_id)})"
                >
                    Zobacz
                </button>
            `;
        }

        if (item.target_type === 'user' && item.target_id) {
            return `
                <button
                    class="btn-ok compact"
                    onclick="history.pushState({view: 'profile', id: ${Number(item.target_id)}}, '', '/profile?id=${Number(item.target_id)}'); router.navigate('profile', false, {id: ${Number(item.target_id)}})"
                >
                    Profil
                </button>
            `;
        }

        return '';
    }
}