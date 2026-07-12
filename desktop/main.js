const path = require('path');
const {
    app,
    BrowserWindow,
    Menu,
    Tray,
    shell,
    nativeImage,
    session,
    ipcMain
} = require('electron');

const APP_URL = process.env.CLUTCHIFY_URL || 'https://clutchify.0bg.pl';
const APP_NAME = 'Clutchify';
const DESKTOP_PARTITION = 'persist:clutchify';

let mainWindow = null;
let tray = null;
let isQuitting = false;
let startMinimized = false;

const iconPath = path.join(__dirname, 'assets', 'icon.ico');

function getAppOrigin() {
    return new URL(APP_URL).origin;
}

function validateServerAddress(address) {
    const value = String(address || '').trim();

    const match = value.match(/^([a-zA-Z0-9.-]+):(\d{1,5})$/);

    if (!match) {
        throw new Error('Nieprawidłowy adres serwera.');
    }

    const host = match[1];
    const port = Number(match[2]);

    if (host.length < 3 || host.length > 180 || port < 1 || port > 65535) {
        throw new Error('Nieprawidłowy adres serwera.');
    }

    return `${host}:${port}`;
}

function validateServerPassword(password) {
    const value = String(password || '').trim();

    if (value === '') {
        return '';
    }

    if (!/^[A-Za-z0-9_-]{4,32}$/.test(value)) {
        throw new Error('Nieprawidłowe hasło serwera.');
    }

    return value;
}

function buildCS2LaunchUri(payload) {
    const address = validateServerAddress(payload?.address);
    const password = validateServerPassword(payload?.password);

    /**
     * CS2 appid = 730.
     * Nie używamy czystego steam://connect, tylko start gry z launch args.
     */
    let uri = `steam://rungame/730/76561202255233023/+connect%20${address}`;

    if (password) {
        uri += `%20+password%20${password}`;
    }

    return uri;
}

function isTrustedAppUrl(rawUrl) {
    try {
        const url = new URL(rawUrl);
        return url.origin === getAppOrigin();
    } catch (_) {
        return false;
    }
}

function getDesktopSession() {
    return session.fromPartition(DESKTOP_PARTITION);
}

function getAppUrl(pathname = '/') {
    const url = new URL(pathname, APP_URL);
    return url.toString();
}

function setupDesktopSession() {
    const desktopSession = getDesktopSession();

    desktopSession.webRequest.onBeforeSendHeaders((details, callback) => {
        details.requestHeaders['X-Clutchify-Desktop'] = '1';

        callback({
            requestHeaders: details.requestHeaders
        });
    });

    desktopSession.setPermissionRequestHandler((webContents, permission, callback) => {
        const url = webContents.getURL();
        const appOrigin = getAppOrigin();

        const allowedPermissions = new Set([
            'notifications'
        ]);

        callback(url.startsWith(appOrigin) && allowedPermissions.has(permission));
    });
}

function createMainWindow() {
    const icon = nativeImage.createFromPath(iconPath);

    mainWindow = new BrowserWindow({
        width: 1280,
        height: 820,
        minWidth: 1050,
        minHeight: 700,
        title: APP_NAME,
        icon,
        frame: false,
        autoHideMenuBar: true,
        backgroundColor: '#0d0d0d',
        show: false,
        webPreferences: {
            nodeIntegration: false,
            contextIsolation: true,
            sandbox: true,
            partition: DESKTOP_PARTITION,
            preload: path.join(__dirname, 'preload.js')
        }
    });

    mainWindow.loadURL(APP_URL);

    mainWindow.once('ready-to-show', () => {
        if (!startMinimized) {
            mainWindow.show();
        }
    });

    mainWindow.webContents.setWindowOpenHandler(({ url }) => {
        const appOrigin = getAppOrigin();

        if (url.startsWith(appOrigin)) {
            return { action: 'allow' };
        }

        shell.openExternal(url);
        return { action: 'deny' };
    });

    mainWindow.webContents.on('will-navigate', (event, url) => {
        const appOrigin = getAppOrigin();

        if (!url.startsWith(appOrigin)) {
            event.preventDefault();
            shell.openExternal(url);
        }
    });

    mainWindow.on('close', (event) => {
        if (isQuitting) {
            return;
        }

        event.preventDefault();
        mainWindow.hide();
    });

    mainWindow.on('closed', () => {
        mainWindow = null;
    });
}

function showMainWindow() {
    if (!mainWindow) {
        createMainWindow();
        return;
    }

    if (mainWindow.isMinimized()) {
        mainWindow.restore();
    }

    mainWindow.show();
    mainWindow.focus();
}

function navigateApp(pathname) {
    showMainWindow();

    if (!pathname || typeof pathname !== 'string') {
        return;
    }

    if (!pathname.startsWith('/')) {
        return;
    }

    mainWindow?.loadURL(getAppUrl(pathname));
}

function buildTrayMenu() {
    const openAtLogin = app.getLoginItemSettings().openAtLogin;
    const windowVisible = !!mainWindow && mainWindow.isVisible();

    return Menu.buildFromTemplate([
        {
            label: 'Clutchify Desktop',
            enabled: false
        },
        {
            label: windowVisible ? 'Ukryj do traya' : 'Otwórz Clutchify',
            click: () => {
                if (mainWindow?.isVisible()) {
                    mainWindow.hide();
                } else {
                    showMainWindow();
                }
            }
        },
        { type: 'separator' },
        {
            label: '🎮 Graj',
            click: () => navigateApp('/play')
        },
        {
            label: '🏆 Turnieje',
            click: () => navigateApp('/tournaments')
        },
        {
            label: '👤 Mój profil',
            click: () => navigateApp('/profile')
        },
        {
            label: '🔔 Powiadomienia',
            click: () => {
                showMainWindow();
            }
        },
        { type: 'separator' },
        {
            label: '🔄 Odśwież',
            accelerator: 'CmdOrCtrl+R',
            click: () => mainWindow?.reload()
        },
        {
            label: '🛠 DevTools',
            accelerator: 'CmdOrCtrl+Shift+I',
            click: () => mainWindow?.webContents.toggleDevTools()
        },
        { type: 'separator' },
        {
            label: 'Uruchamiaj z systemem',
            type: 'checkbox',
            checked: openAtLogin,
            click: (menuItem) => {
                app.setLoginItemSettings({
                    openAtLogin: menuItem.checked,
                    openAsHidden: true
                });

                updateTrayMenu();
            }
        },
        {
            label: 'Wyczyść cache',
            click: async () => {
                await getDesktopSession().clearCache();
                mainWindow?.reload();
            }
        },
        {
            label: 'Wyloguj lokalnie / wyczyść cookies',
            click: async () => {
                await getDesktopSession().clearStorageData({
                    storages: [
                        'cookies',
                        'localstorage',
                        'indexdb',
                        'cachestorage'
                    ]
                });

                mainWindow?.loadURL(APP_URL);
                showMainWindow();
            }
        },
        { type: 'separator' },
        {
            label: 'Zamknij Clutchify',
            click: () => {
                isQuitting = true;
                app.quit();
            }
        }
    ]);
}

function createTray() {
    const icon = nativeImage.createFromPath(iconPath);

    tray = new Tray(icon);
    tray.setToolTip(APP_NAME);

    updateTrayMenu();

    tray.on('click', () => {
        showMainWindow();
        updateTrayMenu();
    });

    tray.on('right-click', () => {
        updateTrayMenu();
        tray.popUpContextMenu(buildTrayMenu());
    });

    tray.on('double-click', () => {
        showMainWindow();
        updateTrayMenu();
    });
}

function updateTrayMenu() {
    if (!tray) return;

    tray.setContextMenu(buildTrayMenu());
}

function setupIpc() {
    ipcMain.on('window:minimize', () => {
        mainWindow?.minimize();
    });

    ipcMain.on('window:toggle-maximize', () => {
        if (!mainWindow) return;

        if (mainWindow.isMaximized()) {
            mainWindow.unmaximize();
        } else {
            mainWindow.maximize();
        }
    });

    ipcMain.on('window:close-to-tray', () => {
        mainWindow?.hide();
        updateTrayMenu();
    });

    ipcMain.on('app:navigate', (_event, pathname) => {
        navigateApp(pathname);
    });
}

function setupAppMenu() {
    const menu = Menu.buildFromTemplate([
        {
            label: APP_NAME,
            submenu: [
                {
                    label: 'Otwórz',
                    click: () => showMainWindow()
                },
                {
                    label: 'Odśwież',
                    accelerator: 'CmdOrCtrl+R',
                    click: () => mainWindow?.reload()
                },
                {
                    label: 'DevTools',
                    accelerator: 'CmdOrCtrl+Shift+I',
                    click: () => mainWindow?.webContents.toggleDevTools()
                },
                { type: 'separator' },
                {
                    label: 'Zamknij',
                    click: () => {
                        isQuitting = true;
                        app.quit();
                    }
                }
            ]
        }
    ]);

    Menu.setApplicationMenu(menu);
}

const gotLock = app.requestSingleInstanceLock();

if (!gotLock) {
    app.quit();
} else {
    app.setAppUserModelId('gg.clutchify.desktop');

    app.on('second-instance', () => {
        showMainWindow();
    });

    app.whenReady().then(() => {
        startMinimized = app.getLoginItemSettings().wasOpenedAsHidden || process.argv.includes('--hidden');

        setupDesktopSession();
        setupIpc();
        setupAppMenu();
        createTray();
        createMainWindow();

        app.on('activate', () => {
            showMainWindow();
        });
    });
}

app.on('before-quit', () => {
    isQuitting = true;
});

app.on('window-all-closed', () => {
    // Trzymamy aplikację w trayu.
    // Pełne wyjście tylko przez "Zamknij Clutchify".
});

ipcMain.handle('steam:connect', async (event, payload) => {
    const senderUrl = event.senderFrame?.url || event.sender.getURL();

    if (!isTrustedAppUrl(senderUrl)) {
        return {
            success: false,
            message: 'Nieautoryzowane źródło połączenia.'
        };
    }

    try {
        const steamUri = buildCS2LaunchUri(payload);

        console.log('[Clutchify] Opening Steam URI:', steamUri);

        await shell.openExternal(steamUri);

        return {
            success: true
        };
    } catch (error) {
        console.error('[Clutchify] steam:connect-cs2 failed:', error);

        return {
            success: false,
            message: error.message || 'Nie udało się uruchomić CS2.'
        };
    }
});