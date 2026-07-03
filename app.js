let currentUser = null;

import { AppState } from './state.js';

import { authController } from './controllers/AuthController.js';
import { setupController } from './controllers/SetupController.js';
import { teamController } from './controllers/TeamController.js';
import { dashboardController } from './controllers/DashboardController.js';

window.authController = authController;
window.setupController = setupController;

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
        try {
            const res = await fetch(`views/${viewName}.html?v=${Date.now()}`);
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
    
    const currentPath = window.location.pathname.replace('/', ''); 
    const defaultView = currentPath ? currentPath : 'dashboard';
    
    router.navigate(defaultView);
});