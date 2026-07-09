# Clutchify 2.0

Clutchify is a web-based esports platform built for CS2 tournaments, teams, school communities, and local tournament organizers. The project is currently developed as an MVP with a PHP backend, a MySQL/MariaDB database, a vanilla JavaScript SPA frontend, and real-time features powered by WebSockets.

## Features

- User registration, login, sessions, and CSRF-protected actions
- Player profiles with avatar URL, bio, CS role, FACEIT level, region, school/organization, and availability
- Public player profiles with vanity routes such as `/u/username`
- Team creation, captains, members, substitutes, invitations, join requests, and team logos
- Friends system with requests, statuses, and private messages
- Real-time notifications, chat updates, online statuses, and match lobby updates through WebSockets
- Tournament creation, open and closed tournaments, join codes, team registration, approval workflow, and participant lists
- Tournament brackets and match lobby flow with ready checks and match start handling
- Activity feed for user, team, tournament, and platform events
- Admin dashboard with platform statistics, newest users, newest teams, newest tournaments, pending tournament requests, and recent activity
- Optional CS2 practice server module using RCON, game server pool management, session passwords, and session limits
- Steam and Discord connection endpoints
- Environment-based configuration through `.env`
- Database migrations through a lightweight PHP migration runner

## Tech Stack

### Backend

- PHP 8.1+
- PDO
- MySQL or MariaDB
- PHP sessions
- Composer
- Ratchet WebSocket server
- RCON integration for CS2 practice servers

### Frontend

- HTML
- CSS
- Vanilla JavaScript
- ES modules
- SPA-style client-side routing

### Infrastructure

- Apache with `mod_rewrite`
- Laragon or a classic PHP/MySQL local stack
- Optional systemd service for the WebSocket server in production

## Requirements

- PHP 8.1 or newer
- Composer
- MySQL or MariaDB
- Apache with `mod_rewrite` enabled
- OpenSSL PHP extension
- A local virtual host such as `clutchify.test` or a configured production domain
- Optional: Node.js, only for JavaScript syntax checks or extra local tooling
- Optional: CS2 server with RCON enabled for the practice server module

## Installation

### 1. Clone the repository

```bash
git clone https://github.com/ksencior/clutchify-2.0.git
cd clutchify-2.0
```

### 2. Install PHP dependencies

```bash
composer install
```

### 3. Create the environment file

```bash
cp .env.example .env
```

Then update the values for your local environment.

```env
APP_NAME=Clutchify
APP_ENV=local
APP_DEBUG=true
APP_URL=http://clutchify.test
APP_HOST=clutchify.test
APP_SECRET_KEY=change_this_to_a_strong_random_secret

DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=v2_clutchify
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

PRACTICE_ENABLED=1
PRACTICE_SESSION_MINUTES=60
```

> Do not commit your real `.env` file. Keep credentials, API keys, RCON passwords, and app secrets private.

### 4. Create the database

Create a MySQL/MariaDB database matching `DB_NAME` from your `.env` file.

```sql
CREATE DATABASE v2_clutchify
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
```

### 5. Run migrations

```bash
php scripts/migrate.php
```

Migrations are stored in:

```text
database/migrations/
```

The migration runner stores executed migrations in the `schema_migrations` table.

### 6. Configure Apache

Point your Apache virtual host document root to the project directory and make sure `mod_rewrite` is enabled.

Example local host:

```text
http://clutchify.test
```

The included `.htaccess` handles SPA fallback routes such as `/dashboard`, `/profile`, `/tournaments`, and public profile routes like `/u/username`.

### 7. Start the WebSocket server

In a separate terminal, run:

```bash
php server.php
```

The server uses these environment variables:

```env
WEBSOCKET_HOST=0.0.0.0
WEBSOCKET_PORT=8080
WS_AUTH_SECRET=change_this_secret
```

In production, run the WebSocket server as a background process, for example with systemd, Supervisor, or another process manager.

## Practice Server Setup

The practice module is optional. Enable it with:

```env
PRACTICE_ENABLED=1
```

Basic practice server configuration:

```env
PRACTICE_SERVER_NAME="Clutchify Practice"
PRACTICE_SERVER_HOST=127.0.0.1
PRACTICE_SERVER_PORT=27015
PRACTICE_SERVER_PUBLIC=your-domain.com:27015
PRACTICE_SERVER_PASSWORD=

PRACTICE_RCON_HOST=127.0.0.1
PRACTICE_RCON_PORT=27015
PRACTICE_RCON_TIMEOUT=5
PRACTICE_SESSION_MINUTES=60
```

RCON passwords can be stored through environment variables or encrypted values in the database. To encrypt a secret, set `APP_SECRET_KEY` first and run:

```bash
php scripts/encrypt-secret.php "your-rcon-password"
```

## Project Structure

```text
.
├── api.php                         # Main API entrypoint
├── app.js                          # SPA bootstrap and global client helpers
├── bootstrap/api_bootstrap.php     # API bootstrap, sessions, CSRF, auth helpers
├── classes/                        # Auth and user-related PHP classes
├── config/env.php                  # .env loader
├── controllers/                    # Frontend JavaScript controllers
├── database/
│   ├── migrations/                 # PHP database migrations
│   └── seeds/                      # Development seed data
├── helpers/                        # WebSocket auth and secret encryption helpers
├── public/img/                     # Public images and logos
├── routes/                         # API action modules
├── scripts/                        # CLI scripts, migrations, secret encryption
├── services/                       # Steam and Discord integration endpoints
├── views/                          # HTML views loaded by the SPA
├── server.php                      # Ratchet WebSocket server
├── state.js                        # Frontend app state
└── style.css                       # Main styles
```

## API Modules

The backend uses `api.php?action=...` and splits actions into route files:

- `routes/auth.php` — registration, login, logout, current user, password changes
- `routes/profile.php` — profile reads and profile settings updates
- `routes/players.php` — player directory
- `routes/teams.php` — teams, invitations, join requests, roster management
- `routes/friends.php` — friend search, requests, responses, friend list
- `routes/chat.php` — private messages and unread threads
- `routes/notifications.php` — notifications and response handling
- `routes/tournaments.php` — tournaments, signups, review workflow, brackets
- `routes/matches.php` — match lobby, ready checks, match start flow
- `routes/practice.php` — CS2 practice sessions and RCON actions
- `routes/admin.php` — admin overview and platform statistics
- `routes/activity.php` — activity feed
- `routes/setup.php` — first-run configuration checks

## Security Notes

The project already includes several baseline security mechanisms:

- HTTP-only PHP session cookies
- `SameSite=Lax` session configuration
- CSRF tokens for state-changing actions
- POST-only enforcement for write actions
- Backend admin guards
- WebSocket authentication tokens
- Environment-based secret configuration
- OpenSSL-based encryption helper for stored secrets
- Basic input validation for usernames and selected payloads

Before using the project in production, consider adding:

- Full XSS audit
- Rate limiting for login and sensitive endpoints
- Password reset flow
- Stronger session management and session rotation
- Content Security Policy headers
- Centralized application logs
- File upload validation if avatar uploads are added
- Production-grade error handling with `APP_DEBUG=false`

## Development Notes

There is no frontend build step at the moment. The frontend is written as plain HTML, CSS, and JavaScript modules.

Useful commands:

```bash
composer install
php scripts/migrate.php
php server.php
```

If you change the database schema, add a new migration file in `database/migrations/` and run:

```bash
php scripts/migrate.php
```

## Deployment Notes

For production deployment:

1. Set `APP_ENV=production` and `APP_DEBUG=false`.
2. Use a strong `APP_SECRET_KEY` and `WS_AUTH_SECRET`.
3. Configure the correct `APP_URL`, `APP_HOST`, and OAuth redirect URLs.
4. Run `composer install --no-dev --optimize-autoloader`.
5. Run database migrations.
6. Configure Apache with `mod_rewrite` and HTTPS.
7. Run `server.php` as a managed background process.
8. Keep `.env` outside version control.

## Status

Clutchify 2.0 is an active MVP. Core platform features are implemented, but the project should still be reviewed and hardened before production use.

## License

No license has been specified yet. Add a `LICENSE` file before distributing or publishing the project as open source.
