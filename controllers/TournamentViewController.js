import { AppState } from "../state.js";

export const tournamentViewController = {
    currentId: null,
    lastData: null,

    init: async () => {
        window.tournamentViewController = tournamentViewController;

        let id = window.history.state?.id;

        if (!id) {
            const urlParams = new URLSearchParams(window.location.search);
            id = urlParams.get('id');
        }

        if (!id) {
            window.Toast.show('Błąd: Nie podano ID turnieju.', 'error');
            window.router.navigate('tournaments');
            return;
        }

        tournamentViewController.currentId = Number(id);
        await tournamentViewController.loadDetails();
    },

    loadDetails: async () => {
        try {
            const response = await fetch(`api.php?action=get_tournament&id=${tournamentViewController.currentId}`);
            const data = await response.json();

            if (!data.success) {
                window.Toast.show(data.message, 'error');
                window.router.navigate('tournaments');
                return;
            }

            tournamentViewController.lastData = data;

            tournamentViewController.renderTournament(data);
            tournamentViewController.renderJoinBox(data);
            tournamentViewController.renderParticipants(data);
            tournamentViewController.renderBracket(data);
            tournamentViewController.renderAdminControls(data);
        } catch (error) {
            console.error(error);
            window.Toast.show('Wystąpił błąd podczas pobierania danych turnieju.', 'error');
        }
    },

    renderTournament: (data) => {
        const t = data.tournament;
        const isOpen = Number(t.is_open) === 1;
        const lifecycleStatus = t.status || 'registration_open';

        document.getElementById('t-view-title').innerText = t.title;
        document.getElementById('t-view-creator').innerText = t.creator;

        const statusEl = document.getElementById('t-view-status');
        statusEl.innerText = `${tournamentViewController.tournamentStatusLabel(lifecycleStatus)} • ${isOpen ? 'PUBLICZNY' : 'KOD'}`;

        statusEl.classList.toggle('closed', lifecycleStatus !== 'registration_open');

        document.getElementById('t-view-sign-ends').innerText = t.sign_in_end
            ? new Date(t.sign_in_end).toLocaleString('pl-PL')
            : 'Brak limitu';

        document.getElementById('t-view-starts-at').innerText = t.starts_at
            ? new Date(t.starts_at).toLocaleString('pl-PL')
            : 'Brak daty';

        const adminBox = document.getElementById('t-view-admin-box');
        const joinCodeBox = document.getElementById('t-view-join-code-box');
        const joinCodeEl = document.getElementById('t-view-join-code');

        if (adminBox) {
            adminBox.classList.toggle('hidden', !data.is_admin);
        }

        if (joinCodeBox && joinCodeEl) {
            if (data.is_admin && !isOpen && t.join_code) {
                joinCodeBox.classList.remove('hidden');
                joinCodeEl.textContent = t.join_code;
            } else {
                joinCodeBox.classList.add('hidden');
                joinCodeEl.textContent = '-';
            }
        }
    },

    renderJoinBox: (data) => {
        const button = document.getElementById('btn-join-tournament');
        const hint = document.getElementById('t-view-join-hint');
        if (!button || !hint) return;

        const t = data.tournament;
        const isOpen = Number(t.is_open) === 1;
        const lifecycleStatus = t.status || 'registration_open';
        const registration = data.user_registration;
        const team = data.user_team;

        button.disabled = false;
        button.onclick = () => tournamentViewController.joinTournament();
        button.textContent = 'Zapisz swoją drużynę';
        hint.textContent = '';

        if (lifecycleStatus !== 'registration_open') {
            button.disabled = true;
            button.textContent = tournamentViewController.tournamentStatusLabel(lifecycleStatus);
            hint.textContent = 'Zapisy do tego turnieju nie są obecnie otwarte.';
            return;
        }

        if (data.started) {
            button.disabled = true;
            button.textContent = 'Turniej wystartował';
            hint.textContent = 'Nie można już dołączyć do tego turnieju.';
            return;
        }

        if (data.signups_ended) {
            button.disabled = true;
            button.textContent = 'Zapisy zamknięte';
            hint.textContent = 'Termin zapisów do tego turnieju minął.';
            return;
        }

        if (!team) {
            button.disabled = true;
            button.textContent = 'Brak drużyny';
            hint.textContent = 'Musisz należeć do drużyny, żeby zapisać się do turnieju.';
            return;
        }

        if (Number(team.captain_id) !== Number(AppState.getUser()?.id)) {
            button.disabled = true;
            button.textContent = 'Tylko kapitan';
            hint.textContent = 'Tylko kapitan może zapisać drużynę do turnieju.';
            return;
        }

        if (Number(team.core_count) < 5) {
            button.disabled = true;
            button.textContent = 'Za mały skład';
            hint.textContent = `Masz ${Number(team.core_count)}/5 podstawowych graczy. Rezerwa jest opcjonalna.`;
            return;
        }

        if (registration?.status === 'approved') {
            button.textContent = 'Wycofaj drużynę';
            button.onclick = () => tournamentViewController.leaveTournament();
            hint.textContent = 'Twoja drużyna jest zapisana do tego turnieju.';
            return;
        }

        if (registration?.status === 'pending') {
            button.textContent = 'Wycofaj zgłoszenie';
            button.onclick = () => tournamentViewController.leaveTournament();
            hint.textContent = 'Zgłoszenie oczekuje na weryfikację admina.';
            return;
        }

        if (!isOpen) {
            button.textContent = 'Dołącz kodem';
            button.onclick = () => window.tournamentController?.showJoin?.();
            hint.textContent = 'To zamknięty turniej. Wpisz kod dołączenia w formularzu.';
            return;
        }

        hint.textContent = 'Otwarty turniej — pełna drużyna zostanie zapisana automatycznie.';
    },

    renderParticipants: (data) => {
        const container = document.getElementById('t-view-participants');
        if (!container) return;

        const participants = data.participants || [];

        if (!participants.length) {
            container.innerHTML = '<div class="empty-state">Nikt jeszcze nie dołączył do tego starcia.</div>';
            return;
        }

        container.innerHTML = participants
            .map(item => tournamentViewController.renderParticipantCard(item, data.is_admin))
            .join('');
    },

    renderParticipantCard: (item, isAdmin) => {
        const logo = item.logo || 'https://api.dicebear.com/7.x/identicon/svg?seed=Clutch';
        const statusLabel = tournamentViewController.statusLabel(item.status);
        const showActions = isAdmin && item.status === 'pending';

        return `
            <article class="tournament-participant-card status-${window.escapeHTML(item.status)}">
                <img src="${window.escapeHTML(logo)}" alt="Logo drużyny">

                <div class="participant-main">
                    <div>
                        <strong>[${window.escapeHTML(item.tag)}] ${window.escapeHTML(item.name)}</strong>
                        <span>Kapitan: ${window.escapeHTML(item.captain_username || '-')}</span>
                    </div>

                    <small>
                        Skład: ${Number(item.core_count)}/5 + ${Math.max(0, Number(item.total_count) - Number(item.core_count))} rez.
                    </small>

                    ${item.verification_note ? `<p>${window.escapeHTML(item.verification_note)}</p>` : ''}
                </div>

                <div class="participant-side">
                    <span class="participant-status">${statusLabel}</span>

                    ${showActions ? `
                        <div class="participant-actions">
                            <button
                                class="btn-confirm compact"
                                onclick="tournamentViewController.reviewRegistration(${Number(item.registration_id)}, 'approve')"
                            >
                                Akceptuj
                            </button>

                            <button
                                class="btn-cancel compact"
                                onclick="tournamentViewController.reviewRegistration(${Number(item.registration_id)}, 'reject')"
                            >
                                Odrzuć
                            </button>
                        </div>
                    ` : ''}
                </div>
            </article>
        `;
    },

    statusLabel: (status) => {
        const labels = {
            pending: 'Oczekuje',
            approved: 'Zapisana',
            rejected: 'Odrzucona',
            left: 'Wycofana'
        };

        return labels[status] || status;
    },

    tournamentStatusLabel: (status) => {
        const labels = {
            registration_open: 'Zapisy otwarte',
            registration_closed: 'Zapisy zamknięte',
            in_progress: 'W trakcie',
            finished: 'Zakończony',
            cancelled: 'Anulowany'
        };

        return labels[status] || 'Nieznany status';
    },

    renderBracket: (data) => {
        const container = document.getElementById('t-view-bracket');
        if (!container) return;

        const matches = data.matches || [];

        if (!matches.length) {
            container.innerHTML = `
                <div class="empty-state">
                    Bracket nie został jeszcze wygenerowany.
                </div>
            `;
            return;
        }

        const rounds = {};

        matches.forEach(match => {
            const round = Number(match.round_number);

            if (!rounds[round]) {
                rounds[round] = [];
            }

            rounds[round].push(match);
        });

        const roundNumbers = Object.keys(rounds)
            .map(Number)
            .sort((a, b) => a - b);

        const finalRound = Math.max(...roundNumbers);

        container.innerHTML = `
            <div class="tournament-bracket">
                ${roundNumbers.map(roundNumber => `
                    <section class="bracket-round">
                        <h3>${window.escapeHTML(tournamentViewController.roundLabel(roundNumber, finalRound))}</h3>

                        <div class="bracket-match-list">
                            ${rounds[roundNumber]
                                .sort((a, b) => Number(a.match_number) - Number(b.match_number))
                                .map(match => tournamentViewController.renderBracketMatch(match))
                                .join('')}
                        </div>
                    </section>
                `).join('')}
            </div>
        `;
    },

    renderBracketMatch: (match) => {
        return `
            <article class="bracket-match status-${window.escapeHTML(match.status)}">
                <div class="bracket-match-header">
                    <span>Mecz #${Number(match.match_number)}</span>
                    <strong>${window.escapeHTML(tournamentViewController.matchStatusLabel(match.status))}</strong>
                </div>

                ${tournamentViewController.renderBracketTeam(match, 'a')}
                ${tournamentViewController.renderBracketTeam(match, 'b')}

                ${tournamentViewController.renderMatchLobbyAction(match)}
            </article>
        `;
    },

    renderBracketTeam: (match, side) => {
        const id = match[`team_${side}_id`];
        const name = match[`team_${side}_name`];
        const tag = match[`team_${side}_tag`];

        const isWinner = id && Number(match.winner_team_id) === Number(id);

        if (!id) {
            return `
                <div class="bracket-team is-empty">
                    <span>TBD</span>
                </div>
            `;
        }

        return `
            <div class="bracket-team ${isWinner ? 'is-winner' : ''}">
                <span>
                    ${tag ? `[${window.escapeHTML(tag)}] ` : ''}
                    ${window.escapeHTML(name || 'Drużyna')}
                </span>

                ${isWinner ? '<strong>WIN</strong>' : ''}
            </div>
        `;
    },

    renderBracketMatch: (match) => {
        return `
            <article class="bracket-match status-${window.escapeHTML(match.status)}">
                <div class="bracket-match-header">
                    <span>Mecz #${Number(match.match_number)}</span>
                    <strong>${window.escapeHTML(tournamentViewController.matchStatusLabel(match.status))}</strong>
                </div>

                ${tournamentViewController.renderBracketTeam(match, 'a')}
                ${tournamentViewController.renderBracketTeam(match, 'b')}
                ${tournamentViewController.renderMatchLobbyAction(match)}
            </article>
        `;
    },

    renderMatchLobbyAction: (match) => {
        const hasBothTeams = !!match.team_a_id && !!match.team_b_id;

        if (!hasBothTeams) {
            return '';
        }

        return `
            <div class="bracket-match-actions">
                <button class="btn-ok compact" onclick="tournamentViewController.openMatchLobby(${Number(match.id)})">
                    Otwórz lobby
                </button>
            </div>
        `;
    },

    openMatchLobby: (matchId) => {
        window.router.navigate('match', true, {
            id: Number(matchId)
        });
    },

    roundLabel: (roundNumber, finalRound) => {
        if (roundNumber === finalRound) {
            return 'Finał';
        }

        if (roundNumber === finalRound - 1) {
            return 'Półfinał';
        }

        return `Runda ${roundNumber}`;
    },

    matchStatusLabel: (status) => {
        const labels = {
            pending: 'Oczekuje',
            ready_check: 'Ready',
            live: 'Live',
            finished: 'Zakończony',
            cancelled: 'Anulowany'
        };

        return labels[status] || status;
    },

    renderAdminControls: (data) => {
        const statusEl = document.getElementById('t-view-admin-status');
        const controlsEl = document.getElementById('t-view-admin-controls');

        if (!statusEl || !controlsEl) return;

        if (!data.is_admin) {
            statusEl.innerHTML = '';
            controlsEl.innerHTML = '';
            return;
        }

        const tournament = data.tournament;
        const status = tournament.status || 'registration_open';

        const approvedCount = (data.participants || []).filter(item => item.status === 'approved').length;
        const pendingCount = (data.participants || []).filter(item => item.status === 'pending').length;

        statusEl.innerHTML = `
            <div>
                <span>Status turnieju</span>
                <strong>${window.escapeHTML(tournamentViewController.tournamentStatusLabel(status))}</strong>
            </div>

            <div>
                <span>Zatwierdzone drużyny</span>
                <strong>${approvedCount}</strong>
            </div>

            <div>
                <span>Oczekujące zgłoszenia</span>
                <strong>${pendingCount}</strong>
            </div>
        `;

        if (status === 'registration_open') {
            controlsEl.innerHTML = `
                <button class="btn-cancel" onclick="tournamentViewController.closeRegistration()">
                    Zamknij zapisy
                </button>

                <button class="btn-ok" disabled title="Najpierw zamknij zapisy">
                    Wygeneruj bracket
                </button>
            `;
            return;
        }

        if (status === 'registration_closed') {
            const hasBracket = (data.matches || []).length > 0;

            let generateButton = '';

            if (hasBracket) {
                generateButton = `
                    <button class="btn-confirm" disabled>
                        Bracket już wygenerowany
                    </button>
                `;
            } else if (pendingCount > 0) {
                generateButton = `
                    <button class="btn-confirm" disabled title="Najpierw rozpatrz oczekujące zgłoszenia">
                        Wygeneruj bracket
                    </button>
                `;
            } else if (approvedCount < 2) {
                generateButton = `
                    <button class="btn-confirm" disabled title="Potrzeba minimum 2 zatwierdzonych drużyn">
                        Wygeneruj bracket
                    </button>
                `;
            } else {
                generateButton = `
                    <button class="btn-confirm" onclick="tournamentViewController.generateBracket()">
                        Wygeneruj bracket
                    </button>
                `;
            }

            controlsEl.innerHTML = `
                <button class="btn-ok" onclick="tournamentViewController.reopenRegistration()">
                    Otwórz zapisy ponownie
                </button>

                ${generateButton}
            `;
            return;
        }

        if (status === 'in_progress') {
            controlsEl.innerHTML = `
                <button class="btn-ok" disabled>
                    Turniej jest w trakcie
                </button>
            `;
            return;
        }

        controlsEl.innerHTML = `
            <button class="btn-ok" disabled>
                Brak dostępnych akcji
            </button>
        `;
    },

    confirmAction: ({ title, message, onConfirm }) => {
        if (!window.Popout?.create) {
            const confirmed = confirm(message);
            if (confirmed && typeof onConfirm === 'function') {
                onConfirm();
            }
            return;
        }

        window.Popout.create(
            title,
            message,
            null,
            async () => {
                if (typeof onConfirm === 'function') {
                    await onConfirm();
                }
            },
            'confirm'
        );
    },

    generateBracket: async () => {
        tournamentViewController.confirmAction({
            title: 'Wygenerować bracket?',
            message: 'Zostaną utworzone mecze, a turniej przejdzie w status "W trakcie".',
            onConfirm: async () => {
                const response = await window.apiFetch('api.php?action=generate_bracket', {
                    method: 'POST',
                    body: JSON.stringify({
                        tournament_id: tournamentViewController.currentId
                    })
                });

                const data = await response.json();

                if (data.success) {
                    window.Toast.show(data.message, 'success');
                    await tournamentViewController.loadDetails();
                } else {
                    window.Toast.show(data.message, 'error');
                }
            }
        });
    },

    closeRegistration: async () => {
        tournamentViewController.confirmAction({
            title: 'Zamknąć zapisy?',
            message: 'Nowe drużyny nie będą mogły już dołączyć, ale nadal możesz zaakceptować oczekujące zgłoszenia.',
            onConfirm: async () => {
                const response = await window.apiFetch('api.php?action=close_tournament_registration', {
                    method: 'POST',
                    body: JSON.stringify({
                        tournament_id: tournamentViewController.currentId
                    })
                });

                const data = await response.json();

                if (data.success) {
                    window.Toast.show(data.message, 'success');
                    await tournamentViewController.loadDetails();
                } else {
                    window.Toast.show(data.message, 'error');
                }
            }
        });
    },

    reopenRegistration: async () => {
        tournamentViewController.confirmAction({
            title: 'Otworzyć zapisy ponownie?',
            message: 'Drużyny będą mogły znowu wysyłać zgłoszenia.',
            onConfirm: async () => {
                const response = await window.apiFetch('api.php?action=reopen_tournament_registration', {
                    method: 'POST',
                    body: JSON.stringify({
                        tournament_id: tournamentViewController.currentId
                    })
                });

                const data = await response.json();

                if (data.success) {
                    window.Toast.show(data.message, 'success');
                    await tournamentViewController.loadDetails();
                } else {
                    window.Toast.show(data.message, 'error');
                }
            }
        });
    },

    joinTournament: async () => {
        const response = await window.apiFetch('api.php?action=join_tournament', {
            method: 'POST',
            body: JSON.stringify({
                tournament_id: tournamentViewController.currentId
            })
        });

        const data = await response.json();

        if (data.success) {
            window.Toast.show(data.message, data.status === 'pending' ? 'info' : 'success');
            await tournamentViewController.loadDetails();
        } else {
            window.Toast.show(data.message, 'error');
        }
    },

    leaveTournament: async () => {
        const response = await window.apiFetch('api.php?action=leave_tournament', {
            method: 'POST',
            body: JSON.stringify({
                tournament_id: tournamentViewController.currentId
            })
        });

        const data = await response.json();

        if (data.success) {
            window.Toast.show(data.message, 'info');
            await tournamentViewController.loadDetails();
        } else {
            window.Toast.show(data.message, 'error');
        }
    },

    reviewRegistration: async (registrationId, decision) => {
        const response = await window.apiFetch('api.php?action=review_tournament_team', {
            method: 'POST',
            body: JSON.stringify({
                registration_id: registrationId,
                decision
            })
        });

        const data = await response.json();

        if (data.success) {
            window.Toast.show(data.message, 'success');
            await tournamentViewController.loadDetails();
        } else {
            window.Toast.show(data.message, 'error');
        }
    }
};