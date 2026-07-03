export const AppState = {
    user: null,

    setUser(user) {
        this.user = user;
    },

    getUser() {
        return this.user;
    },

    isLoggedIn() {
        return this.user !== null;
    }
}