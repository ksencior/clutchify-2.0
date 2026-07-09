const esc = (value = '') => window.escapeHTML ? window.escapeHTML(value ?? '') : String(value ?? '');

export const playersController = {
    searchTimer: null,

    init: async () => {
        window.playersController = playersController;
        playersController.bindEvents();
        await playersController.load();
    },

    bindEvents: () => {
        const form = document.getElementById('players-filters-form');
        const search = document.getElementById('players-search');
        const role = document.getElementById('players-role');
        const region = document.getElementById('players-region');
        const faceitMin = document.getElementById('players-faceit-min');
        const resetBtn = document.getElementById('players-reset');

        if (form) {
            form.addEventListener('submit', (event) => {
                event.preventDefault();
                playersController.load();
            });
        }

        [role, region, faceitMin].forEach(el => {
            if (el) {
                el.addEventListener('change', () => playersController.load());
            }
        });

        if (search) {
            search.addEventListener('input', () => {
                clearTimeout(playersController.searchTimer);

                playersController.searchTimer = setTimeout(() => {
                    playersController.load();
                }, 300);
            });
        }

        if (resetBtn) {
            resetBtn.addEventListener('click', () => {
                if (search) search.value = '';
                if (role) role.value = '';
                if (region) region.value = '';
                if (faceitMin) faceitMin.value = '';

                playersController.load();
            });
        }
    },

    getFilters: () => {
        return {
            search: document.getElementById('players-search')?.value.trim() || '',
            role: document.getElementById('players-role')?.value || '',
            region: document.getElementById('players-region')?.value.trim() || '',
            faceit_min: document.getElementById('players-faceit-min')?.value || ''
        };
    },

    load: async () => {
        const container = document.getElementById('players-list');
        const countEl = document.getElementById('players-count');

        if (!container) return;

        container.innerHTML = `
            <div class="empty-state">
                Ładowanie graczy...
            </div>
        `;

        try {
            const filters = playersController.getFilters();
            const params = new URLSearchParams();

            Object.entries(filters).forEach(([key, value]) => {
                if (value !== '') {
                    params.set(key, value);
                }
            });

            const response = await fetch(`api.php?action=get_players_directory&${params.toString()}`);
            const data = await response.json();

            if (!data.success) {
                container.innerHTML = `
                    <div class="empty-state">
                        ${esc(data.message || 'Nie udało się pobrać graczy.')}
                    </div>
                `;
                return;
            }

            if (countEl) {
                countEl.textContent = `${data.players.length} graczy`;
            }

            if (!data.players.length) {
                container.innerHTML = `
                    <div class="empty-state">
                        Nie znaleziono graczy dla wybranych filtrów.
                    </div>
                `;
                return;
            }

            container.innerHTML = data.players
                .map(player => playersController.renderPlayerCard(player))
                .join('');

            playersController.refreshOnlineStatuses();
            window.refreshOnlineStatuses?.();
        } catch (error) {
            console.error(error);

            container.innerHTML = `
                <div class="empty-state">
                    Wystąpił błąd podczas pobierania katalogu graczy.
                </div>
            `;
        }
    },

    renderPlayerCard: (player) => {
        const avatar = player.avatar
            || `https://ui-avatars.com/api/?name=${encodeURIComponent(player.username || 'Gracz')}&background=121212&color=ff002b`;

        const steamUrl = playersController.getSteamProfileUrl(player.steam_id);
        const discordUrl = playersController.getDiscordProfileUrl(player.discord_id);

        const isOnline = window.onlineUsers?.has(String(player.id));

        return `
            <article class="player-directory-card" data-player-card-id="${Number(player.id)}">
                <div class="player-directory-main">
                    <div class="player-directory-avatar-wrap">
                        <img src="${esc(avatar)}" alt="Avatar gracza">

                        <span
                            class="player-directory-status-dot ${isOnline ? 'online' : ''}"
                            data-player-online-id="${Number(player.id)}"
                            title="${isOnline ? 'Online' : 'Offline'}"
                        ></span>
                    </div>

                    <div class="player-directory-info">
                        <div class="player-directory-top">
                            <h3>${esc(player.username)}</h3>
                            <span>${esc(player.preferred_role_label || 'Nie ustawiono')}</span>

                            <small
                                class="player-directory-online-label"
                                data-player-online-label="${Number(player.id)}"
                            >
                                ${isOnline ? 'Online' : 'Offline'}
                            </small>
                        </div>

                        <p>${esc(player.bio || 'Ten gracz nie dodał jeszcze opisu profilu.')}</p>

                        <div class="player-directory-tags">
                            <span>${esc(player.school || 'Brak szkoły / org.')}</span>
                        </div>

                        ${playersController.renderMiniBadges(player.badges || [])}

                        <small>
                            Dostępność: ${esc(player.availability || 'Brak informacji')}
                        </small>
                    </div>
                </div>

                <div class="player-directory-actions">
                    <button class="btn-ok compact" onclick="playersController.openProfile(${Number(player.id)}, '${playersController.jsArg(player.username)}')">
                        Profil
                    </button>

                    ${playersController.renderFriendAction(player)}

                    ${steamUrl ? `
                        <a
                            class="btn-ok compact player-link-btn"
                            href="${esc(steamUrl)}"
                            target="_blank"
                            rel="noopener noreferrer"
                        >
                            Steam ↗
                        </a>
                    ` : ''}

                    ${discordUrl ? `
                        <a
                            class="btn-ok compact player-link-btn"
                            href="${esc(discordUrl)}"
                            target="_blank"
                            rel="noopener noreferrer"
                        >
                            Discord ↗
                        </a>
                    ` : ''}
                </div>
            </article>
        `;
    },
    renderMiniBadges: (badges = []) => {
        if (!badges.length) return '';

        return `
            <div class="player-directory-badges">
                ${badges.slice(0, 4).map(badge => `
                    <span class="profile-badge badge-${esc(badge.type)}">
                        ${esc(badge.label)}
                    </span>
                `).join('')}
            </div>
        `;
    },

    openProfile: (id, username = null) => {
        if (username) {
            window.router.navigate('profile', true, {
                id: Number(id),
                username
            });
            return;
        }

        window.router.navigate('profile', true, {
            id: Number(id)
        });
    },

    getSteamProfileUrl: (steamId) => {
        if (!steamId) return null;

        const value = String(steamId).trim();

        if (/^https?:\/\//i.test(value)) {
            return value;
        }

        if (/^\d{15,20}$/.test(value)) {
            return `https://steamcommunity.com/profiles/${encodeURIComponent(value)}`;
        }

        return `https://steamcommunity.com/id/${encodeURIComponent(value)}`;
    },

    getDiscordProfileUrl: (discordId) => {
        if (!discordId) return null;

        const value = String(discordId).trim();

        if (/^\d{10,25}$/.test(value)) {
            return `https://discord.com/users/${encodeURIComponent(value)}`;
        }

        return null;
    },

    renderFriendAction: (player) => {
        const id = Number(player.id);
        const username = playersController.jsArg(player.username || 'Gracz');

        if (player.friend_status === 'me') {
            return `
                <button class="btn-ok compact" onclick="playersController.openProfile(${id}, '${username}')">
                    Twój profil
                </button>
            `;
        }

        if (player.friend_status === 'accepted') {
            return `
                <button class="btn-confirm compact" onclick="playersController.openChat(${id}, '${username}')">
                    Napisz
                </button>
            `;
        }

        if (player.friend_status === 'pending_sent') {
            return `
                <button class="btn-ok compact" disabled>
                    Wysłano
                </button>
            `;
        }

        if (player.friend_status === 'pending_received') {
            return `
                <button class="btn-confirm compact" onclick="playersController.acceptFriendRequest(${Number(player.friendship_id)})">
                    Akceptuj
                </button>
            `;
        }

        return `
            <button class="btn-confirm compact" onclick="playersController.sendFriendRequest(${id})">
                Dodaj znajomego
            </button>
        `;
    },

    sendFriendRequest: async (targetId) => {
        const response = await window.apiFetch('api.php?action=send_friend_request', {
            method: 'POST',
            body: JSON.stringify({
                target_id: targetId
            })
        });

        const data = await response.json();

        if (data.success) {
            window.Toast.show(data.message, 'success');

            if (window.wsClient?.readyState === WebSocket.OPEN && data.targetId) {
                window.wsClient.send(JSON.stringify({
                    type: 'notify',
                    targetId: data.targetId
                }));
            }

            await playersController.load();
        } else {
            window.Toast.show(data.message || 'Nie udało się wysłać zaproszenia.', 'error');
        }
    },

    acceptFriendRequest: async (friendshipId) => {
        const response = await window.apiFetch('api.php?action=respond_friend_request', {
            method: 'POST',
            body: JSON.stringify({
                friendship_id: friendshipId,
                action: 'accept'
            })
        });

        const data = await response.json();

        if (data.success) {
            window.Toast.show(data.message || 'Dodano do znajomych.', 'success');
            await playersController.load();

            if (window.notificationController?.load) {
                notificationController.load();
            }
        } else {
            window.Toast.show(data.message || 'Nie udało się zaakceptować zaproszenia.', 'error');
        }
    },

    openChat: (id, username) => {
        history.pushState(
            {
                view: 'friends',
                friendId: Number(id)
            },
            '',
            `/friends?chat=${Number(id)}`
        );

        window.router.navigate('friends', false);
    },

    refreshOnlineStatuses: () => {
        document.querySelectorAll('[data-player-online-id]').forEach(dot => {
            const id = String(dot.dataset.playerOnlineId);
            const isOnline = window.onlineUsers?.has(id);

            dot.classList.toggle('online', !!isOnline);
            dot.setAttribute('title', isOnline ? 'Online' : 'Offline');
        });

        document.querySelectorAll('[data-player-online-label]').forEach(label => {
            const id = String(label.dataset.playerOnlineLabel);
            const isOnline = window.onlineUsers?.has(id);

            label.textContent = isOnline ? 'Online' : 'Offline';
        });
    },

    jsArg: (value = '') => {
        return String(value)
            .replace(/\\/g, '\\\\')
            .replace(/'/g, "\\'")
            .replace(/\n/g, ' ');
    }
};