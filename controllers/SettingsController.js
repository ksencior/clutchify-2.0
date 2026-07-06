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
        const passwordForm = document.getElementById('settings-password-form');
        const inputs = document.querySelectorAll('[data-settings-preview]');

        if (form) {
            form.addEventListener('submit', settingsController.save);
        }

        if (passwordForm) {
            passwordForm.addEventListener('submit', settingsController.changePassword);
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
        settingsController.renderCompleteness(settings.profile_completeness);
        settingsController.renderBadges(settings.badges || []);
        settingsController.renderPublicProfileLink(settings);

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

        const usernameRegex = /^[A-Za-z0-9_.-]{3,24}$/;

        if (!usernameRegex.test(payload.username)) {
            window.Toast.show('Nick może mieć 3-24 znaki i zawierać tylko litery, cyfry, _, . oraz -. Bez spacji.', 'error');
            return;
        }

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

    changePassword: async (event) => {
        event.preventDefault();

        const currentPasswordEl = document.getElementById('settings-current-password');
        const newPasswordEl = document.getElementById('settings-new-password');
        const newPasswordConfirmEl = document.getElementById('settings-new-password-confirm');

        const payload = {
            current_password: currentPasswordEl?.value || '',
            new_password: newPasswordEl?.value || '',
            new_password_confirm: newPasswordConfirmEl?.value || ''
        };

        if (!payload.current_password || !payload.new_password || !payload.new_password_confirm) {
            window.Toast.show('Uzupełnij wszystkie pola hasła.', 'error');
            return;
        }

        if (payload.new_password.length < 8) {
            window.Toast.show('Nowe hasło musi mieć minimum 8 znaków.', 'error');
            return;
        }

        if (payload.new_password !== payload.new_password_confirm) {
            window.Toast.show('Nowe hasła nie są takie same.', 'error');
            return;
        }

        const submitBtn = event.target.querySelector('button[type="submit"]');
        const originalText = submitBtn?.textContent;

        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.textContent = 'Zapisywanie...';
        }

        try {
            const response = await window.apiFetch('api.php?action=change_password', {
                method: 'POST',
                body: JSON.stringify(payload)
            });

            const data = await response.json();

            if (!data.success) {
                window.Toast.show(data.message || 'Nie udało się zmienić hasła.', 'error');
                return;
            }

            window.Toast.show(data.message || 'Hasło zostało zmienione.', 'success');

            if (currentPasswordEl) currentPasswordEl.value = '';
            if (newPasswordEl) newPasswordEl.value = '';
            if (newPasswordConfirmEl) newPasswordConfirmEl.value = '';
        } catch (error) {
            console.error(error);
            window.Toast.show('Wystąpił błąd podczas zmiany hasła.', 'error');
        } finally {
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.textContent = originalText || 'Zmień hasło';
            }
        }
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
    },
    renderCompleteness: (completeness) => {
        const bar = document.getElementById('settings-completeness-bar');
        const text = document.getElementById('settings-completeness-text');
        const list = document.getElementById('settings-completeness-missing');

        if (!bar || !text || !list || !completeness) return;

        const percent = Number(completeness.percent || 0);

        bar.style.width = `${percent}%`;
        text.textContent = `${percent}% uzupełnienia profilu`;

        const missing = completeness.missing || [];

        if (!missing.length) {
            list.innerHTML = '<li>Profil jest kompletny.</li>';
            return;
        }

        list.innerHTML = missing
            .slice(0, 5)
            .map(item => `<li>${window.escapeHTML(item.label)}</li>`)
            .join('');
    },

    renderBadges: (badges = []) => {
        const container = document.getElementById('settings-badges');
        if (!container) return;

        if (!badges.length) {
            container.innerHTML = '<span class="profile-badge is-empty">Brak odznak</span>';
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
    renderPublicProfileLink: (settings) => {
        const input = document.getElementById('settings-public-profile-link');
        if (!input || !settings?.username) return;

        input.value = `${window.location.origin}/u/${encodeURIComponent(settings.username)}`;
    },

    copyPublicProfileLink: async () => {
        const input = document.getElementById('settings-public-profile-link');
        if (!input?.value) return;

        try {
            await navigator.clipboard.writeText(input.value);
            window.Toast.show('Skopiowano link profilu.', 'success');
        } catch (_) {
            input.select();
            document.execCommand('copy');
            window.Toast.show('Skopiowano link profilu.', 'success');
        }
    },
};