import { AppState } from '../state.js';

export const teamController = {
    scrimData: null,
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
        teamController.currentTeam = team;
        teamController.loadScrimCenter();

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
    },
    loadScrimCenter: async () => {
        const form = document.getElementById('scrim-leader-form');
        const myPost = document.getElementById('scrim-my-post');
        const openPosts = document.getElementById('scrim-open-posts');
        const offers = document.getElementById('scrim-offers');

        if (!form || !myPost || !openPosts || !offers) return;

        try {
            const response = await fetch('api.php?action=get_scrim_center');
            const data = await response.json();

            if (!data.success || !data.has_team) {
                return;
            }

            teamController.scrimData = data;

            const isCaptain = !!data.my_team?.is_captain || !!data.my_team?.is_admin;
            form.style.display = isCaptain && !data.my_post ? 'block' : 'none';

            teamController.renderMyScrimPost(data.my_post);
            teamController.renderOpenScrims(data.open_posts || [], isCaptain);
            teamController.renderScrimOffers(data, isCaptain);
        } catch (error) {
            console.error(error);
        }
    },

    scrimSettingsLabel: (item) => {
        const ff = item.friendly_fire ? 'FF ON' : 'FF OFF';
        const ot = item.overtime_enabled ? 'OT ON' : 'OT OFF';
        const knife = item.knife_round ? 'Knife ON' : 'Knife OFF';

        return `${String(item.match_format || 'bo1').toUpperCase()} • MR${Number(item.mr || 12)} • ${ff} • ${ot} • ${knife}`;
    },

    renderMyScrimPost: (post) => {
        const container = document.getElementById('scrim-my-post');
        if (!container) return;

        if (!post) {
            container.innerHTML = '';
            return;
        }

        container.innerHTML = `
            <article class="scrim-post-card is-own">
                <div>
                    <span class="eyebrow">Twoje ogłoszenie</span>
                    <h3>${window.escapeHTML(post.title)}</h3>
                    <p>${window.escapeHTML(post.description || 'Brak opisu.')}</p>
                    <small>${teamController.scrimSettingsLabel(post)}</small>
                </div>

                <button class="btn-cancel compact" onclick="teamController.closeScrimPost(${Number(post.id)})">
                    Zamknij
                </button>
            </article>
        `;
    },

    renderOpenScrims: (posts, isCaptain) => {
        const container = document.getElementById('scrim-open-posts');
        if (!container) return;

        if (!posts.length) {
            container.innerHTML = '<div class="empty-state">Brak otwartych scrimów.</div>';
            return;
        }

        container.innerHTML = posts.map(post => `
            <article class="scrim-post-card">
                <div>
                    <strong>[${window.escapeHTML(post.team_tag)}] ${window.escapeHTML(post.team_name)}</strong>
                    <h3>${window.escapeHTML(post.title)}</h3>
                    <p>${window.escapeHTML(post.description || 'Brak opisu.')}</p>
                    <small>${teamController.scrimSettingsLabel(post)}</small>
                </div>

                ${isCaptain ? `
                    <button class="btn-confirm compact" onclick="teamController.sendScrimOffer(${Number(post.id)})">
                        Złóż ofertę
                    </button>
                ` : `
                    <button class="btn-ok compact" disabled>
                        Tylko lider
                    </button>
                `}
            </article>
        `).join('');
    },

    renderScrimOffers: (data, isCaptain) => {
        const container = document.getElementById('scrim-offers');
        if (!container) return;

        const incoming = data.incoming_offers || [];
        const outgoing = data.outgoing_offers || [];

        if (!incoming.length && !outgoing.length) {
            container.innerHTML = '<div class="empty-state">Brak ofert scrimowych.</div>';
            return;
        }

        const incomingHtml = incoming.map(offer => `
            <article class="scrim-offer-card">
                <div>
                    <strong>Oferta od [${window.escapeHTML(offer.challenger_tag)}] ${window.escapeHTML(offer.challenger_name)}</strong>
                    <p>${window.escapeHTML(offer.message || 'Brak wiadomości.')}</p>
                    <small>Do ogłoszenia: ${window.escapeHTML(offer.post_title || '-')}</small>
                </div>

                ${isCaptain ? `
                    <div class="scrim-offer-actions">
                        <button class="btn-confirm compact" onclick="teamController.respondScrimOffer(${Number(offer.id)}, 'accept')">
                            Akceptuj
                        </button>

                        <button class="btn-cancel compact" onclick="teamController.respondScrimOffer(${Number(offer.id)}, 'reject')">
                            Odrzuć
                        </button>
                    </div>
                ` : ''}
            </article>
        `).join('');

        const outgoingHtml = outgoing.map(offer => `
            <article class="scrim-offer-card">
                <div>
                    <strong>Oferta do [${window.escapeHTML(offer.owner_tag)}] ${window.escapeHTML(offer.owner_name)}</strong>
                    <p>Status: ${window.escapeHTML(offer.status)}</p>
                    ${offer.match_id ? `
                        <button class="btn-ok compact" onclick="teamController.openScrimMatch(${Number(offer.match_id)})">
                            Otwórz lobby
                        </button>
                    ` : ''}
                </div>
            </article>
        `).join('');

        container.innerHTML = incomingHtml + outgoingHtml;
    },

    createScrimPost: async () => {
        const payload = {
            title: document.getElementById('scrim-title')?.value || '',
            description: document.getElementById('scrim-description')?.value || '',
            match_format: document.getElementById('scrim-format')?.value || 'bo1',
            mr: Number(document.getElementById('scrim-mr')?.value || 12),
            friendly_fire: document.getElementById('scrim-friendly-fire')?.checked ? 1 : 0,
            overtime_enabled: document.getElementById('scrim-overtime')?.checked ? 1 : 0,
            knife_round: document.getElementById('scrim-knife-round')?.checked ? 1 : 0
        };

        const response = await window.apiFetch('api.php?action=create_scrim_post', {
            method: 'POST',
            body: JSON.stringify(payload)
        });

        const data = await response.json();

        if (!data.success) {
            window.Toast.show(data.message || 'Nie udało się utworzyć scrima.', 'error');
            return;
        }

        window.Toast.show(data.message || 'Scrim opublikowany.', 'success');
        await teamController.loadScrimCenter();
    },

    closeScrimPost: async (postId) => {
        const response = await window.apiFetch('api.php?action=close_scrim_post', {
            method: 'POST',
            body: JSON.stringify({
                post_id: Number(postId)
            })
        });

        const data = await response.json();

        if (!data.success) {
            window.Toast.show(data.message || 'Nie udało się zamknąć scrima.', 'error');
            return;
        }

        window.Toast.show(data.message || 'Ogłoszenie zamknięte.', 'success');
        await teamController.loadScrimCenter();
    },

    sendScrimOffer: (postId) => {
        const html = `
            <p style="color: var(--text-gray); font-size: 13px; margin-bottom: 12px;">
                Możesz dodać krótką wiadomość do lidera drugiej drużyny.
            </p>

            <textarea id="scrim-offer-message" rows="4" placeholder="Np. możemy grać za 10 minut" style="width:100%; padding:12px; background:var(--bg-dark); border:1px solid var(--border-color); border-radius:8px; color:white;"></textarea>

            <div style="display:flex; gap:10px; margin-top:14px;">
                <button class="btn-confirm" onclick="teamController.confirmScrimOffer(${Number(postId)})">
                    Wyślij ofertę
                </button>
                <button class="btn-cancel" onclick="Popout.close()">
                    Anuluj
                </button>
            </div>
        `;

        window.Popout.create('Złożyć ofertę scrima?', '', null, null, 'custom', html);
    },

    confirmScrimOffer: async (postId) => {
        const message = document.getElementById('scrim-offer-message')?.value || '';

        const response = await window.apiFetch('api.php?action=send_scrim_offer', {
            method: 'POST',
            body: JSON.stringify({
                post_id: Number(postId),
                message
            })
        });

        const data = await response.json();

        if (!data.success) {
            window.Toast.show(data.message || 'Nie udało się wysłać oferty.', 'error');
            return;
        }

        window.Popout.close();
        window.Toast.show(data.message || 'Oferta wysłana.', 'success');

        if (data.target_ids?.length && window.wsClient?.readyState === WebSocket.OPEN) {
            data.target_ids.forEach(id => {
                window.wsClient.send(JSON.stringify({
                    type: 'notify',
                    targetId: Number(id)
                }));
            });
        }

        await teamController.loadScrimCenter();
    },

    respondScrimOffer: async (offerId, decision) => {
        const response = await window.apiFetch('api.php?action=respond_scrim_offer', {
            method: 'POST',
            body: JSON.stringify({
                offer_id: Number(offerId),
                decision
            })
        });

        const data = await response.json();

        if (!data.success) {
            window.Toast.show(data.message || 'Nie udało się obsłużyć oferty.', 'error');
            return;
        }

        window.Toast.show(data.message || 'Oferta obsłużona.', 'success');

        if (data.target_ids?.length && window.wsClient?.readyState === WebSocket.OPEN) {
            data.target_ids.forEach(id => {
                window.wsClient.send(JSON.stringify({
                    type: 'notify',
                    targetId: Number(id)
                }));
            });

            if (data.match_id) {
                window.wsClient.send(JSON.stringify({
                    type: 'match_lobby_update',
                    matchId: Number(data.match_id),
                    targetIds: data.target_ids.map(Number)
                }));
            }
        }

        await teamController.loadScrimCenter();

        if (data.match_id && decision === 'accept') {
            teamController.openScrimMatch(Number(data.match_id));
        }
    },

    openScrimMatch: (matchId) => {
        history.pushState(
            {
                view: 'match',
                id: Number(matchId)
            },
            '',
            `/match?id=${Number(matchId)}`
        );

        router.navigate('match', false, {
            id: Number(matchId)
        });
    },
};