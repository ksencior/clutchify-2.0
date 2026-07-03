let currentUser = null;

import { AppState } from './state.js';

import { authController } from './controllers/AuthController.js';
import { setupController } from './controllers/SetupController.js';

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
            const res = await fetch(`views/${viewName}.html`);
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
            loadTeamsFromApi();
        } else if (viewName === 'setup') {
            setupController.init();
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

async function loadTeamsFromApi() {
    const listContainer = document.getElementById('teams-list');
    if (!listContainer) return;

    try {
        const response = await fetch('api.php?action=get_teams');
        const teams = await response.json();

        if (teams.error) {
            listContainer.innerHTML = `<p style="color: var(--brand-red);">${teams.error}</p>`;
            return;
        }

        if (teams.length === 0) {
            listContainer.innerHTML = '<p style="color: var(--text-gray);">Brak drużyn w bazie. Bądź pierwszy!</p>';
            return;
        }

        listContainer.innerHTML = teams.map(team => `
            <div class="team-card" style="background: var(--bg-card); padding: 15px; border: 1px solid var(--border-color); border-radius: 8px; margin-bottom: 10px; display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <span style="background: rgba(255,0,43,0.1); color: var(--brand-red); padding: 2px 6px; border-radius: 4px; font-size: 11px; font-weight: bold; margin-right: 10px;">${team.tag}</span>
                    <strong style="font-size: 16px;">${team.name}</strong>
                </div>
                <button class="nav-btn" style="background: rgba(255,255,255,0.05); padding: 6px 12px; font-size: 11px;" onclick="alert('Aplikujesz do ${team.name}')">Aplikuj</button>
            </div>
        `).join('');

    } catch (error) {
        console.error('Błąd pobierania danych z API:', error);
        listContainer.innerHTML = '<p style="color: var(--brand-red);">Nie udało się połączyć z API.</p>';
    }
}