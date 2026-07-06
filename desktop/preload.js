const { contextBridge, ipcRenderer } = require('electron');

contextBridge.exposeInMainWorld('ClutchifyDesktop', {
    isDesktop: true,
    minimize: () => ipcRenderer.send('window:minimize'),
    toggleMaximize: () => ipcRenderer.send('window:toggle-maximize'),
    closeToTray: () => ipcRenderer.send('window:close-to-tray'),
    navigate: (path) => ipcRenderer.send('app:navigate', path)
});

function injectDesktopTitlebar() {
    if (document.getElementById('clutchify-desktop-titlebar')) {
        return;
    }

    document.documentElement.classList.add('clutchify-desktop');

    const style = document.createElement('style');

    style.textContent = `
        html.clutchify-desktop body {
            padding-top: 42px !important;
        }

        html.clutchify-desktop .app-layout {
            height: calc(100dvh - 42px) !important;
        }

        html.clutchify-desktop .sidebar {
            top: 42px !important;
            height: calc(100dvh - 42px) !important;
        }

        html.clutchify-desktop main {
            height: calc(100dvh - 42px) !important;
        }

        #clutchify-desktop-titlebar {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            height: 42px;
            z-index: 999999;
            display: flex;
            align-items: center;
            justify-content: space-between;
            background:
                linear-gradient(90deg, rgba(255,0,43,0.12), transparent 35%),
                rgba(10, 10, 10, 0.98);
            border-bottom: 1px solid rgba(255,255,255,0.08);
            color: #fff;
            user-select: none;
            -webkit-app-region: drag;
            font-family: Inter, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }

        .clutchify-titlebar-left {
            display: flex;
            align-items: center;
            gap: 10px;
            padding-left: 14px;
            min-width: 0;
        }

        .clutchify-titlebar-dot {
            width: 10px;
            height: 10px;
            border-radius: 999px;
            background: #ff002b;
            box-shadow: 0 0 16px rgba(255,0,43,0.65);
            flex-shrink: 0;
        }

        .clutchify-titlebar-brand {
            display: flex;
            flex-direction: column;
            line-height: 1.05;
            min-width: 0;
        }

        .clutchify-titlebar-brand strong {
            font-size: 12px;
            letter-spacing: 1px;
            text-transform: uppercase;
            white-space: nowrap;
        }

        .clutchify-titlebar-brand span {
            font-size: 10px;
            color: rgba(255,255,255,0.45);
            white-space: nowrap;
        }

        .clutchify-titlebar-center {
            display: flex;
            align-items: center;
            gap: 6px;
            -webkit-app-region: no-drag;
        }

        .clutchify-titlebar-link {
            height: 28px;
            padding: 0 11px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 999px;
            border: 1px solid rgba(255,255,255,0.07);
            background: rgba(255,255,255,0.035);
            color: rgba(255,255,255,0.78);
            font-size: 10px;
            font-weight: 900;
            letter-spacing: .8px;
            text-transform: uppercase;
            cursor: pointer;
            transition: background .16s ease, color .16s ease, border-color .16s ease;
        }

        .clutchify-titlebar-link:hover {
            background: rgba(255,0,43,0.12);
            border-color: rgba(255,0,43,0.24);
            color: #fff;
        }

        .clutchify-titlebar-controls {
            display: flex;
            height: 100%;
            -webkit-app-region: no-drag;
        }

        .clutchify-window-btn {
            width: 48px;
            height: 42px;
            border: 0;
            outline: 0;
            background: transparent;
            color: rgba(255,255,255,0.75);
            font-size: 14px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: background .15s ease, color .15s ease;
        }

        .clutchify-window-btn:hover {
            background: rgba(255,255,255,0.08);
            color: #fff;
        }

        .clutchify-window-btn.close:hover {
            background: #e81123;
            color: #fff;
        }

        @media (max-width: 820px) {
            .clutchify-titlebar-center {
                display: none;
            }

            html.clutchify-desktop .sidebar {
                top: auto !important;
                height: calc(var(--mobile-nav-height) + env(safe-area-inset-bottom)) !important;
            }

            html.clutchify-desktop main {
                height: calc(100dvh - 42px) !important;
            }
        }
    `;

    document.head.appendChild(style);

    const titlebar = document.createElement('div');
    titlebar.id = 'clutchify-desktop-titlebar';

    titlebar.innerHTML = `
        <div class="clutchify-titlebar-left">
            <span class="clutchify-titlebar-dot"></span>
            <div class="clutchify-titlebar-brand">
                <strong>Clutchify</strong>
                <span>Desktop Client</span>
            </div>
        </div>

        <div class="clutchify-titlebar-controls">
            <button class="clutchify-window-btn" data-window-action="minimize" title="Minimalizuj">—</button>
            <button class="clutchify-window-btn" data-window-action="maximize" title="Maksymalizuj">□</button>
            <button class="clutchify-window-btn close" data-window-action="close" title="Zamknij do traya">×</button>
        </div>
    `;

    document.body.prepend(titlebar);

    titlebar.querySelector('[data-window-action="minimize"]')?.addEventListener('click', () => {
        ipcRenderer.send('window:minimize');
    });

    titlebar.querySelector('[data-window-action="maximize"]')?.addEventListener('click', () => {
        ipcRenderer.send('window:toggle-maximize');
    });

    titlebar.querySelector('[data-window-action="close"]')?.addEventListener('click', () => {
        ipcRenderer.send('window:close-to-tray');
    });

    titlebar.querySelectorAll('[data-desktop-route]').forEach(button => {
        button.addEventListener('click', () => {
            ipcRenderer.send('app:navigate', button.dataset.desktopRoute);
        });
    });
}

window.addEventListener('DOMContentLoaded', injectDesktopTitlebar);