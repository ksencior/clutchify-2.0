let currentUser = null;

import { AppState } from './state.js';

import { authController } from './controllers/AuthController.js';
import { setupController } from './controllers/SetupController.js';
import { teamController } from './controllers/TeamController.js';
import { dashboardController } from './controllers/DashboardController.js';
import { notificationController } from './controllers/NotificationController.js';

window.authController = authController;
window.setupController = setupController;

const ViewSkeletons = {
    dashboard: `
        <div style="padding: 20px;">
            <div style="height: 300px; display: flex; justify-content: space-between; align-items: center;">
                <div class="skeleton-box" style="height: 40px; width: 30%;"></div>
                <div class="skeleton-box" style="width: 400px; height: 160px; border-radius: 16px;"></div>
            </div>
            <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px;">
                <div class="skeleton-box" style="height: 380px; border-radius: 12px;"></div>
                <div class="skeleton-box" style="height: 380px; border-radius: 12px;"></div>
                <div class="skeleton-box" style="height: 380px; border-radius: 12px;"></div>
            </div>
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
    // Fallback - uniwersalny szablon dla stron, które nie mają swojego specyficznego
    default: `
        <div style="padding: 40px; max-width: 800px; margin: 0 auto;">
            <div class="skeleton-box" style="height: 40px; width: 40%; margin-bottom: 30px;"></div>
            <div class="skeleton-box" style="height: 200px; border-radius: 12px; margin-bottom: 20px;"></div>
            <div class="skeleton-box" style="height: 200px; border-radius: 12px;"></div>
        </div>
    `
};

const router = {
    navigate: async (viewName, updateUrl = true) => {
        if ((viewName !== 'login' && viewName !== 'register') && !AppState.isLoggedIn()) {
            viewName = 'login';
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
            //await new Promise(resolve => setTimeout(resolve, 150));
            if (!res.ok) {
                throw new Error('Nie znaleziono pliku widoku');
            }

            const htmlContent = await res.text();
            appContainer.innerHTML = htmlContent;

            if (updateUrl) {
                history.pushState({view: viewName}, '', `/${viewName}`);
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

        const activeBtn = document.querySelector(`button[onclick="router.navigate('${viewName}')"]`);
        if (activeBtn) activeBtn.classList.add('active');
    },
    initView: (viewName) => {
        if (viewName === 'teams') {
            teamController.init();
        } else if (viewName === 'setup') {
            setupController.init();
        } else if (viewName === 'dashboard') {
            dashboardController.init();
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
        }, {once: true});
        bg.classList.remove('active');
    }
}

window.Popout = Popout;

window.onpopstate = (event) => {
    if (event.state && event.state.view) {
        router.navigate(event.state.view, false);
    } else {
        router.navigate('dashboard', false);
    }
};
document.addEventListener('DOMContentLoaded', async () => {

    await authController.checkSession();

    if (AppState.isLoggedIn()) {
        notificationController.init();
    }
    
    const currentPath = window.location.pathname.replace('/', ''); 
    const defaultView = currentPath ? currentPath : 'dashboard';
    
    router.navigate(defaultView);

    const menuEl = document.getElementById('pop-menu');
    const menuBtn = document.getElementById('nav-profile');
    menuBtn.addEventListener('click', () => {
        if (!menuEl) return;

        const isHidden = menuEl.classList.contains('hidden');

        if (isHidden) {
            menuEl.style.display = 'block';

            requestAnimationFrame(() => {
                menuEl.classList.remove('hidden');
            });
        } else {
            menuEl.classList.add('hidden');

            menuEl.addEventListener('transitionend', () => {
                menuEl.style.display = 'none';
            }, { once: true });
        }
    });
    menuEl.addEventListener('click', (e) => {
        if (e.target.closest('button, a')) {
            menuEl.classList.add('hidden');
        }
    });
    document.addEventListener('click', (e) => {
        if (menuEl.classList.contains('hidden')) return;
        if (menuBtn.contains(e.target)) return;
        if (menuEl.contains(e.target)) return;

        menuEl.classList.add('hidden');
    });

    const notifBtn = document.getElementById('nav-notifications');
    const drawer = document.getElementById('notification-drawer');
    const drawerOverlay = document.getElementById('drawer-overlay');
    const closeDrawerBtn = document.getElementById('btn-close-drawer');

    const toggleDrawer = (forceState) => {
        const isOpen = drawer.classList.contains('open');
        const newState = forceState !== undefined ? forceState : !isOpen;

        if (newState) {
            drawer.classList.add('open');
            drawerOverlay.classList.add('active');
        } else {
            drawer.classList.remove('open');
            drawerOverlay.classList.remove('active');
        }
    }

    if (notifBtn) notifBtn.addEventListener('click', () => toggleDrawer(true));
    if (closeDrawerBtn) closeDrawerBtn.addEventListener('click', () => toggleDrawer(false));
    if (drawerOverlay) drawerOverlay.addEventListener('click', () => toggleDrawer(false));
});