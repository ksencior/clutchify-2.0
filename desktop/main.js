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