import { AppState } from "../state.js";

const getVanityUsernameFromPath = () => {
    const match = window.location.pathname.match(/^\/u\/([^/?#]+)\/?$/i);

    if (!match) return null;

    try {
        return decodeURIComponent(match[1]);
    } catch (_) {
        return match[1];
    }
};

export const profileController = {
    init: async () => {
        window.profileController = profileController;

        const urlParams = new URLSearchParams(window.location.search);

        let targetUsername = window.history.state?.username
            || getVanityUsernameFromPath()
            || urlParams.get('username');

        let targetId = window.history.state?.id
            || urlParams.get('id');

        if (!targetUsername && (!targetId || Number(targetId) === 0)) {
            const currentUser = AppState.getUser();

            if (!currentUser?.id) {
                window.Toast.show('Zaloguj się, aby zobaczyć swój profil.', 'error');
                window.router.navigate('login');
                return;
            }

            targetId = currentUser.id;
        }

        await profileController.loadProfile({
            id: targetId,
            username: targetUsername
        });
    },

    loadProfile: async ({ id = null, username = null } = {}) => {
        try {
            const params = new URLSearchParams({
                action: 'get_profile'
            });

            if (username) {
                params.set('username', username);
            } else if (id) {
                params.set('id', id);
            }

            const response = await fetch(`api.php?${params.toString()}`);
            const data = await response.json();

            if (!data.success) {
                window.Toast.show(data.message || 'Nie udało się pobrać profilu.', 'error');

                if (AppState.isLoggedIn()) {
                    window.router.navigate('dashboard');
                } else {
                    window.router.navigate('login');
                }

                return;
            }

            const profile = data.profile;

            profileController.renderProfile(profile);

            window.currentViewedProfileId = String(profile.id);
            document.title = `${profile.username} | Clutchify.gg`;

            window.requestOnlineStatuses?.();
            window.applyCurrentProfileStatus?.();

        } catch (error) {
            console.error(error);
            window.Toast.show('Błąd podczas ładowania profilu.', 'error');
        }
    },

    renderProfile: (profile) => {
        window.currentViewedProfileId = String(profile.id);
        document.getElementById('p-view-username').innerText = profile.username;
        
        profileController.renderConnectedAccounts(profile);

        profileController.renderProfileCompleteness(profile.profile_completeness, profile.is_me);
        profileController.renderBadges(profile.badges || []);

        const bioEl = document.getElementById('p-view-bio');
        if (bioEl) bioEl.innerText = profile.bio || 'Ten gracz nie dodał jeszcze opisu profilu.';

        const roleEl = document.getElementById('p-view-role');
        if (roleEl) roleEl.innerText = profile.preferred_role_label || 'Nie ustawiono';

        const faceitEl = document.getElementById('p-view-faceit');
        if (faceitEl) faceitEl.innerText = profile.faceit_level ? `Faceit ${profile.faceit_level}` : 'Faceit -';

        const regionEl = document.getElementById('p-view-region');
        if (regionEl) regionEl.innerText = profile.region || 'EU';

        const schoolEl = document.getElementById('p-view-school');
        if (schoolEl) schoolEl.innerText = profile.school || 'Brak szkoły / organizacji';

        const availabilityEl = document.getElementById('p-view-availability');
        if (availabilityEl) availabilityEl.innerText = profile.availability || 'Brak informacji o dostępności';

        const createdAtEl = document.getElementById('p-view-created-at');
        if (createdAtEl && profile.created_at) {
            createdAtEl.innerText = new Date(profile.created_at).toLocaleDateString('pl-PL');
        }
        
        // Zabezpieczenie braku avatara
        const defaultAvatar = `https://ui-avatars.com/api/?name=${profile.username}&background=121212&color=ff002b`;
        document.getElementById('p-view-avatar').src = profile.avatar || defaultAvatar;

        // Renderowanie info o drużynie
        const teamSection = document.getElementById('p-view-team-section');
        if (profile.team_name) {
            teamSection.innerHTML = `
                <img src="${profile.team_logo}" style="width: 20px; height: 20px; border-radius: 4px; object-fit: cover;">
                <span style="background: rgba(255,0,43,0.1); color: var(--brand-red); padding: 2px 6px; border-radius: 4px; font-size: 11px; font-weight: bold;">${profile.team_tag}</span>
                <span style="color: var(--text-gray); font-size: 14px;">${profile.team_name}</span>
            `;
        } else {
            teamSection.innerHTML = `<span style="color: var(--text-gray); font-size: 13px;">Wolny Agent (Brak drużyny)</span>`;
        }

        // Renderowanie przycisków
        const actionsContainer = document.getElementById('p-view-actions');
        if (!AppState.isLoggedIn() || profile.friend_status === 'guest') {
            actionsContainer.innerHTML = `
                <button
                    class="nav-btn active"
                    style="padding: 8px 15px; font-size: 12px;"
                    onclick="router.navigate('login')"
                >
                    Zaloguj się, aby dodać
                </button>

                <button
                    class="btn-ok"
                    style="padding: 8px 15px; font-size: 12px;"
                    onclick="router.navigate('register')"
                >
                    Załóż konto
                </button>
            `;
            return;
        }
        if (profile.is_me) {
            actionsContainer.innerHTML = `
                <button
                    class="nav-btn active"
                    style="padding: 8px 15px; font-size: 12px;"
                    onclick="router.navigate('settings')"
                >
                    Edytuj Profil
                </button>
            `;
            return;
        }

        if (profile.friend_status === 'accepted') {
            actionsContainer.innerHTML = `
                <button
                    class="nav-btn active"
                    style="padding: 8px 15px; font-size: 12px;"
                    onclick="history.pushState({view: 'friends', friendId: ${Number(profile.id)}}, '', '/friends'); router.navigate('friends', false)"
                >
                    Napisz wiadomość
                </button>

                <button class="btn-cancel" style="padding: 8px 15px; font-size: 12px;">
                    Zgłoś
                </button>
            `;
            return;
        }

        if (profile.friend_status === 'pending_sent') {
            actionsContainer.innerHTML = `
                <button class="btn-ok" style="padding: 8px 15px; font-size: 12px;" disabled>
                    Zaproszenie wysłane
                </button>

                <button class="btn-cancel" style="padding: 8px 15px; font-size: 12px;">
                    Zgłoś
                </button>
            `;
            return;
        }

        if (profile.friend_status === 'pending_received') {
            actionsContainer.innerHTML = `
                <button
                    class="nav-btn active"
                    style="padding: 8px 15px; font-size: 12px;"
                    onclick="friendsController.respondRequest(${Number(profile.friendship_id)}, 'accept').then(() => profileController.loadProfile(${Number(profile.id)}))"
                >
                    Akceptuj znajomego
                </button>

                <button class="btn-cancel" style="padding: 8px 15px; font-size: 12px;">
                    Zgłoś
                </button>
            `;
            return;
        }

        actionsContainer.innerHTML = `
            <button
                class="nav-btn active"
                style="padding: 8px 15px; font-size: 12px;"
                onclick="friendsController.sendFriendRequest(${Number(profile.id)}).then(() => profileController.loadProfile(${Number(profile.id)}))"
            >
                Dodaj do znajomych
            </button>

            <button class="btn-cancel" style="padding: 8px 15px; font-size: 12px;">
                Zgłoś
            </button>
        `;
    },
    renderConnectedAccounts: (profile) => {
        const steamEl = document.getElementById('p-view-steam');
        const discordEl = document.getElementById('p-view-discord');

        if (steamEl) {
            profileController.renderExternalAccountLink(
                steamEl,
                profile.steam_id,
                profileController.getSteamProfileUrl(profile.steam_id),
                'Otwórz profil Steam'
            );
        }

        if (discordEl) {
            profileController.renderExternalAccountLink(
                discordEl,
                profile.discord_id,
                profileController.getDiscordProfileUrl(profile.discord_id),
                'Otwórz profil Discord'
            );
        }
    },

    renderExternalAccountLink: (element, value, url, label) => {
        if (!value || !url) {
            element.innerHTML = `<span class="profile-account-empty">Brak połączenia</span>`;
            return;
        }

        element.innerHTML = `
            <a
                class="profile-account-link"
                href="${window.escapeHTML(url)}"
                target="_blank"
                rel="noopener noreferrer"
            >
                ${window.escapeHTML(label)}
                <span>↗</span>
            </a>
        `;
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
    renderProfileCompleteness: (completeness, isMe = false) => {
        const wrap = document.getElementById('p-view-completeness');
        const bar = document.getElementById('p-view-completeness-bar');
        const text = document.getElementById('p-view-completeness-text');
        const missing = document.getElementById('p-view-completeness-missing');
        const action = document.getElementById('p-view-completeness-action');

        if (!wrap || !bar || !text || !missing || !completeness) return;

        if (action) {
            action.style.display = isMe ? 'inline-flex' : 'none';
        }

        const percent = Number(completeness.percent || 0);

        bar.style.width = `${percent}%`;
        text.textContent = `${percent}% uzupełnienia profilu`;

        const missingItems = completeness.missing || [];

        if (!missingItems.length) {
            missing.innerHTML = `
                <li>Profil jest kompletny. Kozak.</li>
            `;
            return;
        }

        missing.innerHTML = missingItems
            .slice(0, 4)
            .map(item => `<li>${window.escapeHTML(item.label)}</li>`)
            .join('');
    },

    renderBadges: (badges = []) => {
        const container = document.getElementById('p-view-badges');
        if (!container) return;

        if (!badges.length) {
            container.innerHTML = `
                <span class="profile-badge is-empty">Brak odznak</span>
            `;
            return;
        }

        container.innerHTML = badges
            .map(badge => `
                <span
                    class="profile-badge badge-${window.escapeHTML(badge.type)}"
                    title="${window.escapeHTML(badge.description || badge.label)}"
                >
                    ${window.escapeHTML(badge.label)}
                </span>
            `)
            .join('');
    },
};