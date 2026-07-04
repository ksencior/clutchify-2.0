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

            listContainer.innerHTML = teams.map(team => `
                <div class="team-card" style="background: var(--bg-card); padding: 15px; border: 1px solid var(--border-color); border-radius: 8px; margin-bottom: 10px; display: flex; justify-content: space-between; align-items: center;">
                    <div style="display:flex; align-items:center; gap: 15px;">
                        <img src="${team.logo}" style="width:40px; height:40px; border-radius:6px; background:#222; object-fit:cover;">
                        <div>
                            <span style="background: rgba(255,0,43,0.1); color: var(--brand-red); padding: 2px 6px; border-radius: 4px; font-size: 11px; font-weight: bold; margin-right: 10px;">${team.tag}</span>
                            <strong style="font-size: 16px;">${team.name}</strong>
                        </div>
                    </div>
                    <button class="nav-btn apply-action-btn" data-team="${team.name}" style="background: rgba(255,255,255,0.05); padding: 6px 12px; font-size: 11px;">Aplikuj</button>
                </div>
            `).join('');

            // Podpięcie eventów pod dynamiczne przyciski "Aplikuj"
            listContainer.querySelectorAll('.apply-action-btn').forEach(btn => {
                btn.addEventListener('click', () => {
                    window.Toast.show(`Pomyślnie wysłano podanie do ${btn.getAttribute('data-team')}!`, 'success');
                });
            });

        } catch (err) {
            listContainer.innerHTML = '<p style="color: var(--brand-red);">Błąd ładowania otwartych drużyn.</p>';
        }
    },

    handleCreateTeam: async (e) => {
        e.preventDefault();
        const name = document.getElementById('team-name').value;
        const tag = document.getElementById('team-tag').value;
        const isOpen = document.getElementById('team-is-open').checked;

        try {
            const response = await fetch('api.php?action=create_team', {
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
            const response = await fetch('api.php?action=update_team_logo', {
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
            const response = await fetch('api.php?action=invite_player', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ username: username })
            });
            const data = await response.json();

            if (data.success) {
                window.Toast.show(data.message, 'success');
                window.Popout.close();
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
                const response = await fetch('api.php?action=kick_player', {
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
            const response = await fetch('api.php?action=leave_team');
            const data = await response.json();
            if (data.success && data.action && data.action === 'popout') {
                window.Popout.create('Potwierdź akcję', 'Wychodząc jako ostatni członek drużyny, jednocześnie usuwasz ją całą. Potwierdź akcję, jeśli chcesz usunąć drużynę.', null, async () => {
                    const delResponse = await fetch('api.php?action=delete_team');
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