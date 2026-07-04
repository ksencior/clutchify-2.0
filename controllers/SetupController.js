import { AppState } from '../state.js';
import { authController } from './AuthController.js';

export const setupController = {
    currentStep: 1,
    setupData: {
        steamConnected: false,
        discordConnected: false,
        choice: 'Pominięto'
    },
    init: async () => {
        await window.authController.checkSession();
        const res = await fetch('api.php?action=check-for-configuration');
        const confData = await res.json();

        if (!confData.success || !confData.required) {
            window.router.navigate('dashboard');
            return;
        }
        setupController.currentStep = 1;
        setupController.ensureConnection('steam');
        setupController.ensureConnection('discord');
        setupController.updateUI();
    },

    nextStep: () => {
        if (setupController.currentStep < 3) {
            setupController.currentStep++;
            setupController.updateUI();
        }
        
        if (setupController.currentStep === 3) {
            setupController.renderPlayerCard();
        }
    },

    prevStep: () => {
        if (setupController.currentStep > 1) {
            setupController.currentStep--;
            setupController.updateUI();
        }
    },

    updateUI: () => {
        for (let i = 1; i <= 3; i++) {
            const dot = document.getElementById(`prog-${i}`);
            if (!dot) continue;
            
            dot.classList.remove('active', 'completed');
            if (i < setupController.currentStep) dot.classList.add('completed');
            if (i === setupController.currentStep) dot.classList.add('active');
        }

        document.querySelectorAll('.setup-step').forEach(el => el.classList.remove('active'));
        const currentStepEl = document.getElementById(`step-${setupController.currentStep}`);
        if (currentStepEl) currentStepEl.classList.add('active');
    },

    connectAccount: async (provider) => {
        if (provider === 'steam') {
            window.location.href = '/services/connect_steam.php';
        } else if (provider === 'discord') {
            window.location.href = 'https://discord.com/oauth2/authorize?client_id=1522618604934271017&response_type=code&redirect_uri=http%3A%2F%2Fclutchify.test%2Fservices%2Fconnect_discord.php&scope=identify';
        }
    },

    ensureConnection: async (provider) => {
        const btn = document.getElementById(`btn-${provider}`);
        btn.innerText = 'Łączenie...';
        btn.style.opacity = '0.5';

        const response = await fetch('api.php?action=ensure_connection', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ provider: provider })
        });
        const data = await response.json();

        if (data.success) {
            if (data.connected == true) {
                btn.classList.add('btn-connected');
                btn.innerHTML = `Połączono z ${provider.charAt(0).toUpperCase() + provider.slice(1)}`;
                btn.style.opacity = '1';
                btn.disabled = true;
                if (provider === 'steam') setupController.setupData.steamConnected = true;
                if (provider === 'discord') setupController.setupData.discordConnected = true;
            } else {
                btn.innerText = `Połącz z ${provider}`;
                btn.style.opacity = '1';
            }
        } else {
            window.Toast.show(data.message, 'error');
        }
    },

    selectChoice: (type, element) => {
        // Usuwamy podświetlenie ze wszystkich
        document.querySelectorAll('.choice-card').forEach(el => el.classList.remove('selected'));
        // Dodajemy do klikniętego
        element.classList.add('selected');
        
        if (type === 'create') {
            setupController.setupData.choice == 'create';
        } else {
            setupController.setupData.choice == 'lft';
        }
    },

    renderPlayerCard: () => {
        const usernameEl = document.getElementById('preview-username');
        const steamIdEl = document.getElementById('preview-steam-id');
        const avatarEl = document.getElementById('preview-avatar');

        authController.checkSession();

        usernameEl.innerText = AppState.isLoggedIn() ? AppState.getUser().username : 'Nowy_Gracz';
        avatarEl.src = `https://ui-avatars.com/api/?name=${AppState.isLoggedIn() ? AppState.getUser().username : 'P'}&background=121212&color=ff002b`;

        if (AppState.getUser().player.steam_id != null) {
            steamIdEl.innerText = 'Połączono konto steam';
        } else {
            steamIdEl.innerText = 'Nie połączono konta steam!';
        }
    },

    finishSetup: () => {
        window.Toast.show('Profil został pomyślnie skonfigurowany!', 'success');

        if (setupController.setupData.choice === 'create') { 
            window.router.navigate('teams');
        } else {
            window.router.navigate('dashboard');
        }
    }
}