let currentUser = null;

import { AppState } from './state.js';

import { authController } from './controllers/AuthController.js';
import { setupController } from './controllers/SetupController.js';
import { teamController } from './controllers/TeamController.js';

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
            renderPlayerCard();
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

function renderPlayerCard() {
    const usernameEl = document.getElementById('dashboard-username');
    const avatarEl = document.getElementById('dashboard-avatar');
    const teamEl = document.getElementById('dashboard-team');

    usernameEl.innerText = AppState.isLoggedIn() ? AppState.getUser().username : 'Nowy_Gracz';
    teamEl.innerText = AppState.getUser().player.team_id !== null? AppState.getUser().player.team_name : "Brak drużyny";
    avatarEl.src = `https://ui-avatars.com/api/?name=${AppState.isLoggedIn() ? AppState.getUser().username : 'P'}&background=121212&color=ff002b`;
}