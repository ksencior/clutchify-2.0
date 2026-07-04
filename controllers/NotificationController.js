import { AppState } from "../state.js";

export const notificationController = {
    pollingInterval: null,

    init: () => {
        window.notificationController = notificationController;

        notificationController.load();

        if (notificationController.pollingInterval) {
            clearInterval(notificationController.pollingInterval);
        }

        notificationController.pollingInterval = setInterval(notificationController.load, 30000)
    },

    load: async () => {
        if (!AppState.isLoggedIn()) return;

        try {
            const response = await fetch('api.php?action=get_notifications');
            const data = await response.json();

            if (data.success) {
                notificationController.render(data.notifications);
            }
        } catch (err) {
            console.error('Błąd ładowania powiadomień:', err);
        }
    },

    render: (notifications) => {
        const badge = document.getElementById('notif-badge');
        const drawerCount = document.getElementById('drawer-count');
        const drawerContent = document.getElementById('drawer-content');
        if (!badge || !drawerCount || !drawerContent) return;

        const count = notifications.length;
        drawerCount.innerHTML = count;
        if (count > 0) {
            badge.style.display = 'block';
            badge.innerText = count > 9? '9+' : count;
        } else {
            badge.style.display = 'none';
        }

        if (count === 0) {
            drawerContent.innerHTML = '<div style="text-align: center; color: var(--text-gray); margin-top: 40px; font-size: 14px;">Brak nowych powiadomień.</div>';
            return;
        }

        drawerContent.innerHTML = notifications.map(notif => `
            <div class="notification-card">
                <p>${notif.message}</p>
                <div class="notification-actions">
                    <button class="nav-btn active" style="flex: 1; padding: 8px; font-size: 12px; background-color: #00E676; color: #080808;" onclick="notificationController.respond(${notif.id}, 'accept')">Akceptuj</button>
                    <button class="btn-cancel" style="flex: 1; padding: 8px; font-size: 12px; margin: 0;" onclick="notificationController.respond(${notif.id}, 'reject')">Odrzuć</button>
                </div>
            </div>
        `).join('');
    },

    respond: async (notifId, action) => {
        try {
            const response = await fetch('api.php?action=respond_notification', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ notification_id: notifId, action: action })
            });
            const data = await response.json();

            if (data.success) {
                window.Toast.show(data.message, action === 'accept' ? 'success' : 'info');
                await notificationController.load();

                if (action === 'accept' && window.location.pathname.includes('teams')) {
                    window.teamController.loadCurrentTeamState();
                }
            } else {
                window.Toast.show(data.message, 'error');
            }
        } catch (err) {
            window.Toast.show('Błąd komunikacji z serwerem.', 'error');
        }
    }
}