import { AppState } from "../state.js";

const esc = (value = '') => window.escapeHTML ? window.escapeHTML(value) : String(value);

export const settingsController = {
    settings: null,

    init: async () => {
        window.settingsController = settingsController;
        await settingsController.load();
        settingsController.bindEvents();
    },

    load: async () => {
        const response = await fetch('api.php?action=get_profile_settings');
        const data = await response.json();

        if (!data.success) {
            window.Toast.show(data.message || 'Nie udało się pobrać ustawień.', 'error');
            window.router.navigate('profile');
            return;
        }

        settingsController.settings = data.settings;
        settingsController.fillForm(data.settings);
        settingsController.renderPreview(data.settings);
    },

    bindEvents: () => {
        const form = document.getElementById('settings-profile-form');
        const inputs = document.querySelectorAll('[data-settings-preview]');

        if (form) {
            form.addEventListener('submit', settingsController.save);
        }

        inputs.forEach(input => {
            input.addEventListener('input', () => {
                const current = settingsController.readForm();
                settingsController.renderPreview(current);
            });
        });
    },

    fillForm: (settings) => {
        settingsController.setValue('settings-username', settings.username || '');
        settingsController.setValue('settings-avatar', settings.avatar || '');
        settingsController.setValue('settings-bio', settings.bio || '');
        settingsController.setValue('settings-role', settings.preferred_role || 'unknown');
        settingsController.setValue('settings-faceit', settings.faceit_level || '');
        settingsController.setValue('settings-region', settings.region || 'EU');
        settingsController.setValue('settings-school', settings.school || '');
        settingsController.setValue('settings-availability', settings.availability || '');

        const steamEl = document.getElementById('settings-steam');
        const discordEl = document.getElementById('settings-discord');

        if (steamEl) steamEl.textContent = settings.steam_id || 'Nie połączono';
        if (discordEl) discordEl.textContent = settings.discord_id || 'Nie połączono';
    },

    setValue: (id, value) => {
        const el = document.getElementById(id);
        if (el) el.value = value;
    },

    readForm: () => {
        return {
            username: document.getElementById('settings-username')?.value.trim() || '',
            avatar: document.getElementById('settings-avatar')?.value.trim() || '',
            bio: document.getElementById('settings-bio')?.value.trim() || '',
            preferred_role: document.getElementById('settings-role')?.value || 'unknown',
            faceit_level: document.getElementById('settings-faceit')?.value || '',
            region: document.getElementById('settings-region')?.value.trim() || '',
            school: document.getElementById('settings-school')?.value.trim() || '',
            availability: document.getElementById('settings-availability')?.value.trim() || ''
        };
    },

    save: async (event) => {
        event.preventDefault();

        const payload = settingsController.readForm();

        const response = await window.apiFetch('api.php?action=update_profile_settings', {
            method: 'POST',
            body: JSON.stringify(payload)
        });

        const data = await response.json();

        if (!data.success) {
            window.Toast.show(data.message || 'Nie udało się zapisać profilu.', 'error');
            return;
        }

        window.Toast.show(data.message, 'success');

        /**
         * Odświeżamy AppState, żeby avatar i nick w sidebarze zmieniły się od razu.
         */
        await window.authController.checkSession();

        settingsController.renderPreview({
            ...payload,
            username: payload.username
        });
    },

    renderPreview: (settings) => {
        const avatar = settings.avatar || `https://ui-avatars.com/api/?name=${encodeURIComponent(settings.username || 'Gracz')}&background=121212&color=ff002b`;

        const avatarEl = document.getElementById('settings-preview-avatar');
        const usernameEl = document.getElementById('settings-preview-username');
        const roleEl = document.getElementById('settings-preview-role');
        const bioEl = document.getElementById('settings-preview-bio');
        const faceitEl = document.getElementById('settings-preview-faceit');
        const regionEl = document.getElementById('settings-preview-region');
        const schoolEl = document.getElementById('settings-preview-school');
        const availabilityEl = document.getElementById('settings-preview-availability');

        if (avatarEl) avatarEl.src = avatar;
        if (usernameEl) usernameEl.textContent = settings.username || '-';
        if (roleEl) roleEl.textContent = settingsController.roleLabel(settings.preferred_role);
        if (bioEl) bioEl.textContent = settings.bio || 'Brak opisu profilu.';
        if (faceitEl) faceitEl.textContent = settings.faceit_level ? `Faceit ${settings.faceit_level}` : 'Faceit -';
        if (regionEl) regionEl.textContent = settings.region || 'EU';
        if (schoolEl) schoolEl.textContent = settings.school || 'Brak szkoły / organizacji';
        if (availabilityEl) availabilityEl.textContent = settings.availability || 'Brak informacji o dostępności';
    },

    roleLabel: (role) => {
        const labels = {
            unknown: 'Nie ustawiono',
            entry: 'Entry Fragger',
            rifler: 'Rifler',
            awper: 'AWPer',
            igl: 'IGL',
            lurker: 'Lurker',
            support: 'Support'
        };

        return labels[role] || 'Nie ustawiono';
    }
};