import { AppState } from "../state.js";


export const dashboardController = {

    init: () => {
        window.dashboardController = dashboardController;
        window.authController.checkSession();
        dashboardController.renderPlayer();
    },
    renderPlayer: () => {
        const usernameEl = document.getElementById('dashboard-username');
        const avatarEl = document.getElementById('dashboard-avatar');
        const teamEl = document.getElementById('dashboard-team');

        const welcomeEl = document.getElementById('dashboard-welcome-text');

        welcomeEl.innerText = `Witaj, ${AppState.isLoggedIn() ? AppState.getUser().username : "..."}`

        usernameEl.innerText = AppState.isLoggedIn() ? AppState.getUser().username : 'Nowy_Gracz';
        teamEl.innerText = AppState.getUser().player.team_id !== null? AppState.getUser().player.team_name : "Brak drużyny";
        avatarEl.src = `https://ui-avatars.com/api/?name=${AppState.isLoggedIn() ? AppState.getUser().username : 'P'}&background=121212&color=ff002b`;

        if (!localStorage.getItem("startPopoutSeen")) {
            window.Popout.create("Witaj na Clutchify.gg!", `Hej, ${AppState.getUser().username}! Miej na uwadze, że aplikacja dalej jest w wczesnej fazie alpha. Możesz przyczynić się do ulepszania jej,
            zgłaszając błędy na githubie! Miłego korzystania z Clutchify.gg :)`, () => {
                localStorage.setItem('startPopoutSeen', true);
            });
        }
    }
}