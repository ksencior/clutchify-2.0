let currentUser = null;

import { AppState } from './state.js';

import { authController } from './controllers/AuthController.js';
import { setupController } from './controllers/SetupController.js';
import { teamController } from './controllers/TeamController.js';
import { dashboardController } from './controllers/DashboardController.js';
import { notificationController } from './controllers/NotificationController.js';
import { tournamentController } from './controllers/TournamentsController.js';
import { tournamentViewController } from './controllers/TournamentViewController.js';
import { profileController } from './controllers/ProfileController.js';
import { friendsController } from './controllers/FriendsController.js';
import { settingsController } from './controllers/SettingsController.js';
import { adminController } from './controllers/AdminController.js';
import { playersController } from './controllers/PlayersController.js';
import { playController } from './controllers/PlayController.js';
import { matchLobbyController } from './controllers/MatchLobbyController.js';
import { teamProfileController } from './controllers/TeamProfileController.js';

window.authController = authController;
window.setupController = setupController;
window.friendsController = friendsController;
window.settingsController = settingsController;
window.adminController = adminController;
window.playersController = playersController;
window.notificationController = notificationController;
window.playController = playController;
window.matchLobbyController = matchLobbyController;
window.teamProfileController = teamProfileController;

window.wsClient = null;
window.onlineUsers = new Set();
window.escapeHTML = (value = '') => String(value).replace(/[&<>'"]/g, char => ({
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    "'": '&#039;',
    '"': '&quot;'
}[char]));

window.apiFetch = async (url, options = {}) => {
    const headers = new Headers(options.headers || {});

    const method = (options.method || 'GET').toUpperCase();

    if (!headers.has('Content-Type') && options.body && !(options.body instanceof FormData)) {
        headers.set('Content-Type', 'application/json');
    }

    const csrfToken = AppState.getCsrfToken?.();

    if (csrfToken) {
        headers.set('X-CSRF-Token', csrfToken);
    }

    const response = await fetch(url, {
        ...options,
        method,
        headers
    });

    if (response.status === 403) {
        let data = null;

        try {
            data = await response.clone().json();
        } catch (_) {}

        window.Toast?.show(
            data?.message || 'Sesja bezpieczeństwa wygasła. Odśwież stronę.',
            'error'
        );
    }

    return response;
}

window.bootstrapLoggedInServices = async () => {
    if (!AppState.isLoggedIn()) return;

    notificationController.init();
    window.initWebSocket?.();
};

window.Sound = {
    enabled: localStorage.getItem('notifySoundEnabled') !== 'false',
    audioContext: null,

    toggle() {
        this.enabled = !this.enabled;
        localStorage.setItem('notifySoundEnabled', this.enabled ? 'true' : 'false');

        document.querySelectorAll('[data-sound-state]').forEach(el => {
            el.textContent = this.enabled ? 'Dźwięk: ON' : 'Dźwięk: OFF';
        });

        return this.enabled;
    },

    notify() {
        if (!this.enabled) return;

        try {
            this.audioContext ??= new (window.AudioContext || window.webkitAudioContext)();

            const ctx = this.audioContext;
            const osc = ctx.createOscillator();
            const gain = ctx.createGain();

            osc.type = 'sine';
            osc.frequency.setValueAtTime(880, ctx.currentTime);
            osc.frequency.exponentialRampToValueAtTime(660, ctx.currentTime + 0.12);

            gain.gain.setValueAtTime(0.0001, ctx.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.06, ctx.currentTime + 0.02);
            gain.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + 0.18);

            osc.connect(gain);
            gain.connect(ctx.destination);

            osc.start();
            osc.stop(ctx.currentTime + 0.2);
        } catch (error) {
            console.warn('Nie udało się odtworzyć dźwięku powiadomienia.', error);
        }
    }
};

const getLoggedUserId = () => AppState.getUser()?.id ?? null;

window.currentViewedProfileId = null;

const getVanityUsernameFromPath = (path = window.location.pathname) => {
    const match = path.match(/^\/u\/([^/?#]+)\/?$/i);

    if (!match) return null;

    try {
        return decodeURIComponent(match[1]);
    } catch (_) {
        return match[1];
    }
};

const getProfileRouteIdentifier = (params = {}) => {
    return params.username
        || params.id
        || window.history.state?.username
        || window.history.state?.id
        || getVanityUsernameFromPath()
        || new URLSearchParams(window.location.search).get('username')
        || new URLSearchParams(window.location.search).get('id');
};

const isPublicProfileRoute = (viewName, params = {}) => {
    if (viewName !== 'profile') return false;

    const identifier = getProfileRouteIdentifier(params);

    return !!identifier;
};

const getViewedProfileId = () => {
    const urlParams = new URLSearchParams(window.location.search);

    return window.currentViewedProfileId
        || urlParams.get('id')
        || getLoggedUserId();
};

const getTeamTagFromPath = (path = window.location.pathname) => {
    const match = path.match(/^\/team\/([^/?#]+)\/?$/i);

    if (!match) return null;

    try {
        return decodeURIComponent(match[1]).trim().toUpperCase();
    } catch (_) {
        return match[1].trim().toUpperCase();
    }
};

const getTeamRouteTag = (params = {}) => {
    return params.tag
        || window.history.state?.tag
        || getTeamTagFromPath();
};

const isPublicTeamRoute = (viewName, params = {}) => {
    if (viewName !== 'team') return false;

    return !!getTeamRouteTag(params);
};

const setProfileStatusDot = (status) => {
    const statusDot = document.getElementById('p-current-status');
    if (!statusDot) return;

    const isOnline = status === 'online';
    statusDot.style.background = isOnline ? '#00E676' : '#333333';
    statusDot.setAttribute('title', isOnline ? 'Online' : 'Offline');
};

window.applyCurrentProfileStatus = () => {
    const viewedProfileId = getViewedProfileId();
    if (!viewedProfileId) return;

    setProfileStatusDot(window.onlineUsers.has(String(viewedProfileId)) ? 'online' : 'offline');
};

window.requestOnlineStatuses = () => {
    if (window.wsClient?.readyState === WebSocket.OPEN) {
        window.wsClient.send(JSON.stringify({type: 'request_status'}));
    }
};

window.initWebSocket = () => {
    const userId = getLoggedUserId();
    if (!userId) return;

    if (window.wsClient && [WebSocket.CONNECTING, WebSocket.OPEN].includes(window.wsClient.readyState)) {
        return;
    }

    console.log('Initializing websocket');

    const wsProtocol = window.location.protocol === 'https:' ? 'wss' : 'ws';
    const wsPort = AppState.getWsPort?.() || 8080;

    const resolveWebSocketUrl = () => {
        const wsProtocol = window.location.protocol === 'https:' ? 'wss' : 'ws';
        const hostname = window.location.hostname;

        const isLocalHost =
            hostname === 'localhost'
            || hostname === 'clutchify.test'
            || hostname === '127.0.0.1'
            || hostname === '::1'
            || hostname.startsWith('192.168.')
            || hostname.startsWith('10.')
            || /^172\.(1[6-9]|2\d|3[0-1])\./.test(hostname);

        if (isLocalHost) {
            return `${wsProtocol}://${hostname}:${wsPort}`;
        }

        return `${wsProtocol}://${window.location.host}/ws`;
    };

    window.wsClient = new WebSocket(resolveWebSocketUrl());

    window.wsClient.onopen = () => {
        console.log(`WS connected on ${wsProtocol}://${window.location.hostname}:${wsPort}`);

        const wsToken = AppState.getWsToken?.();

        if (!wsToken) {
            console.warn('Brak tokena WebSocket — auth pominięty.');
            return;
        }

        window.wsClient.send(JSON.stringify({
            type: 'auth',
            token: wsToken
        }));
    };

    window.wsClient.onmessage = (event) => {
        let data;
        try {
            data = JSON.parse(event.data);
        } catch (error) {
            console.error('WS: invalid message', error);
            return;
        }

        if (data.action === 'auth_ok') {
            console.log('WS authenticated as user', data.userId);
        }

        if (data.action === 'auth_failed' || data.action === 'auth_required') {
            console.warn('WS auth problem:', data.message);
            return;
        }

        if (data.action === 'fetch_notifications') {
            notificationController.load();
            Sound.notify();
            Toast.show('Dostałeś nowe powiadomienie.');
        }

        if (data.action === 'fetch_chat') {
            Sound.notify();

            notificationController.load();

            if (window.friendsController) {
                friendsController.onChatNotification(data);
            }
        }

        if (data.action === 'user_status_change') {
            const changedUserId = String(data.user_id);
            console.log(`WS: ${changedUserId} jest teraz ${data.status}`);

            if (data.status === 'online') {
                window.onlineUsers.add(changedUserId);
            } else {
                window.onlineUsers.delete(changedUserId);
            }

            if (String(getViewedProfileId()) === changedUserId) {
                setProfileStatusDot(data.status);
            }

            window.playersController?.refreshOnlineStatuses?.();
        }

        if (data.action === 'initial_status_list') {
            window.onlineUsers = new Set((data.users || []).map(id => String(id)));
            window.applyCurrentProfileStatus();
            window.playersController?.refreshOnlineStatuses?.();
        }

        if (data.action === 'match_lobby_update') {
            const matchId = Number(data.match_id || 0);

            if (
                window.matchLobbyController?.currentId
                && Number(window.matchLobbyController.currentId) === matchId
            ) {
                window.matchLobbyController.load?.();
            }

            if (window.playController?.load) {
                window.playController.load();
            }
        }
    };

    window.wsClient.onclose = () => {
        window.wsClient = null;
        window.onlineUsers.clear();
        window.applyCurrentProfileStatus();
    };

    window.wsClient.onerror = (error) => {
        console.error('WS error:', error);
    };
};

const ViewSkeletons = {
    dashboard: `
        <div style="padding: 20px;">
            <div style="height: 300px; display: flex; justify-content: space-between; align-items: center;">
                <div class="skeleton-box" style="height: 40px; width: 30%;"></div>
                <div class="skeleton-box" style="width: 400px; height: 160px; border-radius: 16px;"></div>
            </div>
            <div class="skeleton-box" style="width: 800px; height: 400px; margin: 0 auto; border-radius: 12px;"></div>
            <div class="skeleton-box" style="width: 100%; height: 600px; border-radius: 8px; margin-top: 20px;"></div>
        </div>
    `,
    teams: `
        <div style="padding: 20px; max-width: 800px; margin: 40px auto;">
            <div style="text-align: center; margin-bottom: 40px;">
                <div class="skeleton-box" style="height: 45px; width: 250px; margin: 0 auto 15px auto;"></div>
                <div class="skeleton-box" style="height: 15px; width: 400px; margin: 0 auto;"></div>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; max-width: 600px; margin: 0 auto;">
                <div class="skeleton-box" style="height: 130px; border-radius: 12px;"></div>
                <div class="skeleton-box" style="height: 130px; border-radius: 12px;"></div>
            </div>
        </div>
    `,
    tournaments: `
    <div style="width: 100%; display: flex; align-items: center;">
        <div class="skeleton-box" style="height: 40px; width: 18%;"></div>
        <div class="skeleton-box" style="height: 40px; width: 8%; margin: 0 20px;"></div>
    </div>
    <div style="margin-top: 20px; display: flex; flex-direction: column; justify-content: start; gap: 10px;">
        <div class="skeleton-box" style="height: 80px;"></div>
        <div class="skeleton-box" style="height: 80px;"></div>
        <div class="skeleton-box" style="height: 80px;"></div>
    </div>
    `,
    tournament: `
        <div style="padding: 40px;">
            <div class="skeleton-box" style="height: 200px; border-radius: 12px; margin-bottom: 20px;"></div>
            <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 20px;">
                <div class="skeleton-box" style="height: 300px; border-radius: 12px;"></div>
                <div class="skeleton-box" style="height: 300px; border-radius: 12px;"></div>
            </div>
        </div>
    `,
    profile: `
        <div style="max-width: 800px; margin: 0 auto; padding: 40px 0;">
            <div class="skeleton-box" style="height: 210px; border-radius: 12px;"></div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 20px;">
                <div class="skeleton-box" style="height: 150px; border-radius: 12px;"></div>
                <div class="skeleton-box" style="height: 150px; border-radius: 12px;"></div>
            </div>
        </div>
    `,
    friends: `
        <div style="display: grid; grid-template-columns: 340px 1fr; gap: 20px; height: calc(100vh - 80px);">
            <div class="skeleton-box" style="border-radius: 16px;"></div>
            <div class="skeleton-box" style="border-radius: 16px;"></div>
        </div>
    `,
    settings: `
        <div style="max-width: 1000px; margin: 0 auto;">
            <div class="skeleton-box" style="height: 120px; border-radius: 18px; margin-bottom: 20px;"></div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                <div class="skeleton-box" style="height: 520px; border-radius: 18px;"></div>
                <div class="skeleton-box" style="height: 520px; border-radius: 18px;"></div>
            </div>
        </div>
    `,
    admin: `
        <div style="max-width: 1180px; margin: 0 auto;">
            <div class="skeleton-box" style="height: 140px; border-radius: 18px; margin-bottom: 20px;"></div>
            <div style="display: grid; grid-template-columns: repeat(6, 1fr); gap: 12px; margin-bottom: 20px;">
                <div class="skeleton-box" style="height: 120px; border-radius: 16px;"></div>
                <div class="skeleton-box" style="height: 120px; border-radius: 16px;"></div>
                <div class="skeleton-box" style="height: 120px; border-radius: 16px;"></div>
                <div class="skeleton-box" style="height: 120px; border-radius: 16px;"></div>
                <div class="skeleton-box" style="height: 120px; border-radius: 16px;"></div>
                <div class="skeleton-box" style="height: 120px; border-radius: 16px;"></div>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                <div class="skeleton-box" style="height: 420px; border-radius: 18px;"></div>
                <div class="skeleton-box" style="height: 420px; border-radius: 18px;"></div>
            </div>
        </div>
    `,
    players: `
        <div style="max-width: 1120px; margin: 0 auto;">
            <div class="skeleton-box" style="height: 140px; border-radius: 18px; margin-bottom: 20px;"></div>
            <div class="skeleton-box" style="height: 120px; border-radius: 18px; margin-bottom: 20px;"></div>
            <div style="display: flex; flex-direction: column; gap: 12px;">
                <div class="skeleton-box" style="height: 130px; border-radius: 16px;"></div>
                <div class="skeleton-box" style="height: 130px; border-radius: 16px;"></div>
                <div class="skeleton-box" style="height: 130px; border-radius: 16px;"></div>
            </div>
        </div>
    `,
    play: `
    <div style="max-width: 1120px; margin: 0 auto;">
        <div class="skeleton-box" style="height: 150px; border-radius: 18px; margin-bottom: 20px;"></div>
        <div class="skeleton-box" style="height: 360px; border-radius: 18px;"></div>
    </div>
    `,
    match: `
        <div style="max-width: 1180px; margin: 0 auto;">
            <div class="skeleton-box" style="height: 180px; border-radius: 18px; margin-bottom: 20px;"></div>
            <div class="skeleton-box" style="height: 160px; border-radius: 18px; margin-bottom: 20px;"></div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                <div class="skeleton-box" style="height: 420px; border-radius: 18px;"></div>
                <div class="skeleton-box" style="height: 420px; border-radius: 18px;"></div>
            </div>
        </div>
    `,
    default: `
        <div style="padding: 40px; max-width: 800px; margin: 0 auto;">
            <div class="skeleton-box" style="height: 40px; width: 40%; margin-bottom: 30px;"></div>
            <div class="skeleton-box" style="height: 200px; border-radius: 12px; margin-bottom: 20px;"></div>
            <div class="skeleton-box" style="height: 200px; border-radius: 12px;"></div>
        </div>
    `
};

const router = {
    navigate: async (viewName, updateUrl = true, params = {}) => {
        const publicProfileRoute    = isPublicProfileRoute(viewName, params);
        const publicTeamRoute       = isPublicTeamRoute(viewName, params);

        if (
            viewName !== 'login'
            && viewName !== 'register'
            && !publicProfileRoute
            && !publicTeamRoute
            && !AppState.isLoggedIn()
        ) {
            viewName = 'login';
        }

        if (viewName === 'admin' && !AppState.getUser()?.player?.isAdmin) {
            window.Toast?.show('Brak uprawnień administratora.', 'error');
            viewName = 'dashboard';
        }

        if ((viewName === 'login' || viewName === 'register') && AppState.isLoggedIn()) {
            viewName = 'dashboard';
        }

        if (viewName === 'login' || viewName === 'register') {
            document.documentElement.classList.add('auth-mode');
        } else {
            document.documentElement.classList.remove('auth-mode');
        }

        authController.checkSession();

        const appContainer = document.getElementById('app');
        appContainer.innerHTML = ViewSkeletons[viewName] || ViewSkeletons.default;

        try {
            const res = await fetch(`views/${viewName}.html?v=${Date.now()}`);
            // wait
            //await new Promise(resolve => setTimeout(resolve, 500));
            if (!res.ok) {
                throw new Error('Nie znaleziono pliku widoku');
            }

            const htmlContent = await res.text();
            appContainer.innerHTML = htmlContent;

            if (updateUrl) {
               if (viewName === 'tournament') {
                    const id = tournamentViewController.currentId ?? new URLSearchParams(location.search).get('id');

                    history.pushState(
                        { view: "tournament", id },
                        "",
                        `/tournament?id=${id}`
                    );
                } else if (viewName === 'profile') {
                    const username = params.username || null;
                    const id = params.id || null;

                    if (username) {
                        history.pushState(
                            {
                                view: 'profile',
                                username
                            },
                            '',
                            `/u/${encodeURIComponent(username)}`
                        );
                    } else if (id) {
                        history.pushState(
                            {
                                view: 'profile',
                                id
                            },
                            '',
                            `/profile?id=${encodeURIComponent(id)}`
                        );
                    } else {
                        history.pushState(
                            {
                                view: 'profile',
                                id: null,
                                username: null
                            },
                            '',
                            `/profile`
                        );
                    }
                } else if (viewName === 'team') {
                    const tag = params.tag || teamProfileController.currentTag || getTeamTagFromPath();

                    history.pushState(
                        {
                            view: 'team',
                            tag
                        },
                        '',
                        `/team/${encodeURIComponent(tag)}`
                    );
                } else if (viewName === 'match') {
                    const id = params.id || matchLobbyController.currentId || new URLSearchParams(location.search).get('id');

                    history.pushState(
                        { view: 'match', id },
                        '',
                        `/match?id=${encodeURIComponent(id)}`
                    );
                } else {
                    history.pushState({view: viewName}, '', `/${viewName}`);
                }
            }
            document.title = `${viewName.toUpperCase()} | Clutchify.gg`
            router.initView(viewName);

        } catch (error) {
            console.error('Router error: ', error);
            appContainer.innerHTML = '<h1>Error 404 :(</h1>';
        }

        document.querySelectorAll('.nav-btn').forEach(btn => {
            btn.classList.remove('active');
        })

        const navViewName = viewName === 'match' ? 'play' : viewName;
        const activeBtn = document.querySelector(`button[onclick="router.navigate('${navViewName}')"]`);
        if (activeBtn) activeBtn.classList.add('active');
    },
    initView: async (viewName) => {
        if (viewName === 'teams') {
            teamController.init();
        } else if (viewName === 'setup') {
            setupController.init();
        } else if (viewName === 'dashboard') {
            dashboardController.init();
        } else if (viewName === 'tournaments') {
            tournamentController.init();
        } else if (viewName === 'tournament') {
            await tournamentViewController.init();
        } else if (viewName === 'profile') {
            await profileController.init();
        } else if (viewName === 'team') {
            await teamProfileController.init();
        } else if (viewName === 'friends') {
            await friendsController.init();
        } else if (viewName === 'settings') {
            await settingsController.init();
        } else if (viewName === 'players') {
            await playersController.init();
        } else if (viewName === 'admin') {
            await adminController.init();
        } else if (viewName === 'play') {
            await playController.init();
        } else if (viewName === 'match') {
            await matchLobbyController.init();
        } 
    }
};

window.router = router;

const Toast = {
    show: (message, type = 'info', duration = 3000) => {
        const container = document.getElementById('toast-container');
        if (!container) return;

        const toastEl = document.createElement('div');
        toastEl.classList.add('toast', type);
        toastEl.innerHTML = `<span>${message}</span>`;

        container.appendChild(toastEl);

        setTimeout(() => {
            toastEl.classList.add('fade-out');
            toastEl.addEventListener('animationend', () => {
                toastEl.remove();
            });
        }, duration);
    }
}

window.Toast = Toast;

const Popout = {
    onClose: null,
    onConfirm: null,
    create: (title, message, onclose = null, onconfirm = null, type = 'info', customHTML = null) => {
        const bg = document.getElementById('popout-overlay');
        const container = document.getElementById('popout-container');
        if (!container || !bg) return;
        bg.classList.add('active');

        Popout.onClose = onclose;
        Popout.onConfirm = onconfirm;

        const popoutEl = document.createElement('div');
        popoutEl.id = "popout";
        popoutEl.classList.add('popout', type);
        let html;
        if (type === 'info') {
            html = `
                <h3>${title}</h3>
                <p>${message}</p>
                <button class="btn-ok" onclick="Popout.close()" style="margin-top: auto;">Zamknij</button>
            `;
        } else if (type === 'confirm') {
            html = `
                <h3>${title}</h3>
                <p>${message}</p>
                <div style="display: flex; align-items: center; justify-content: space-around">
                    <button class="btn-cancel" onclick="Popout.close() " style="margin: 0; margin-top: auto;">Anuluj</button>
                    <button class="btn-confirm" onclick="Popout.confirm()" style="margin: 0; margin-top: auto;">Potwierdź</button>
                </div>
            `;
        } else if (type === 'warning') {
            html = `
                <h3>${title}</h3>
                <p>${message}</p>
                <button class="btn-confirm" onclick="Popout.close()" style="margin-top: auto;">Akceptuję</button>
            `;
        } else if (type === 'custom') {
            if (!customHTML) return;
            html = customHTML;
        }
        popoutEl.innerHTML = html;
        container.append(popoutEl);
    },
    close: () => {
        const bg = document.getElementById('popout-overlay');
        const container = document.getElementById('popout-container');
        if (!container || !bg) return;
        const activePopout = container.querySelector('#popout');
        if (!activePopout) return;
        activePopout.classList.add('fade-out');
        activePopout.addEventListener('animationend', () => {
            activePopout.remove();
            if (typeof Popout.onClose === "function") {
                Popout.onClose();
            }

            Popout.onClose = null;
            Popout.onConfirm = null;
        }, {once: true});
        bg.classList.remove('active');
    },
    confirm: () => {
        const bg = document.getElementById('popout-overlay');
        const container = document.getElementById('popout-container');
        if (!container || !bg) return;
        const activePopout = container.querySelector('#popout');
        if (!activePopout) return;
        activePopout.classList.add('fade-out');
        activePopout.addEventListener('animationend', () => {
            activePopout.remove();
            if (typeof Popout.onConfirm === "function") {
                Popout.onConfirm();
            }

            Popout.onClose = null;
            Popout.onConfirm = null;
        }, {once: true});
        bg.classList.remove('active');
    }
}

window.Popout = Popout;

window.onpopstate = (event) => {
    if (event.state && event.state.view) {
        router.navigate(event.state.view, false, event.state);
        return;
    }
    
    const teamTag = getTeamTagFromPath();
    if (teamTag) {
        router.navigate('team', false, { tag: teamTag });
        return;
    }

    const vanityUsername = getVanityUsernameFromPath();

    if (vanityUsername) {
        router.navigate('profile', false, { username: vanityUsername });
        return;
    }

    router.navigate('dashboard', false);
};
document.addEventListener('DOMContentLoaded', async () => {

    await authController.checkSession();

    await window.bootstrapLoggedInServices?.();
    
    const vanityUsername = getVanityUsernameFromPath();
    const teamTag = getTeamTagFromPath();
    const urlParams = new URLSearchParams(window.location.search);

    let currentPath = window.location.pathname.replace(/^\/+|\/+$/g, '');
    let defaultView = currentPath ? currentPath : 'dashboard';
    let initialParams = {};

    if (teamTag) {
        defaultView = 'team';
        initialParams = {
            tag: teamTag
        }
    } else if (vanityUsername) {
        defaultView = 'profile';
        initialParams = {
            username: vanityUsername
        };
    } else if (defaultView === 'profile') {
        initialParams = {
            id: urlParams.get('id'),
            username: urlParams.get('username')
        };
    } else if (defaultView === 'tournament' || defaultView === 'match') {
        initialParams = {
            id: urlParams.get('id')
        };
    }

    router.navigate(defaultView, false, initialParams);

    const menuEl = document.getElementById('pop-menu');
    const menuBtn = document.getElementById('nav-profile');

    let profileMenuCloseTimer = null;

    const isProfileMenuOpen = () => {
        return menuEl?.classList.contains('is-open');
    };

    const openProfileMenu = () => {
        if (!menuEl || !menuBtn) return;

        clearTimeout(profileMenuCloseTimer);

        menuEl.classList.remove('is-closed', 'is-closing');
        menuEl.style.display = 'block';

        /**
         * Wymuszamy reflow, żeby przeglądarka zauważyła stan początkowy
         * przed dodaniem klasy is-open. Bez tego czasem przeskakuje bez animacji.
         */
        menuEl.offsetHeight;

        requestAnimationFrame(() => {
            menuEl.classList.add('is-open');
            menuEl.setAttribute('aria-hidden', 'false');
        });
    };

    const closeProfileMenu = () => {
        if (!menuEl || !isProfileMenuOpen()) return;

        clearTimeout(profileMenuCloseTimer);

        menuEl.classList.remove('is-open');
        menuEl.classList.add('is-closing');
        menuEl.setAttribute('aria-hidden', 'true');

        const finishClose = () => {
            menuEl.classList.remove('is-closing');
            menuEl.classList.add('is-closed');
            menuEl.style.display = 'none';
        };

        menuEl.addEventListener('transitionend', finishClose, { once: true });

        /**
         * Fallback, bo transitionend może nie odpalić np. przy szybkim klikaniu,
         * zmianie display, reduced motion albo przerwaniu animacji.
         */
        profileMenuCloseTimer = setTimeout(finishClose, 280);
    };

    const toggleProfileMenu = () => {
        if (isProfileMenuOpen()) {
            closeProfileMenu();
        } else {
            openProfileMenu();
        }
    };

    if (menuBtn && menuEl) {
        menuBtn.addEventListener('click', (event) => {
            event.stopPropagation();
            toggleProfileMenu();
        });

        menuEl.addEventListener('click', (event) => {
            const clickedMenuAction = event.target.closest('button, a');

            if (clickedMenuAction) {
                closeProfileMenu();
            }
        });

        document.addEventListener('click', (event) => {
            if (!isProfileMenuOpen()) return;
            if (menuBtn.contains(event.target)) return;
            if (menuEl.contains(event.target)) return;

            closeProfileMenu();
        });

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                closeProfileMenu();
            }
        });
    }

    const notifBtn = document.getElementById('nav-notifications');
    const drawer = document.getElementById('notification-drawer');
    const drawerOverlay = document.getElementById('drawer-overlay');
    const closeDrawerBtn = document.getElementById('btn-close-drawer');

    const toggleDrawer = async (forceState) => {
        const isOpen = drawer.classList.contains('open');
        const newState = forceState !== undefined ? forceState : !isOpen;

        if (newState) {
            drawer.classList.add('open');
            drawerOverlay.classList.add('active');

            if (window.notificationController?.activeTab === 'notifications') {
                await notificationController.markSystemNotificationsSeen();
            }
        } else {
            drawer.classList.remove('open');
            drawerOverlay.classList.remove('active');
        }
    }

    if (notifBtn) notifBtn.addEventListener('click', () => toggleDrawer(true));
    if (closeDrawerBtn) closeDrawerBtn.addEventListener('click', () => toggleDrawer(false));
    if (drawerOverlay) drawerOverlay.addEventListener('click', () => toggleDrawer(false));
});