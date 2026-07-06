import { AppState } from '../state.js';

export const authController = {
    checkSession: async () => {
        try {
            const res = await fetch(`api.php?action=get_current_user`);
            const data = await res.json();

            if (data.logged_in) {
                AppState.setUser(data.user);
                AppState.setCsrfToken(data.csrf_token);
                AppState.setWsToken(data.ws_token);
                AppState.setWsPort(data.ws_port);
            } else {
                AppState.clear ? AppState.clear() : AppState.setUser(null);
            }
            authController.updateUI();
        } catch (err) {
            console.error('AuthController Error: ', err);
        }
    },
    updateUI: () => {
        const guestLinks = document.querySelectorAll('.guest-link');
        const protectedLinks = document.querySelectorAll('.protected-link');
        const navProfile = document.getElementById('nav-profile');
        const adminLinks = document.querySelectorAll('.admin-only');

        if (AppState.isLoggedIn()) {
            guestLinks.forEach(el => el.style.display = 'none');
            protectedLinks.forEach(el => el.style.display = 'block');
            const isAdmin = !!AppState.getUser()?.player?.isAdmin;
            adminLinks.forEach(el => el.style.display = isAdmin ? 'block' : 'none');
            
            if (navProfile) {
                const avatarUrl = AppState.getUser().player.avatar ? AppState.getUser().player.avatar : `https://ui-avatars.com/api/?name=${AppState.getUser().username}&background=121212&color=ff002b`
                navProfile.innerHTML = `
                <img src="${avatarUrl}" alt="Avatar" class="player-avatar-nav">
                <span>${AppState.getUser().username}</span>`;
                navProfile.style.display = 'flex';
            }
        } else {
            guestLinks.forEach(el => el.style.display = 'block');
            protectedLinks.forEach(el => el.style.display = 'none');
            adminLinks.forEach(el => el.style.display = 'none');

            if (navProfile) navProfile.style.display = 'none';
        }
    },
    handleRegister: async (e) => {
        e.preventDefault();

        const username = document.getElementById('reg-username').value;
        const email = document.getElementById('reg-email').value;
        const password = document.getElementById('reg-password').value;

        try {
            const response = await fetch('api.php?action=register', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ username, email, password })
            });
            const data = await response.json();

            if (data.success) {
                window.Toast.show(data.message, 'success');
                window.router.navigate('login');
            } else {
                window.Toast.show(data.message, 'error');
            }
        } catch (err) {
            window.Toast.show('Wystąpił nieznany błąd. Spróbuj ponownie później.', 'error');
        }
    },

    handleLogin: async (e) => {
        e.preventDefault();

        const email = document.getElementById('log-email').value;
        const password = document.getElementById('log-password').value;

        try {
            const response = await fetch('api.php?action=login', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ email, password })
            });
            const data = await response.json();

            if (data.success) {
                await authController.checkSession();
                await window.bootstrapLoggedInServices?.();

                const res = await fetch('api.php?action=check-for-configuration');
                const confData = await res.json();

                if (confData.success && confData.required) {
                    window.router.navigate('setup');
                } else {
                    window.router.navigate('dashboard');
                }
            } else {
                window.Toast.show(data.message, 'error');
            }
        } catch (err) {
            window.Toast.show('Wystąpił nieznany błąd. Spróbuj ponownie później.', 'error');
        }
    },

    handleLogout: async () => {
        await window.apiFetch('api.php?action=logout', {
            method: 'POST'
        });

        if (window.wsClient) {
            window.wsClient.close();
            window.wsClient = null;
        }

        window.onlineUsers?.clear();
        window.notificationController?.reset?.();

        if (AppState.clear) {
            AppState.clear();
        } else {
            AppState.setUser(null);
        }

        window.Toast.show('Wylogowano pomyślnie.');
        authController.updateUI();
        window.router.navigate('dashboard');
    }
}