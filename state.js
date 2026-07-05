export const AppState = {
    user: null,
    csrfToken: null,
    wsToken: null,
    wsPort: null,

    setUser(user) {
        this.user = user;
    },

    getUser() {
        return this.user;
    },

    isLoggedIn() {
        return this.user !== null;
    },

    setCsrfToken(token) {
        this.csrfToken = token;
    },

    getCsrfToken() {
        return this.csrfToken;
    },

    setWsToken(token) {
        this.wsToken = token;
    },

    getWsToken() {
        return this.wsToken;
    },

    setWsPort(port) {
        this.wsPort = port;
    },

    getWsPort() {
        return this.wsPort;
    },

    clear() {
        this.user = null;
        this.csrfToken = null;
        this.wsToken = null;
        this.wsPort = null;
    }
};