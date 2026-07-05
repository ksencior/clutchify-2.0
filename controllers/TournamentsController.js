import { AppState } from "../state.js";

export const tournamentController = {
    init: () => {
        window.tournamentController = tournamentController;
        tournamentController.loadTournaments();
        if (!AppState.getUser().player.isAdmin) {
            const createBtn = document.getElementById('create-tournament');
            if (createBtn) {
                createBtn.remove();
            }
        }
    },
    showCreation: () => {
        const popHTML = `
            <p style="color: var(--text-gray); font-size: 13px; margin-bottom: 15px;">Wypełnij formularz, aby stworzyć nowy turniej</p>
            
            <form id="tournament-creation-form">
                <input type="text" id="tournament-name-input" placeholder="Wpisz nazwę turnieju" style="width: 100%; padding: 12px; background: var(--bg-dark); border: 1px solid var(--border-color); border-radius: 6px; color: white;">
                <input type="text" id="tournament-creator-input" placeholder="Wpisz organizatora turnieju" style="width: 100%; padding: 12px; background: var(--bg-dark); border: 1px solid var(--border-color); border-radius: 6px; color: white;">
                <div class="form-group" style="margin: 10px;">
                    <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                        <input type="checkbox" id="tournament-is-open" style="accent-color: var(--brand-red);"> Turniej otwarty
                    </label>
                </div>
                <p style="color: var(--text-gray); font-size: 13px; margin-bottom: 15px;">Data zakończenia zapisów</p>
                <input type="datetime-local" id="tournament-sign-in-input">
                <p style="color: var(--text-gray); font-size: 13px; margin-bottom: 15px;">Data startu rozgrywek</p>
                <input type="datetime-local" id="tournament-starts-at-input">
                <div style="display: flex; align-items: center; justify-content: space-around; margin-top: 5px">
                    <input type="reset" value="Anuluj" class="btn-cancel" onclick="Popout.close()" style="margin: 0; margin-top: auto;">
                    <input type="submit" value="Prześlij" class="btn-confirm" style="margin: 0; margin-top: auto;">
                </div>
            </form>
        `;
        window.Popout.create("Utwórz turniej", '', null, null, 'custom', popHTML);

        const formEl = document.getElementById('tournament-creation-form');
        if (!formEl) return;

        formEl.addEventListener('submit', async (e) => {
            e.preventDefault();
            const title = document.getElementById('tournament-name-input').value;
            const creator = document.getElementById('tournament-creator-input').value;
            const isOpen = document.getElementById('tournament-is-open').checked;
            const signEnds = document.getElementById('tournament-sign-in-input').value;
            const startsAt = document.getElementById('tournament-starts-at-input').value;

            await tournamentController.createTournament(title, creator, isOpen, signEnds, startsAt);
        });
    },
    createTournament: async (title, creator, isOpen, signEnds, startsAt) => {
        console.log(isOpen)
        try {
            const response = await window.apiFetch('api.php?action=create_tournament', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ title: title, creator: creator, isOpen: isOpen, signEnds: Date.parse(signEnds), startsAt: Date.parse(startsAt) })
            });
            const data = await response.json();
            console.log(data);
            if (data.success) {
                window.Popout.close();
                window.Toast.show(data.message, 'success');

                if (data.join_code) {
                    window.Popout.create(
                        'Kod turnieju',
                        `
                            Zamknięty turniej został utworzony.<br><br>
                            Kod dołączenia:<br>
                            <code style="display: inline-block; margin-top: 10px; padding: 10px 12px; background: var(--bg-dark); border: 1px solid var(--border-color); border-radius: 8px; color: var(--brand-red); font-weight: 800;">
                                ${window.escapeHTML(data.join_code)}
                            </code>
                            <br><br>
                            Udostępnij go tylko kapitanom. Każde zgłoszenie nadal wymaga akceptacji admina.
                        `,
                        null,
                        null,
                        'info'
                    );
                }
            } else {
                window.Toast.show(data.message, 'error');
            }
        } catch (err) {
            console.error(err);
        }
        tournamentController.loadTournaments();
    },
    loadTournaments: async () => {
        const container = document.getElementById('tournaments-container');
        if (!container) return;
        container.innerHTML = '';

        const response = await fetch('api.php?action=get_open_tournaments');
        const data = await response.json();

        if (data.success) {
            if (data.items.length === 0) {
                container.innerHTML = '<div class="empty-state">Brak otwartych turniejów. Sprawdź później.</div>';
                return;
            }

            data.items.forEach(tournament => {
                const dateFormated = tournament.sign_in_end
                    ? tournament.sign_in_end.toString()
                    : 'Nie ustawiono';

                const tItem = document.createElement('article');

                tItem.classList.add('tournament-item');
                tItem.id = `tournament-${tournament.id}`;

                tItem.innerHTML = `
                    <div class="tournament-item-icon">🏆</div>

                    <div class="tournament-item-main">
                        <span class="tournament-card-label">Otwarte zapisy</span>
                        <h2>${window.escapeHTML(tournament.title)}</h2>
                        <p>Organizator: ${window.escapeHTML(tournament.creator)}</p>
                    </div>

                    <div class="tournament-item-meta">
                        <span>Zapisy do</span>
                        <strong>${window.escapeHTML(dateFormated)}</strong>
                    </div>

                    <button
                        class="btn-confirm"
                        onclick="window.tournamentController.openTournament(${Number(tournament.id)})"
                    >
                        Zobacz
                    </button>
                `;

                container.append(tItem);
            });
        } else {
            window.Toast.show('Wystąpił błąd: ' + data.message, 'error');
        }
    },
    showJoin: () => {
        const popHTML = `
            <p style="color: var(--text-gray); font-size: 13px; margin-bottom: 15px;">
                Wpisz kod dołączenia do zamkniętego turnieju. Kod nie daje slota automatycznie — zgłoszenie musi zatwierdzić admin.
            </p>

            <input
                type="text"
                id="tournament-join-input"
                placeholder="Kod turnieju"
                style="width: 100%; padding: 12px; background: var(--bg-dark); border: 1px solid var(--border-color); border-radius: 6px; color: white;"
            >

            <textarea
                id="tournament-verification-note"
                placeholder="Krótka weryfikacja, np. szkoła, klasa, opiekun, kontakt do kapitana..."
                style="width: 100%; min-height: 90px; margin-top: 10px; padding: 12px; background: var(--bg-dark); border: 1px solid var(--border-color); border-radius: 6px; color: white; resize: vertical;"
            ></textarea>

            <div style="display: flex; align-items: center; justify-content: space-around; margin-top: 12px">
                <button class="btn-cancel" onclick="Popout.close()" style="margin: 0; margin-top: auto;">
                    Anuluj
                </button>

                <button class="btn-confirm" onclick="window.tournamentController.joinTournament()" style="margin: 0; margin-top: auto;">
                    Wyślij zgłoszenie
                </button>
            </div>
        `;

        window.Popout.create("Dołącz do turnieju", '', null, null, 'custom', popHTML);
    },
    joinTournament: async () => {
        const providedCode = document.getElementById('tournament-join-input')?.value.trim();
        const verificationNote = document.getElementById('tournament-verification-note')?.value.trim() || '';

        if (!providedCode) {
            window.Toast.show("Nie podano kodu dołączenia.", 'error');
            return;
        }

        const response = await window.apiFetch('api.php?action=join_tournament', {
            method: 'POST',
            body: JSON.stringify({
                join_code: providedCode,
                verification_note: verificationNote
            })
        });

        const data = await response.json();

        if (data.success) {
            window.Toast.show(data.message, data.status === 'pending' ? 'info' : 'success');
            window.Popout.close();

            if (data.tournament_id) {
                window.tournamentController.openTournament(data.tournament_id);
            } else {
                window.tournamentController.loadTournaments();
            }
        } else {
            window.Toast.show(data.message, 'error');
        }
    },
    openTournament: (id) => {
        // Trik SPA: Ręcznie ustawiamy ładny URL z ID
        window.history.pushState({view: 'tournament', id: id}, '', `/tournament?id=${id}`);
        // Wywołujemy nawigację, podając 'false', żeby router nie nadpisał naszego URL-a z ID!
        window.router.navigate('tournament', false); 
    }
}