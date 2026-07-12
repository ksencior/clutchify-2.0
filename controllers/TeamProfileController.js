export const teamProfileController = {
    currentTag: null,
    data: null,

    init: async () => {
        window.teamProfileController = teamProfileController;

        const tag = teamProfileController.resolveTeamTag();

        if (!tag) {
            teamProfileController.renderMissing();
            return;
        }

        teamProfileController.currentTag = tag;
        await teamProfileController.load(tag);
    },

    resolveTeamTag: () => {
        const stateTag = window.history.state?.tag || null;

        if (stateTag) {
            return String(stateTag).trim().toUpperCase();
        }

        const match = window.location.pathname.match(/^\/team\/([^/?#]+)\/?$/i);

        if (!match) {
            return '';
        }

        try {
            return decodeURIComponent(match[1]).trim().toUpperCase();
        } catch (_) {
            return match[1].trim().toUpperCase();
        }
    },

    isValidTag: (tag) => /^[A-Z0-9_-]{1,5}$/.test(String(tag || '').trim().toUpperCase()),

    open: (tag) => {
        const value = String(tag || '').trim().toUpperCase();

        if (!teamProfileController.isValidTag(value)) {
            window.Toast?.show('Nieprawidłowy tag drużyny.', 'error');
            return;
        }

        history.pushState(
            {
                view: 'team',
                tag: value
            },
            '',
            `/team/${encodeURIComponent(value)}`
        );

        router.navigate('team', false, { tag: value });
    },

    load: async (tag) => {
        const value = String(tag || '').trim().toUpperCase();

        if (!teamProfileController.isValidTag(value)) {
            teamProfileController.renderMissing('Nieprawidłowy tag drużyny.');
            return;
        }

        try {
            const response = await fetch(`api.php?action=get_team_public&tag=${encodeURIComponent(value)}`);
            const data = await response.json();

            if (!data.success) {
                teamProfileController.renderMissing(data.message || 'Nie znaleziono drużyny.');
                return;
            }

            teamProfileController.data = data;
            teamProfileController.currentTag = data.team?.tag || value;

            teamProfileController.render(data);
        } catch (error) {
            console.error(error);
            teamProfileController.renderMissing('Błąd podczas ładowania drużyny.');
        }
    },

    renderMissing: (message = 'Nie znaleziono drużyny.') => {
        const hero = document.getElementById('team-public-hero');
        const roster = document.getElementById('team-public-roster');
        const info = document.getElementById('team-public-info');

        if (hero) {
            hero.className = 'team-public-hero';
            hero.innerHTML = `
                <div class="team-public-hero-main">
                    <div class="team-public-logo">
                        <span>?</span>
                    </div>

                    <div>
                        <span class="eyebrow">Drużyna</span>
                        <h1>Nie znaleziono</h1>
                        <p>${window.escapeHTML(message)}</p>
                    </div>
                </div>
            `;
        }

        if (roster) {
            roster.innerHTML = '<div class="empty-state">Brak składu do pokazania.</div>';
        }

        if (info) {
            info.innerHTML = '';
        }
    },

    render: (data) => {
        const team = data.team;
        const members = data.members || [];
        const viewer = data.viewer || {};

        teamProfileController.renderHero(team, viewer);
        teamProfileController.renderRoster(members);
        teamProfileController.renderInfo(team, viewer);

        document.title = `[${team.tag}] ${team.name} | Clutchify.gg`;
    },

    renderHero: (team, viewer) => {
        const hero = document.getElementById('team-public-hero');
        if (!hero) return;

        const logo = team.logo || `https://api.dicebear.com/7.x/identicon/svg?seed=${encodeURIComponent(team.name || team.tag || 'Team')}`;
        const statusLabel = team.is_open && !team.is_full ? 'Rekrutacja otwarta' : 'Rekrutacja zamknięta';

        hero.className = 'team-public-hero';
        hero.innerHTML = `
            <div class="team-public-hero-main">
                <img class="team-public-logo" src="${window.escapeHTML(logo)}" alt="Logo drużyny">

                <div>
                    <span class="eyebrow">Publiczny profil drużyny</span>

                    <h1>
                        <span>[${window.escapeHTML(team.tag || 'TEAM')}]</span>
                        ${window.escapeHTML(team.name || 'Drużyna')}
                    </h1>

                    <p>
                        Lider: <strong>${window.escapeHTML(team.captain_username || 'Nieznany')}</strong>
                        • Skład: ${Number(team.members_count || 0)}/6
                    </p>

                    <div class="team-public-badges">
                        <span class="${team.is_open && !team.is_full ? 'is-open' : 'is-closed'}">${statusLabel}</span>
                        ${viewer.is_member ? '<span>Twoja drużyna</span>' : ''}
                        ${viewer.is_captain ? '<span>Lider</span>' : ''}
                    </div>
                </div>
            </div>

            <div class="team-public-actions">
                ${teamProfileController.renderHeroAction(team, viewer)}

                <button class="btn-ok compact" onclick="teamProfileController.copyLink()">
                    Kopiuj link
                </button>
            </div>
        `;
    },

    renderHeroAction: (team, viewer) => {
        if (!viewer.is_logged_in) {
            return `
                <button class="btn-confirm compact" onclick="history.pushState({view:'login'}, '', '/login'); router.navigate('login', false)">
                    Zaloguj, aby aplikować
                </button>
            `;
        }

        if (viewer.is_member) {
            return `
                <button class="btn-ok compact" onclick="history.pushState({view:'teams'}, '', '/teams'); router.navigate('teams', false)">
                    Panel drużyny
                </button>
            `;
        }

        if (viewer.team_id) {
            return `
                <button class="btn-ok compact" disabled>
                    Masz już drużynę
                </button>
            `;
        }

        if (team.is_full) {
            return `
                <button class="btn-cancel compact" disabled>
                    Pełny skład
                </button>
            `;
        }

        if (!team.is_open) {
            return `
                <button class="btn-cancel compact" disabled>
                    Rekrutacja zamknięta
                </button>
            `;
        }

        if (viewer.join_request_status === 'pending') {
            return `
                <button class="btn-ok compact" disabled>
                    Prośba wysłana
                </button>
            `;
        }

        return `
            <button class="btn-confirm compact" onclick="teamProfileController.requestJoin()">
                Aplikuj do drużyny
            </button>
        `;
    },

    renderRoster: (members) => {
        const container = document.getElementById('team-public-roster');
        if (!container) return;

        if (!members.length) {
            container.innerHTML = '<div class="empty-state">Ta drużyna nie ma jeszcze graczy.</div>';
            return;
        }

        container.innerHTML = members.map(member => {
            const avatar = member.avatar || `https://api.dicebear.com/7.x/avataaars/svg?seed=${encodeURIComponent(member.username || 'Player')}`;

            return `
                <article class="team-public-member">
                    <img src="${window.escapeHTML(avatar)}" alt="Avatar">

                    <div>
                        <strong>${window.escapeHTML(member.username || 'Gracz')}</strong>

                        <span>
                            ${member.is_captain ? 'Lider' : (member.is_substitute ? 'Rezerwowy' : 'Gracz')}
                            ${member.preferred_role ? ` • ${window.escapeHTML(teamProfileController.roleLabel(member.preferred_role))}` : ''}
                            ${member.faceit_level ? ` • Faceit ${Number(member.faceit_level)}` : ''}
                        </span>
                    </div>

                    <button class="btn-ok compact" onclick="teamProfileController.openPlayer('${window.escapeHTML(member.username || '')}')">
                        Profil
                    </button>
                </article>
            `;
        }).join('');
    },

    renderInfo: (team, viewer) => {
        const container = document.getElementById('team-public-info');
        if (!container) return;

        container.innerHTML = `
            <div class="team-public-info-row">
                <span>Link</span>
                <strong>/team/${window.escapeHTML(team.tag || '')}</strong>
            </div>

            <div class="team-public-info-row">
                <span>Tag</span>
                <strong>${window.escapeHTML(team.tag || '-')}</strong>
            </div>

            <div class="team-public-info-row">
                <span>Lider</span>
                <strong>${window.escapeHTML(team.captain_username || '-')}</strong>
            </div>

            <div class="team-public-info-row">
                <span>Skład</span>
                <strong>${Number(team.members_count || 0)}/6</strong>
            </div>

            <div class="team-public-info-row">
                <span>Status</span>
                <strong>${team.is_open && !team.is_full ? 'Otwarta rekrutacja' : 'Zamknięta'}</strong>
            </div>

            <div class="team-public-info-row">
                <span>Utworzono</span>
                <strong>${team.created_at ? new Date(team.created_at.replace(' ', 'T')).toLocaleDateString('pl-PL') : '-'}</strong>
            </div>

            ${viewer.join_request_status ? `
                <div class="team-public-info-row">
                    <span>Twoja prośba</span>
                    <strong>${teamProfileController.requestStatusLabel(viewer.join_request_status)}</strong>
                </div>
            ` : ''}
        `;
    },

    requestJoin: async () => {
        const teamId = Number(teamProfileController.data?.team?.id || 0);

        if (!teamId) {
            window.Toast.show('Brak ID drużyny.', 'error');
            return;
        }

        try {
            const response = await window.apiFetch('api.php?action=request_join_team', {
                method: 'POST',
                body: JSON.stringify({
                    team_id: teamId
                })
            });

            const data = await response.json();

            if (!data.success) {
                window.Toast.show(data.message || 'Nie udało się wysłać prośby.', 'error');
                return;
            }

            window.Toast.show(data.message || 'Prośba została wysłana.', 'success');

            if (data.targetId && window.wsClient?.readyState === WebSocket.OPEN) {
                window.wsClient.send(JSON.stringify({
                    type: 'notify',
                    targetId: Number(data.targetId)
                }));
            }

            await teamProfileController.load(teamProfileController.currentTag);
        } catch (error) {
            console.error(error);
            window.Toast.show('Błąd podczas wysyłania prośby.', 'error');
        }
    },

    copyLink: async () => {
        const tag = teamProfileController.data?.team?.tag || teamProfileController.currentTag;
        const url = `${window.location.origin}/team/${encodeURIComponent(tag)}`;

        try {
            await navigator.clipboard.writeText(url);
            window.Toast.show('Skopiowano link do drużyny.', 'success');
        } catch (_) {
            window.Toast.show(url, 'info');
        }
    },

    openPlayer: (username) => {
        const value = String(username || '').trim();

        if (!value) return;

        history.pushState(
            {
                view: 'profile',
                username: value
            },
            '',
            `/u/${encodeURIComponent(value)}`
        );

        router.navigate('profile', false, {
            username: value
        });
    },

    roleLabel: (role) => {
        const labels = {
            entry: 'Entry',
            rifler: 'Rifler',
            awper: 'AWPer',
            igl: 'IGL',
            lurker: 'Lurker',
            support: 'Support'
        };

        return labels[role] || role;
    },

    requestStatusLabel: (status) => {
        const labels = {
            pending: 'Oczekuje',
            accepted: 'Zaakceptowana',
            rejected: 'Odrzucona',
            cancelled: 'Anulowana'
        };

        return labels[status] || status;
    }
};

window.teamProfileController = teamProfileController;