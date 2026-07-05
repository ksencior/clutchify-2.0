import { AppState } from "../state.js";

const esc = (value = '') => window.escapeHTML ? window.escapeHTML(value) : String(value);

export const friendsController = {
    friends: [],
    requests: [],
    activeFriendId: null,
    activeFriendName: null,

    init: async () => {
        window.friendsController = friendsController;

        document.querySelectorAll('[data-sound-state]').forEach(el => {
            el.textContent = window.Sound?.enabled ? 'Dźwięk: ON' : 'Dźwięk: OFF';
        });

        friendsController.bindEvents();

        await Promise.all([
            friendsController.loadFriends(),
            friendsController.loadRequests()
        ]);

        const friendIdFromState = window.history.state?.friendId;

        if (friendIdFromState) {
            const friend = friendsController.friends.find(item => Number(item.id) === Number(friendIdFromState));

            if (friend) {
                await friendsController.openChat(friend.id, friend.username);
            }
        }
    },

    bindEvents: () => {
        const searchInput = document.getElementById('friend-search-input');
        const form = document.getElementById('chat-form');

        if (searchInput) {
            searchInput.addEventListener('input', friendsController.debounce(async (event) => {
                const query = event.target.value.trim();
                await friendsController.searchUsers(query);
            }, 250));
        }

        if (form) {
            form.addEventListener('submit', friendsController.sendMessage);
        }
    },

    debounce: (fn, delay = 250) => {
        let timer;

        return (...args) => {
            clearTimeout(timer);
            timer = setTimeout(() => fn(...args), delay);
        };
    },

    loadFriends: async () => {
        const response = await fetch('api.php?action=get_friends');
        const data = await response.json();

        if (!data.success) {
            window.Toast.show(data.message || 'Nie udało się pobrać znajomych.', 'error');
            return;
        }

        friendsController.friends = data.friends || [];
        friendsController.renderFriends();
    },

    loadRequests: async () => {
        const response = await fetch('api.php?action=get_friend_requests');
        const data = await response.json();

        if (!data.success) return;

        friendsController.requests = data.requests || [];
        friendsController.renderRequests();
    },

    searchUsers: async (query) => {
        const resultsEl = document.getElementById('friend-search-results');
        if (!resultsEl) return;

        if (query.length < 2) {
            resultsEl.innerHTML = '<div class="empty-state small">Wpisz minimum 2 znaki.</div>';
            return;
        }

        const response = await fetch(`api.php?action=search_users&q=${encodeURIComponent(query)}`);
        const data = await response.json();

        if (!data.success) {
            resultsEl.innerHTML = `<div class="empty-state small">${esc(data.message || 'Błąd wyszukiwania.')}</div>`;
            return;
        }

        if (!data.users.length) {
            resultsEl.innerHTML = '<div class="empty-state small">Brak pasujących graczy.</div>';
            return;
        }

        resultsEl.innerHTML = data.users.map(user => {
            const avatar = user.avatar || `https://ui-avatars.com/api/?name=${encodeURIComponent(user.username)}&background=121212&color=ff002b`;
            const statusText = friendsController.statusLabel(user.friend_status);
            const action = friendsController.renderSearchAction(user);

            return `
                <div class="friend-search-result">
                    <img src="${esc(avatar)}" alt="Avatar">

                    <div>
                        <strong>${esc(user.username)}</strong>
                        <span>${statusText}</span>
                    </div>

                    ${action}
                </div>
            `;
        }).join('');
    },

    statusLabel: (status) => {
        const labels = {
            none: 'Możesz zaprosić',
            pending_sent: 'Zaproszenie wysłane',
            pending_received: 'Odpowiedz na zaproszenie',
            accepted: 'Znajomy'
        };

        return labels[status] || 'Gracz';
    },

    renderSearchAction: (user) => {
        if (user.friend_status === 'accepted') {
            return `
                <button
                    class="btn-confirm compact"
                    onclick="friendsController.openChat(${Number(user.id)}, '${esc(user.username).replace(/'/g, '&#039;')}')"
                >
                    Czat
                </button>
            `;
        }

        if (user.friend_status === 'pending_sent') {
            return '<button class="btn-ok compact" disabled>Wysłano</button>';
        }

        if (user.friend_status === 'pending_received') {
            return `
                <button
                    class="btn-confirm compact"
                    onclick="friendsController.respondRequest(${Number(user.friendship_id)}, 'accept')"
                >
                    Akceptuj
                </button>
            `;
        }

        return `
            <button
                class="btn-confirm compact"
                onclick="friendsController.sendFriendRequest(${Number(user.id)})"
            >
                Dodaj
            </button>
        `;
    },

    renderFriends: () => {
        const list = document.getElementById('friends-list');
        if (!list) return;

        if (!friendsController.friends.length) {
            list.innerHTML = '<div class="empty-state">Nie masz jeszcze znajomych. Wyszukaj gracza powyżej.</div>';
            return;
        }

        list.innerHTML = friendsController.friends.map(friend => {
            const avatar = friend.avatar || `https://ui-avatars.com/api/?name=${encodeURIComponent(friend.username)}&background=121212&color=ff002b`;
            const online = window.onlineUsers?.has(String(friend.id));
            const active = Number(friend.id) === Number(friendsController.activeFriendId) ? 'active' : '';

            return `
                <button
                    class="friend-row ${active}"
                    onclick="friendsController.openChat(${Number(friend.id)}, '${esc(friend.username).replace(/'/g, '&#039;')}')"
                >
                    <span class="avatar-wrap">
                        <img src="${esc(avatar)}" alt="Avatar">
                        <span class="status-dot ${online ? 'online' : ''}"></span>
                    </span>

                    <span class="friend-row-main">
                        <strong>${esc(friend.username)}</strong>
                        <small>${friend.last_message ? esc(friend.last_message) : 'Brak wiadomości'}</small>
                    </span>
                </button>
            `;
        }).join('');
    },

    renderRequests: () => {
        const box = document.getElementById('friend-requests');
        const badge = document.getElementById('friend-requests-badge');
        if (!box) return;

        if (badge) badge.textContent = friendsController.requests.length;

        if (!friendsController.requests.length) {
            box.innerHTML = '<div class="empty-state small">Brak oczekujących zaproszeń.</div>';
            return;
        }

        box.innerHTML = friendsController.requests.map(req => {
            const avatar = req.avatar || `https://ui-avatars.com/api/?name=${encodeURIComponent(req.username)}&background=121212&color=ff002b`;

            return `
                <div class="friend-request-card">
                    <img src="${esc(avatar)}" alt="Avatar">

                    <div>
                        <strong>${esc(req.username)}</strong>
                        <span>Chce dodać Cię do znajomych.</span>
                    </div>

                    <div class="friend-request-actions">
                        <button
                            class="btn-confirm compact"
                            onclick="friendsController.respondRequest(${Number(req.friendship_id)}, 'accept')"
                        >
                            ✓
                        </button>

                        <button
                            class="btn-cancel compact"
                            onclick="friendsController.respondRequest(${Number(req.friendship_id)}, 'reject')"
                        >
                            ×
                        </button>
                    </div>
                </div>
            `;
        }).join('');
    },

    sendFriendRequest: async (targetId) => {
        const response = await window.apiFetch('api.php?action=send_friend_request', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ target_id: targetId })
        });

        const data = await response.json();

        if (data.success) {
            window.Toast.show(data.message, 'success');

            friendsController.searchUsers(
                document.getElementById('friend-search-input')?.value.trim() || ''
            );

            if (window.wsClient?.readyState === WebSocket.OPEN) {
                window.wsClient.send(JSON.stringify({
                    type: 'notify',
                    targetId: data.targetId
                }));
            }
        } else {
            window.Toast.show(data.message, 'error');
        }
    },

    respondRequest: async (friendshipId, action) => {
        const response = await window.apiFetch('api.php?action=respond_friend_request', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ friendship_id: friendshipId, action })
        });

        const data = await response.json();

        if (data.success) {
            window.Toast.show(data.message, action === 'accept' ? 'success' : 'info');

            await Promise.all([
                friendsController.loadFriends(),
                friendsController.loadRequests()
            ]);

            friendsController.searchUsers(
                document.getElementById('friend-search-input')?.value.trim() || ''
            );
        } else {
            window.Toast.show(data.message, 'error');
        }
    },

    openChat: async (friendId, username) => {
        friendsController.activeFriendId = Number(friendId);
        friendsController.activeFriendName = username;

        const title = document.getElementById('chat-title');
        const form = document.getElementById('chat-form');
        const hint = document.getElementById('chat-empty-hint');

        if (title) title.textContent = username;
        if (form) form.classList.remove('hidden');
        if (hint) hint.classList.add('hidden');

        friendsController.renderFriends();

        await friendsController.loadChat();
    },

    loadChat: async () => {
        const messagesEl = document.getElementById('chat-messages');

        if (!messagesEl || !friendsController.activeFriendId) return;

        const response = await fetch(`api.php?action=get_chat&friend_id=${friendsController.activeFriendId}`);
        const data = await response.json();

        if (!data.success) {
            messagesEl.innerHTML = `<div class="empty-state">${esc(data.message || 'Nie udało się pobrać rozmowy.')}</div>`;
            return;
        }

        if (!data.messages.length) {
            messagesEl.innerHTML = '<div class="empty-state">Napisz pierwszą wiadomość.</div>';
            return;
        }

        messagesEl.innerHTML = data.messages.map(msg => {
            const mine = Number(msg.sender_id) === Number(AppState.getUser().id);

            return `
                <div class="chat-message ${mine ? 'mine' : 'theirs'}">
                    <div class="chat-bubble">
                        <p>${esc(msg.body)}</p>
                        <span>${esc(msg.created_at)}</span>
                    </div>
                </div>
            `;
        }).join('');

        messagesEl.scrollTop = messagesEl.scrollHeight;

        await friendsController.loadFriends();

        if (window.notificationController) {
            await window.notificationController.load();
        }
    },

    sendMessage: async (event) => {
        event.preventDefault();

        const input = document.getElementById('chat-message-input');
        const body = input?.value.trim();

        if (!body || !friendsController.activeFriendId) return;

        input.value = '';

        const response = await window.apiFetch('api.php?action=send_message', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                receiver_id: friendsController.activeFriendId,
                body
            })
        });

        const data = await response.json();

        if (!data.success) {
            window.Toast.show(data.message || 'Nie udało się wysłać wiadomości.', 'error');
            input.value = body;
            return;
        }

        await friendsController.loadChat();

        if (window.wsClient?.readyState === WebSocket.OPEN) {
            window.wsClient.send(JSON.stringify({
                type: 'chat_message',
                targetId: friendsController.activeFriendId
            }));
        }
    },

    onChatNotification: async (data) => {
        const fromId = Number(data.fromId);
        const fromUsername = data.fromUsername || 'Znajomy';

        await friendsController.loadFriends();

        if (
            Number(friendsController.activeFriendId) === fromId &&
            window.location.pathname.includes('friends')
        ) {
            await friendsController.loadChat();
            return;
        }

        window.Toast.show(`Nowa wiadomość od ${esc(fromUsername)}`, 'info');
    }
};