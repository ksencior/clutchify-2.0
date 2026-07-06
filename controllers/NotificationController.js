import { AppState } from "../state.js";

const esc = (value = '') => window.escapeHTML ? window.escapeHTML(value) : String(value);

export const notificationController = {
    notifications: [],
    unreadNotificationsCount: 0,
    messageThreads: [],
    unreadMessagesTotal: 0,
    activeTab: 'notifications',

    init: () => {
        notificationController.load();
    },

    load: async () => {
        if (!AppState.isLoggedIn()) return;

        try {
            const [notificationsResponse, messagesResponse] = await Promise.all([
                fetch('api.php?action=get_notifications'),
                fetch('api.php?action=get_unread_message_threads')
            ]);

            const notificationsData = await notificationsResponse.json();
            const messagesData = await messagesResponse.json();

            if (notificationsData.success) {
                notificationController.notifications = notificationsData.notifications || [];
                notificationController.unreadNotificationsCount = Number(notificationsData.unread_count || 0);
            }

            if (messagesData.success) {
                notificationController.messageThreads = messagesData.threads || [];
                notificationController.unreadMessagesTotal = Number(messagesData.unread_total || 0);
            }

            notificationController.render();
        } catch (err) {
            console.error('Błąd ładowania centrum aktywności:', err);
        }
    },

    render: () => {
        notificationController.renderBadges();
        notificationController.renderTabs();
        notificationController.renderContent();
    },

    renderBadges: () => {
        const badge = document.getElementById('notif-badge');
        const drawerCount = document.getElementById('drawer-count');

        const notificationsCount = notificationController.unreadNotificationsCount;
        const messagesCount = notificationController.unreadMessagesTotal;
        const totalCount = notificationsCount + messagesCount;

        if (drawerCount) {
            if (totalCount > 0) {
                drawerCount.style.display = 'inline-flex';
                drawerCount.textContent = totalCount > 9 ? '9+' : totalCount;
            } else {
                drawerCount.style.display = 'none';
                drawerCount.textContent = '0';
            }
        }

        if (!badge) return;

        if (totalCount > 0) {
            badge.style.display = 'grid';
            badge.textContent = totalCount > 9 ? '9+' : totalCount;
        } else {
            badge.style.display = 'none';
        }
    },

    renderTabs: () => {
        const notificationsTab = document.getElementById('activity-tab-notifications');
        const messagesTab = document.getElementById('activity-tab-messages');

        const notificationsCount = document.getElementById('activity-notifications-count');
        const messagesCount = document.getElementById('activity-messages-count');

        if (notificationsTab) {
            notificationsTab.classList.toggle(
                'active',
                notificationController.activeTab === 'notifications'
            );
        }

        if (messagesTab) {
            messagesTab.classList.toggle(
                'active',
                notificationController.activeTab === 'messages'
            );
        }

        if (notificationsCount) {
            notificationsCount.textContent = notificationController.unreadNotificationsCount;
            notificationsCount.style.display = notificationController.unreadNotificationsCount > 0 ? 'grid' : 'none';
        }

        if (messagesCount) {
            messagesCount.textContent = notificationController.unreadMessagesTotal;
            messagesCount.style.display = notificationController.unreadMessagesTotal > 0 ? 'grid' : 'none';
        }
    },

    renderContent: () => {
        const drawerContent = document.getElementById('drawer-content');
        if (!drawerContent) return;

        if (notificationController.activeTab === 'messages') {
            notificationController.renderMessages(drawerContent);
            return;
        }

        notificationController.renderNotifications(drawerContent);
    },

    switchTab: async (tab) => {
        notificationController.activeTab = tab;
        notificationController.render();

        if (tab === 'notifications') {
            await notificationController.markSystemNotificationsSeen();
        }
    },

    markSystemNotificationsSeen: async () => {
        const hasPendingSystemNotifications = notificationController.notifications.some(
            notif => notif.type === 'system'
        );

        if (!hasPendingSystemNotifications) return;

        try {
            const response = await window.apiFetch('api.php?action=mark_system_notifications_seen', {
                method: 'POST'
            });

            const data = await response.json();

            if (data.success && Number(data.marked || 0) > 0) {
                await notificationController.load();
            }
        } catch (error) {
            console.error('Nie udało się oznaczyć systemowych powiadomień jako przeczytane:', error);
        }
    },

    renderNotifications: (drawerContent) => {
        const notifications = notificationController.notifications;

        if (notifications.length === 0) {
            drawerContent.innerHTML = `
                <div class="empty-state">
                    Brak nowych powiadomień.
                </div>
            `;
            return;
        }

        drawerContent.innerHTML = notifications
            .map(notif => notificationController.renderNotificationCard(notif))
            .join('');
    },

    renderNotificationCard: (notif) => {
        let actions = '';

        if (notif.type === 'team_invite' || notif.type === 'friend_request') {
            actions = `
                <button
                    class="btn-confirm compact"
                    onclick="notificationController.respond(${Number(notif.id)}, 'accept')"
                >
                    Akceptuj
                </button>

                <button
                    class="btn-cancel compact"
                    onclick="notificationController.respond(${Number(notif.id)}, 'reject')"
                >
                    Odrzuć
                </button>
            `;
        }
        const readClass = notif.status === 'seen' ? 'is-read' : 'is-unread';
        return `
            <div class="notification-card notification-card-${esc(notif.type)} ${readClass}">
                <div class="notification-card-top">
                    <span class="notification-dot"></span>
                    <strong>${notificationController.typeLabel(notif.type)}</strong>
                    <small>${esc(notif.created_at || '')}</small>
                </div>

                <p>${notif.message}</p>

                ${actions ? `<div class="notification-actions">${actions}</div>` : ''}
            </div>
        `;
    },

    renderMessages: (drawerContent) => {
        const threads = notificationController.messageThreads;

        if (threads.length === 0) {
            drawerContent.innerHTML = `
                <div class="empty-state">
                    Brak nieprzeczytanych wiadomości.
                </div>
            `;
            return;
        }

        drawerContent.innerHTML = threads.map(thread => {
            const avatar = thread.avatar || `https://ui-avatars.com/api/?name=${encodeURIComponent(thread.username)}&background=121212&color=ff002b`;
            const unreadCount = Number(thread.unread_count || 0);

            return `
                <button
                    class="message-thread-card"
                    onclick="notificationController.openMessageThread(${Number(thread.id)}, '${esc(thread.username).replace(/'/g, '&#039;')}')"
                    type="button"
                >
                    <span class="message-thread-avatar">
                        <img src="${esc(avatar)}" alt="Avatar">
                        <span>${unreadCount > 9 ? '9+' : unreadCount}</span>
                    </span>

                    <span class="message-thread-main">
                        <strong>${esc(thread.username)}</strong>
                        <small>${esc(thread.last_message || 'Nowa wiadomość')}</small>
                    </span>

                    <span class="message-thread-time">
                        ${esc(thread.last_message_at || '')}
                    </span>
                </button>
            `;
        }).join('');
    },

    openMessageThread: (friendId, username) => {
        window.history.pushState(
            {
                view: 'friends',
                friendId: Number(friendId),
                friendName: username
            },
            '',
            '/friends'
        );

        window.router.navigate('friends', false);

        notificationController.closeDrawerIfPossible();
    },

    closeDrawerIfPossible: () => {
        const drawer = document.getElementById('notification-drawer');
        const overlay = document.getElementById('drawer-overlay');

        if (drawer) {
            drawer.classList.remove('open', 'active', 'show');
        }
        if (overlay) {
            overlay.classList.remove('active');
        }

        document.body.classList.remove('drawer-open');
    },

    typeLabel: (type) => {
        const labels = {
            team_invite: 'Zaproszenie do drużyny',
            friend_request: 'Zaproszenie do znajomych',
            system: 'System'
        };

        return labels[type] || 'Powiadomienie';
    },

    respond: async (notifId, action) => {
        try {
            const response = await window.apiFetch('api.php?action=respond_notification', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    notification_id: notifId,
                    action
                })
            });

            const data = await response.json();

            if (data.success) {
                window.Toast.show(data.message, action === 'accept' ? 'success' : 'info');

                await notificationController.load();

                if (action === 'accept' && window.location.pathname.includes('teams')) {
                    window.teamController?.loadCurrentTeamState();
                }

                if (window.friendsController && window.location.pathname.includes('friends')) {
                    await Promise.all([
                        window.friendsController.loadFriends(),
                        window.friendsController.loadRequests()
                    ]);
                }
            } else {
                window.Toast.show(data.message, 'error');
            }
        } catch (err) {
            window.Toast.show('Błąd komunikacji z serwerem.', 'error');
        }
    },
    reset: () => {
        notificationController.notifications = [];
        notificationController.unreadNotificationsCount = 0;
        notificationController.messageThreads = [];
        notificationController.unreadMessagesTotal = 0;
        notificationController.activeTab = 'notifications';

        notificationController.render();
    }
};