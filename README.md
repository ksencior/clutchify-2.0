# Clutchify

Clutchify to webowa platforma turniejowa tworzona z myślą o turniejach CS2, drużynach, społecznościach szkolnych i lokalnych organizatorach esportowych.

Projekt jest rozwijany jako aplikacja SPA z backendem PHP, bazą MySQL/MariaDB i komunikacją realtime przez WebSocket.

---

## Status projektu

Projekt jest w aktywnym rozwoju jako MVP.

Aktualnie aplikacja posiada:

- system kont użytkowników,
- profile graczy,
- ustawienia profilu,
- drużyny,
- znajomych,
- prywatny czat,
- powiadomienia,
- turnieje,
- zapisy drużyn do turniejów,
- activity feed,
- centrum admina,
- WebSocket server,
- konfigurację przez `.env`.

---

## Stack technologiczny

### Backend

- PHP
- PDO
- MySQL / MariaDB
- PHP Sessions
- Ratchet WebSocket
- Composer

### Frontend

- HTML
- CSS
- Vanilla JavaScript
- ES Modules
- SPA router

### Dev / Deployment

- Laragon lokalnie
- Apache na VPS
- `.env` config
- systemd dla WebSocket servera na produkcji

---

## Główne funkcje

### Użytkownicy

- Rejestracja i logowanie.
- Sesje użytkownika.
- CSRF protection.
- Publiczny profil gracza.
- Edycja profilu.
- Avatar jako URL.
- Bio.
- Rola w CS.
- Faceit level.
- Region.
- Szkoła / organizacja.
- Dostępność.

### Drużyny

- Tworzenie drużyn.
- Kapitan drużyny.
- Członkowie drużyny.
- Zaproszenia do drużyny.
- Rezerwa / substitute.
- Podstawowa integracja z profilem gracza.

### Znajomi i czat

- System znajomych.
- Zaproszenia do znajomych.
- Prywatne wiadomości.
- Drawer wiadomości.
- Nieprzeczytane wątki.
- WebSocket pod realtime statusy i komunikaty.

### Turnieje

- Tworzenie turniejów.
- Turnieje otwarte i zamknięte.
- Kod dołączenia do turnieju zamkniętego.
- Zapisy drużyn.
- Statusy zgłoszeń:
  - pending,
  - approved,
  - rejected,
  - left.
- Statusy turnieju:
  - registration_open,
  - registration_closed,
  - in_progress,
  - finished,
  - cancelled.
- Zamykanie i ponowne otwieranie zapisów.
- Panel administratora turnieju.
- Lista uczestników.

### Activity feed

- Centralny feed aktywności platformy.
- Logowanie zdarzeń takich jak:
  - aktualizacja profilu,
  - utworzenie drużyny,
  - utworzenie turnieju,
  - zapis drużyny do turnieju,
  - zatwierdzenie drużyny w turnieju.
- Publiczne i admin-only eventy.

### Centrum admina

- Osobny widok administratora.
- Backendowy guard administratora.
- Statystyki platformy.
- Najnowsi użytkownicy.
- Najnowsze drużyny.
- Najnowsze turnieje.
- Oczekujące zgłoszenia do turniejów.
- Ostatnia aktywność platformy.

---

## Wymagania

- PHP 8.1+
- Composer
- MySQL lub MariaDB
- Apache z `mod_rewrite`
- Node.js opcjonalnie do sprawdzania składni JS
- Laragon lokalnie albo klasyczny stack PHP/MySQL

---

## Instalacja lokalna

### 1. Sklonuj repozytorium

```bash
git clone https://github.com/ksencior/clutchify.git
cd clutchify
```
### 2. Zainstaluj zależności PHP

```bash
composer install
```

### 3. Przygotuj plik .env
Skopiuj przykład konfiguracji:
```bash
cp .env.example .env
```

Przykład:
```env
APP_NAME=Clutchify
APP_ENV=local
APP_DEBUG=true
APP_URL=http://clutchify.test

DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=clutchify
DB_USER=root
DB_PASS=
DB_CHARSET=utf8mb4

WEBSOCKET_HOST=0.0.0.0
WEBSOCKET_PORT=8080
WS_AUTH_SECRET=change_this_secret

DISCORD_CLIENT_ID=
DISCORD_CLIENT_SECRET=
DISCORD_REDIRECT_URL=http://clutchify.test/services/connect_discord.php

STEAM_API_KEY=
STEAM_RETURN_URL=http://clutchify.test/services/connect_steam.php
```

---
### Bezpieczeństwo

Projekt posiada podstawowe mechanizmy bezpieczeństwa:

- sesje PHP,
- CSRF protection dla akcji POST,
- walidację danych wejściowych,
- backendowe sprawdzanie uprawnień admina,
- tokeny autoryzacyjne dla WebSocket,
- konfigurację sekretów przez .env.

Do zrobienia przed produkcją:

- pełny audyt XSS,
- pełna walidacja wszystkich payloadów,
- rate limiting logowania,
- reset hasła,
- lepsze zarządzanie sesjami,
- upload avatarów z walidacją plików,
- logi bezpieczeństwa,
- twarde CSP headers.

---

### Roadmap

Najbliższe
- Bracket generator.
- Tabela meczów.
- Wybór zwycięzcy meczu.
- Automatyczne generowanie kolejnych rund.
- Panel organizatora turnieju.
- Lepsze akcje admina.
- Upload avatarów i logotypów.
- Publiczny landing page.
- 
Później
- Lobby meczowe.
- Ready check.
- Integracja z CS2 serverami.
- RCON.
- MatchZy integration.
- Automatyczne veto map.
- Wyniki meczów.
- Statystyki graczy.
- Demo parser.
- System organizacji / szkół.
- Plany subskrypcyjne dla organizatorów.

---

### Autor
@ksencior

### Licencja
Projekt prywatny / w trakcie rozwoju.

Licencja zostanie określona później.
