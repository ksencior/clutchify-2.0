import { AppState } from '../state.js';

export const teamController = {
    init: async () => {
        teamController.bindEvents();
        window.teamController = teamController;
        await teamController.loadCurrentTeamState();
    },

    bindEvents: () => {
        const btnBrowse = document.getElementById('choice-browse');
        const btnCreate = document.getElementById('choice-create');
        const createForm = document.getElementById('create-team-form');
        const btnSaveLogo = document.getElementById('btn-save-logo');
        const btnInvite = document.getElementById('btn-invite-player');
        const btnLeave = document.getElementById('btn-leave-team');

        if (btnBrowse) btnBrowse.addEventListener('click', () => teamController.switchSubView('browse'));
        if (btnCreate) btnCreate.addEventListener('click', () => teamController.switchSubView('create'));
        if (createForm) createForm.addEventListener('submit', (e) => teamController.handleCreateTeam(e));
        if (btnSaveLogo) btnSaveLogo.addEventListener('click', () => teamController.updateLogo());
        if (btnInvite) btnInvite.addEventListener('click', () => teamController.invitePlayer());
        if (btnLeave) btnLeave.addEventListener('click', () => teamController.leaveTeam());
    },

    switchSubView: (view) => {
        document.querySelectorAll('#no-team-section .setup-step').forEach(el => el.classList.remove('active'));
        document.querySelectorAll('.choice-card').forEach(el => el.classList.remove('selected'));
        
        const subview = document.getElementById(`subview-${view}`);
        const card = document.getElementById(`choice-${view}`);
        
        if (subview) subview.classList.add('active');
        if (card) card.classList.add('selected');

        if (view === 'browse') {
            teamController.loadOpenTeams();
        }
    },

    loadCurrentTeamState: async () => {
        try {
            const res = await fetch('api.php?action=get_my_team');
            const data = await res.json();

            const noTeamSec = document.getElementById('no-team-section');
            const activeTeamSec = document.getElementById('active-team-section');

            if (!noTeamSec || !activeTeamSec) return;

            if (data.has_team) {
                noTeamSec.style.display = 'none';
                activeTeamSec.style.display = 'block';
                teamController.renderActiveTeam(data.team);
            } else {
                noTeamSec.style.display = 'block';
                activeTeamSec.style.display = 'none';
                teamController.switchSubView('browse');
            }
        } catch (err) {
            console.error(err);
            window.Toast.show('Błąd komunikacji z serwerem podczas pobierania stanu składu.', 'error');
        }
    },

    loadOpenTeams: async () => {
        const listContainer = document.getElementById('teams-list');
        if (!listContainer) return;

        try {
            const response = await fetch('api.php?action=get_teams');
            const teams = await response.json();

            if (teams.length === 0) {
                listContainer.innerHTML = '<p style="color: var(--text-gray); padding: 20px; text-align:center;">Brak otwartych rekrutacji na ten moment.</p>';
                return;
            }

            listContainer.innerHTML = teams.map(team => {
                const logo = team.logo || `https://api.dicebear.com/7.x/identicon/svg?seed=${encodeURIComponent(team.name || 'Team')}`;
                const members = Number(team.members_count || 0);
                const isFull = !!team.is_full;
                const requestStatus = team.join_request_status;

                return `
                    <div class="team-card open-team-card">
                        <div class="open-team-main">
                            <img src="${window.escapeHTML(logo)}" alt="Logo drużyny">

                            <div>
                                <div class="open-team-title">
                                    <span>${window.escapeHTML(team.tag || 'TEAM')}</span>
                                    <strong>${window.escapeHTML(team.name || 'Drużyna')}</strong>
                                </div>

                                <small>
                                    Lider: ${window.escapeHTML(team.captain_username || 'Nieznany')}
                                    • Skład: ${members}/6
                                </small>
                            </div>
                        </div>

                        <div class="open-team-actions">
                            <button class="btn-ok compact" onclick="teamProfileController.open('${window.escapeHTML(team.tag || '')}')">
                                Profil
                            </button>

                            ${teamController.renderJoinAction(team)}
                        </div>
                    </div>
                `;
            }).join('');

        } catch (err) {
            listContainer.innerHTML = '<p style="color: var(--brand-red);">Błąd ładowania otwartych drużyn.</p>';
        }
    },
    renderJoinAction: (team) => {
        const status = team.join_request_status;

        if (team.viewer_has_team) {
            return `
                <button class="btn-ok compact" disabled>
                    Masz drużynę
                </button>
            `;
        }

        if (team.is_full) {
            return `
                <button class="btn-cancel compact" disabled>
                    Pełny skład
                </button>
            `;
        }

        if (status === 'pending') {
            return `
                <button class="btn-ok compact" disabled>
                    Wysłano prośbę
                </button>
            `;
        }

        if (status === 'rejected') {
            return `
                <button class="btn-confirm compact" onclick="teamController.requestJoinTeam(${Number(team.id)})">
                    Aplikuj ponownie
                </button>
            `;
        }

        return `
            <button class="btn-confirm compact" onclick="teamController.requestJoinTeam(${Number(team.id)})">
                Aplikuj
            </button>
        `;
    },

    requestJoinTeam: async (teamId) => {
        try {
            const response = await window.apiFetch('api.php?action=request_join_team', {
                method: 'POST',
                body: JSON.stringify({
                    team_id: Number(teamId)
                })
            });

            const data = await response.json();

            if (!data.success) {
                window.Toast.show(data.message || 'Nie udało się wysłać prośby.', 'error');
                return;
            }

            window.Toast.show(data.message || 'Wysłano prośbę do lidera.', 'success');

            if (data.targetId && window.wsClient?.readyState === WebSocket.OPEN) {
                window.wsClient.send(JSON.stringify({
                    type: 'notify',
                    targetId: Number(data.targetId)
                }));
            }

            await teamController.loadOpenTeams();
        } catch (error) {
            console.error(error);
            window.Toast.show('Błąd podczas wysyłania prośby.', 'error');
        }
    },

    handleCreateTeam: async (e) => {
        e.preventDefault();
        const name = document.getElementById('team-name').value;
        const tag = document.getElementById('team-tag').value;
        const isOpen = document.getElementById('team-is-open').checked;

        try {
            const response = await window.apiFetch('api.php?action=create_team', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ name, tag, is_open: isOpen })
            });
            const data = await response.json();

            if (data.success) {
                window.Toast.show(data.message, 'success');
                window.authController.checkSession();
                await teamController.loadCurrentTeamState();
            } else {
                window.Toast.show(data.message, 'error');
            }
        } catch (err) {
            window.Toast.show('Błąd krytyczny podczas tworzenia drużyny.', 'error');
        }
    },

    renderActiveTeam: (team) => {
        document.getElementById('team-name-view').innerText = team.name;
        document.getElementById('team-tag-view').innerText = team.tag;
        document.getElementById('team-logo-view').src = team.logo;
        document.getElementById('team-type-status').innerText = team.is_open ? 'Typ: Rekrutacja Otwarta' : 'Typ: Zamknięta (Tylko Zaproszenia)';

        const grid = document.querySelector('.team-grid-layout');
        if (!grid) return;

        grid.innerHTML = '';

        const currentUserId = AppState.isLoggedIn() ? AppState.getUser().id : 0;
        const isCurrentUserLider = team.captain_id === currentUserId;

        // Renderujemy 6 slotów (5 głównych + 1 rezerwa)
        for (let i = 0; i < 6; i++) {
            const isSubSlot = i === 5;
            const member = team.members[i];

            if (member) {
                const isLider = member.is_captain;
                const roleClass = isLider ? 'leader' : (member.is_sub ? 'sub' : 'member');
                const roleText = isLider ? 'Lider' : (member.is_sub ? 'Rezerwa' : 'Gracz');
                
                // Generowanie unikalnego przycisku usuwania dla Lidera
                const kickBtn = (isCurrentUserLider && !isLider) ? `<button class="kick-player-btn" data-id="${member.id}">&times;</button>` : '';

                grid.innerHTML += `
                    <div class="player-team-card ${isLider ? 'captain-card' : ''}">
                        ${kickBtn}
                        <img src="https://ui-avatars.com/api/?name=${member.username}&background=121212&color=ff002b" style="width:70px; height:70px; border-radius:50%; margin-bottom:10px; object-fit:cover;">
                        <div style="font-weight:bold; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">${member.username}</div>
                        <span class="role-badge ${roleClass}">${roleText}</span>
                    </div>
                `;
            } else {
                const cursorStyle = isCurrentUserLider ? 'cursor: pointer;' : '';
                const hoverClass = isCurrentUserLider ? 'invite-slot' : '';

                grid.innerHTML += `
                    <div class="player-team-card empty-slot ${hoverClass}" style="${cursorStyle}" ${isCurrentUserLider ? 'onclick="teamController.openInvitePopout()"' : ''}>
                        <span style="color: rgba(255,255,255,0.15); font-size: 24px; font-weight: 300;">+</span>
                        <span style="color: var(--text-gray); font-size: 11px; margin-top:5px;">${isSubSlot ? 'Zaproś Rezerwę' : 'Zaproś Gracza'}</span>
                    </div>
                `;
            }
        }

        grid.querySelectorAll('.kick-player-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const id = btn.getAttribute('data-id');
                teamController.kickPlayer(id);
            });
        });

        const leaderPanel = document.getElementById('leader-panel');
        if (leaderPanel) {
            leaderPanel.style.display = isCurrentUserLider ? 'block' : 'none';
        }
    },

    updateLogo: async () => {
        const url = document.getElementById('team-logo-url-input').value;
        if (!url) return window.Toast.show('Wprowadź link URL!', 'error');

        try {
            const response = await window.apiFetch('api.php?action=update_team_logo', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ logo: url })
            });
            const data = await response.json();

            if (data.success) {
                window.Toast.show('Logo drużyny zostało zmienione.', 'success');
                document.getElementById('team-logo-url-input').value = '';
                await teamController.loadCurrentTeamState();
            } else {
                window.Toast.show(data.message, 'error');
            }
        } catch (err) {
            window.Toast.show('Błąd zapisu logo.', 'error');
        }
    },
    openInvitePopout: () => {
        const customHTML = `
            <h3>Wyszukaj zawodnika</h3>
            <p style="color: var(--text-gray); font-size: 13px; margin-bottom: 15px;">Wpisz co najmniej 2 znaki, aby wyszukać wolnych agentów.</p>
            
            <input type="text" id="popout-search-input" placeholder="Wpisz nick gracza..." style="width: 100%; padding: 12px; background: var(--bg-dark); border: 1px solid var(--border-color); border-radius: 6px; color: white;">
            
            <div id="popout-search-results" class="search-results-container"></div>
            
            <button class="btn-cancel" onclick="Popout.close()" style="width: 100%; margin-top: 20px;">Anuluj</button>
        `;

        window.Popout.create('Zaproś gracza', '', null, null, 'custom', customHTML);
        const searchInput = document.getElementById('popout-search-input');

        setTimeout(() => {
            if (searchInput) {
                 searchInput.focus();
                 searchInput.addEventListener('input', (e) => {
                     teamController.handlePlayerSearch(e.target.value);
                 });
            }
        }, 50);
    },
    handlePlayerSearch: async (query) => {
        const resultsContainer = document.getElementById('popout-search-results');
        if (!resultsContainer) return;

        if (query.length < 2) {
            resultsContainer.innerHTML = '';
            return;
        }
        try {
            const response = await fetch(`api.php?action=search_players&q=${encodeURIComponent(query)}`);
            const players = await response.json();

            if (players.length === 0) {
                resultsContainer.innerHTML = '<p style="text-align:center; color: var(--text-gray); font-size: 13px; padding: 10px;">Brak wyników lub gracze są już w drużynach.</p>';
                return;
            }
            resultsContainer.innerHTML = players.map(player => {
                const avatar = player.avatar ? player.avatar : `https://ui-avatars.com/api/?name=${player.username}&background=121212&color=ff002b`;
                return `
                    <div class="search-result-item">
                        <div style="display: flex; align-items: center;">
                            <img src="${avatar}" alt="Avatar">
                            <strong>${player.username}</strong>
                        </div>
                        <button class="nav-btn active" style="padding: 6px 12px; font-size: 11px;" onclick="teamController.invitePlayer('${player.username}')">Zaproś</button>
                    </div>
                `;
            }).join('');
        } catch (err) {
            resultsContainer.innerHTML = '<p style="text-align:center; color: var(--brand-red); font-size: 13px;">Błąd wyszukiwania.</p>';
        }
    },
    invitePlayer: async (username) => {
        try {
            const response = await window.apiFetch('api.php?action=invite_player', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ username: username })
            });
            const data = await response.json();

            if (data.success) {
                window.Toast.show(data.message, 'success');
                window.Popout.close();
                const targetUserId = data.targetId;
                if (window.wsClient && window.wsClient.readyState === WebSocket.OPEN) {
                    window.wsClient.send(JSON.stringify({
                        type: 'notify',
                        targetId: targetUserId // ID gracza, którego szturchamy
                    }));
                }
                await teamController.loadCurrentTeamState();
            } else {
                window.Toast.show(data.message, 'error');
            }
        } catch (err) {
            window.Toast.show('Błąd podczas wysyłania zaproszenia: ' + err, 'error');
        }
    },

    kickPlayer: async (playerId) => {
        window.Popout.create("Potwierdź", "Czy na pewno chcesz usunąć tego gracza ze składu?", null, async () => {
            try {
                const response = await window.apiFetch('api.php?action=kick_player', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ player_id: playerId })
                });
                const data = await response.json();

                if (data.success) {
                    window.Toast.show(data.message, 'info');
                    await teamController.loadCurrentTeamState();
                } else {
                    window.Toast.show(data.message, 'error');
                }
            } catch (err) {
                window.Toast.show('Błąd podczas usuwania gracza.', 'error');
            }
        }, 'confirm');
    },
    leaveTeam: async () => {
        try {
            const response = await window.apiFetch('api.php?action=leave_team', {
                method: 'POST'
            });
            const data = await response.json();
            if (data.success && data.action && data.action === 'popout') {
                window.Popout.create('Potwierdź akcję', 'Wychodząc jako ostatni członek drużyny, jednocześnie usuwasz ją całą. Potwierdź akcję, jeśli chcesz usunąć drużynę.', null, async () => {
                    const delResponse = await window.apiFetch('api.php?action=delete_team', {
                        method: 'POST'
                    });
                    const delData = await delResponse.json();
                    if (!delData.success) {
                        window.Toast.show(delData.message, 'error');
                        return;
                    }
                    window.Toast.show(delData.message);
                    window.authController.checkSession();
                    await teamController.loadCurrentTeamState();
                }, 'confirm');
            } else if (data.success && !data.action) {
                window.Toast.show(data.message);
            } else if (!data.success) {
                window.Toast.show(data.message, 'error');
            }
            window.authController.checkSession();
        } catch (err) {
            window.Toast.show('Wystąpił błąd', 'error');
        }
    }
};