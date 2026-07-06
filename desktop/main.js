const { app, BrowserWindow, shell } = require('electron');

const APP_URL = process.env.CLUTCHIFY_URL || 'https://clutchify.0bg.pl';

function createWindow() {
    const win = new BrowserWindow({
        width: 1280,
        height: 820,
        minWidth: 1050,
        minHeight: 700,
        title: 'Clutchify',
        autoHideMenuBar: true,
        backgroundColor: '#0d0d0d',
        webPreferences: {
            nodeIntegration: false,
            contextIsolation: true,
            sandbox: true
        }
    });

    win.loadURL(APP_URL);

    win.webContents.setWindowOpenHandler(({ url }) => {
        const appOrigin = new URL(APP_URL).origin;

        if (url.startsWith(appOrigin)) {
            return { action: 'allow' };
        }

        shell.openExternal(url);
        return { action: 'deny' };
    });
}

app.whenReady().then(() => {
    createWindow();

    app.on('activate', () => {
        if (BrowserWindow.getAllWindows().length === 0) {
            createWindow();
        }
    });
});

app.on('window-all-closed', () => {
    if (process.platform !== 'darwin') {
        app.quit();
    }
});