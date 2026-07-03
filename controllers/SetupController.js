import { AppState } from '../state.js';

export const setupController = {
    currentStep: 1,
    mockData: {
        steamConnected: false,
        discordConnected: false,
        choice: 'Pominięto'
    },
    init: () => {
        setupController.currentStep = 1;
        setupController.mockData = { steamConnected: false, discordConnected: false, choice: 'Pominięto' };
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

    simulateConnect: (provider) => {
        const btn = document.getElementById(`btn-${provider}`);
        btn.innerText = 'Łączenie...';
        btn.style.opacity = '0.5';

        setTimeout(() => {
            btn.classList.add('btn-connected');
            btn.innerHTML = `Połączono z ${provider.charAt(0).toUpperCase() + provider.slice(1)} &#10003;`;
            btn.style.opacity = '1';
            
            if (provider === 'steam') setupController.mockData.steamConnected = true;
            if (provider === 'discord') setupController.mockData.discordConnected = true;
            
            window.Toast.show(`Konto ${provider} zostało połączone!`, 'success');
        }, 1000);
    },

    selectChoice: (type, element) => {
        // Usuwamy podświetlenie ze wszystkich
        document.querySelectorAll('.choice-card').forEach(el => el.classList.remove('selected'));
        // Dodajemy do klikniętego
        element.classList.add('selected');
        
        setupController.mockData.choice = type === 'create' ? 'Chce założyć drużynę' : 'Szuka drużyny (LFT)';
    },

    renderPlayerCard: () => {
        const usernameEl = document.getElementById('preview-username');
        const steamIdEl = document.getElementById('preview-steam-id');
        const avatarEl = document.getElementById('preview-avatar');
        const choiceEl = document.getElementById('preview-choice');

        usernameEl.innerText = AppState.isLoggedIn() ? AppState.getUser().username : 'Nowy_Gracz';
        choiceEl.innerText = `Status: ${setupController.mockData.choice}`;

        if (setupController.mockData.steamConnected) {
            steamIdEl.innerText = 'Steam ID: STEAM_0:1:12345678';
            avatarEl.src = 'https://api.dicebear.com/7.x/avataaars/svg?seed=Clutchify&backgroundColor=121212'; 
        } else {
            steamIdEl.innerText = 'Steam ID: Brak połączenia';
            avatarEl.src = `https://ui-avatars.com/api/?name=${AppState.isLoggedIn() ? AppState.getUser().username : 'P'}&background=121212&color=ff002b`;
        }
    },

    finishSetup: () => {
        window.Toast.show('Profil został pomyślnie skonfigurowany!', 'success');
        window.router.navigate('dashboard');
    }
}