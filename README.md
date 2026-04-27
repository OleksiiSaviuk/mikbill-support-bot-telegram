

# MB Support Bot

> 🇺🇦 [Українська версія документації](README.uk.md)

> **Forked from** [mikbill/support-bot-telegram](https://github.com/mikbill/support-bot-telegram) — this is a customized version with extended functionality.

A Telegram bot designed to help ISP support operators quickly look up subscriber information without logging into the admin panel.

### Features

- Search subscriber by login / contract / UID / phone
- View subscriber info card (balance, tariff, status, IP, MAC, etc.)
- View payment history
- View session history
- View service list
- Open subscriber's personal cabinet
- Wildcore integration: ONU/ONT diagnostics and access port info
- One-tap Wildcore refresh button directly in the subscriber card
- Connection type detection: ONU / switch port / unknown
- International phone number formatting
- Multi-language UI: 🇺🇦 Ukrainian, 🇬🇧 English

### Changelog

#### Upstream (mikbill/support-bot-telegram)

**23.04.2022**
- Added bot localization: uk, en, ru
- Currency is taken from billing settings

**03.05.2022**
- Added subscriber kick button
- Added change history
- Added authorization history
- Added ticket history
- Added search by phone number

#### Custom fork (OleksiiSaviuk)

**25.04.2026**
- Wildcore integration: ONU/ONT diagnostics and access port detection
- One-tap Wildcore refresh button in the subscriber card (preserves menu and spoiler)
- ONU block: status icon, last down reason, up/down events, online duration
- Connection type detection: ONU / switch port / unknown
- International phone number formatting with configurable default country code
- Timezone support via `APP_TIMEZONE` env variable (applied to PHP and Docker containers)
- Online duration display for subscriber card and ONU block
- Removed Local IP field from subscriber card
- Localization expanded: uk / en (ru kept for compatibility)
- Removed Russian-language code comments
- Docker Compose setup with `app` + `mysql` services


### Requirements

- PHP >= 8.1
- Composer 2.x


### 1. Installation

```shell script
cd /var/www/
git clone https://github.com/OleksiiSaviuk/mikbill-support-bot-telegram.git
cd support-bot-telegram

composer install

mkdir -p /var/www/support-bot-telegram/storage/{sessions,views,cache}
sudo chown -R www-data:www-data /var/www/support-bot-telegram
sudo chmod -R 775 /var/www/support-bot-telegram/storage/
```

### 2. Nginx

Point the document root to `/var/www/support-bot-telegram/public`.  
It is recommended to use a dedicated subdomain and set it as `APP_URL`.  
A valid TLS certificate is required for the Telegram webhook.

```nginx
location ~ /\.git {
    deny all;
}

location / {
    root   /var/www/support-bot-telegram/public;
    index  index.php;
    try_files $uri $uri/ /index.php?$args;
}

location ~ \.php$ {
    include /etc/nginx/fastcgi_params;
    fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
    fastcgi_index index.php;
    fastcgi_param SCRIPT_FILENAME /var/www/support-bot-telegram/public$fastcgi_script_name;
}
```

### 2.1 Apache

Point the document root to `/var/www/support-bot-telegram/public`.

Example `.htaccess`:

```apache
<IfModule mod_rewrite.c>
<IfModule mod_negotiation.c>
    Options -MultiViews
</IfModule>

RewriteEngine On

RewriteCond %{REQUEST_FILENAME} -d [OR]
RewriteCond %{REQUEST_FILENAME} -f
RewriteRule ^ ^$1 [N]

RewriteCond %{REQUEST_URI} (\.\w+$) [NC]
RewriteRule ^(.*)$ public/$1

RewriteCond %{REQUEST_FILENAME} !-d
RewriteCond %{REQUEST_FILENAME} !-f
RewriteRule (.*) index.php
DirectoryIndex /public/index.php
</IfModule>
```

### 3. Configuration

Copy `.env.example` to `.env` and fill in the required values:

```shell script
APP_URL=https://your-domain.tld
APP_TIMEZONE=Europe/Kiev

TELEGRAM_BOT_TOKEN="11111:xxxxxxxxxxxx"
TELEGRAM_BOT_NAME="name_bot"
# JSON array of Telegram user IDs allowed to access the bot
TELEGRAM_BOT_ALLOWED_ID="[1234345, 4789456]"
# Number of search results per page (default: 5)
TELEGRAM_SEARCH_PER_PAGE=5
# Enable auto-search by phone from /start payload like phone_380971234567
TELEGRAM_ENABLE_START_PHONE_SEARCH=false
# How many days to keep message history (auto-cleanup)
TELEGRAM_HISTORY_RETENTION_DAYS=7
# Max number of message_ids to track per chat
TELEGRAM_HISTORY_TRACK_LIMIT=5000
# Minimum interval between auto-cleanup runs (seconds)
TELEGRAM_HISTORY_PURGE_INTERVAL_SECONDS=300
# How many old messages to delete per cleanup run
TELEGRAM_HISTORY_DELETE_BATCH_SIZE=25
# Optional default country code for local phone numbers (digits only, no +)
# Examples: 38 (UA), 48 (PL), 1 (US/CA)
TELEGRAM_DEFAULT_PHONE_COUNTRY_CODE=

MIKBILL_CABINET_HOST="https://stat.your-domain.tld"
MIKBILL_HOST="https://admin.your-domain.tld"
MIKBILL_LOGIN=admin
MIKBILL_PASSWORD=admin

# Wildcore integration
WILDCORE_ENABLED=false
WILDCORE_URL="https://wildcore.your-domain.tld"
WILDCORE_API_KEY="your_wildcore_api_key"
```

### 3.1 Application key

```shell script
php artisan key:generate
```

### 3.2 Wildcore Integration (ONU/ONT)

When `WILDCORE_ENABLED=true`, an additional diagnostics block is shown in the subscriber card:

- **ONU/ONT**: status, RX signal, last up/down events, last down reason, online duration, LAN/MAC, location, summary
- **Switch port**: port status, type, LAN/MAC, location, summary
- **Unknown**: technical block for the found connection

The `🔄 Wildcore refresh` button fetches live data directly from the device.

### 3.3 Phone number format

Phone numbers in the subscriber card are displayed in international format with `+`:

- `+380...` — kept as-is
- `00380...` — converted to `+380...`
- Local numbers without a country code can be normalized via `TELEGRAM_DEFAULT_PHONE_COUNTRY_CODE`

Example:

```shell script
TELEGRAM_DEFAULT_PHONE_COUNTRY_CODE=38
```

If the variable is not set, ambiguous local numbers are displayed unchanged.

### 3.4 Timezone

Timestamps in the subscriber card (ONU events, up/down times) are displayed in the timezone set by `APP_TIMEZONE`.

Default: `Europe/Kiev`.

```shell script
APP_TIMEZONE=Europe/Kiev
```

This variable is used for both PHP/Laravel and the system time of Docker containers (via `TZ` in `docker-compose.yml`).  
Valid values: [PHP timezones](https://www.php.net/manual/en/timezones.php).

### 3.5 Bot language

Supported locales:

- `uk` — Ukrainian
- `en` — English

To change the bot language, set in `.env`:

```shell script
APP_LOCALE=en
```

Locale files are located at:

```shell script
resources/lang/uk.json
resources/lang/en.json
```

### 3.6 MikBill tickets in Telegram (MySQL direct)

The bot supports working with MikBill tickets directly via MySQL:

- Show the latest tickets in Telegram (`🎫 Tickets` button in main menu)
- Open a ticket and view full message history
- Send operator replies to `tickets_messages`
- Change ticket status (`opened`, `in_work`, `performed`, `closed`)
- Open subscriber info by `useruid` from ticket

Access is restricted:

- Feature must be enabled in env
- Telegram user must be mapped to MikBill operator id in `MIKBILL_TICKETS_OPERATORS`

Add to `.env`:

```shell script
# Enable/disable MikBill tickets UI in bot
MIKBILL_TICKETS_ENABLED=true

# Number of tickets shown in list
MIKBILL_TICKETS_LIMIT=10

# Mapping: MikBill operator_id : Telegram user_id
# Example with one operator:
MIKBILL_TICKETS_OPERATORS="[1:111111]"
# Example with multiple operators:
# MIKBILL_TICKETS_OPERATORS="[1:111111,2:222222]"

# Max operator reply length for one message
MIKBILL_TICKETS_MAX_MESSAGE_LENGTH=500

# Separate MySQL connection for ticket tables
MIKBILL_TICKETS_DB_HOST=127.0.0.1
MIKBILL_TICKETS_DB_PORT=3306
MIKBILL_TICKETS_DB_NAME=mikbill
MIKBILL_TICKETS_DB_USER=tg_ticket_bot
MIKBILL_TICKETS_DB_PASSWORD=strong_password
```

### 3.7 Minimal MySQL permissions for ticket feature

The ticket workflow needs only these operations:

- `SELECT` from:
    - `tickets_tickets`
    - `tickets_messages`
    - `tickets_status_types`
    - `tickets_categories_list`
    - `tickets_priorities_types`
- `INSERT` into `tickets_messages`
- `UPDATE` on `tickets_tickets`

Example (MySQL/MariaDB):

```sql
CREATE USER 'tg_ticket_bot'@'127.0.0.1' IDENTIFIED BY 'strong_password';

GRANT SELECT ON mikbill.tickets_tickets TO 'tg_ticket_bot'@'127.0.0.1';
GRANT SELECT ON mikbill.tickets_messages TO 'tg_ticket_bot'@'127.0.0.1';
GRANT SELECT ON mikbill.tickets_status_types TO 'tg_ticket_bot'@'127.0.0.1';
GRANT SELECT ON mikbill.tickets_categories_list TO 'tg_ticket_bot'@'127.0.0.1';
GRANT SELECT ON mikbill.tickets_priorities_types TO 'tg_ticket_bot'@'127.0.0.1';

GRANT INSERT ON mikbill.tickets_messages TO 'tg_ticket_bot'@'127.0.0.1';
GRANT UPDATE ON mikbill.tickets_tickets TO 'tg_ticket_bot'@'127.0.0.1';

FLUSH PRIVILEGES;
```

If your DB host differs, replace `'127.0.0.1'` with the required host (for example `'%'` only if really needed).

### 4. Webhook

Set webhook:

```shell script
php artisan telebot:webhook --setup
```

Remove webhook:

```shell script
php artisan telebot:webhook --remove
```

### 5. Long polling

Remove the webhook first if it is set, then run:

```shell script
php artisan telebot:polling --all
```

### 6. Message history auto-cleanup

- `TELEGRAM_HISTORY_RETENTION_DAYS` — how many days to keep messages. Default: `7`.
- `TELEGRAM_HISTORY_TRACK_LIMIT` — max message_ids cached per chat. Default: `5000`.
- `TELEGRAM_HISTORY_PURGE_INTERVAL_SECONDS` — minimum interval between cleanup runs. Default: `300`.
- `TELEGRAM_HISTORY_DELETE_BATCH_SIZE` — messages deleted per cleanup run. Default: `25`.

Recommended values for better performance:

```shell script
TELEGRAM_HISTORY_RETENTION_DAYS=7
TELEGRAM_HISTORY_TRACK_LIMIT=5000
TELEGRAM_HISTORY_PURGE_INTERVAL_SECONDS=900
TELEGRAM_HISTORY_DELETE_BATCH_SIZE=10
```

### 7. Docker Compose

The project includes `docker-compose.yml` with two services:

- `app` — Apache + PHP 8.1
- `mysql` — MySQL 8

No local Nginx in Compose. If you have an external reverse proxy (Nginx / Traefik / Caddy), proxy it to:

- `8088` → HTTP Laravel

**Steps:**

1. Prepare the env file:

```shell script
cp .env.example .env
```

2. For production, set in `.env`:

```shell script
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain.tld
APP_TIMEZONE=Europe/Kiev

DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=your_db_name
# DB_USERNAME must not be root — the MySQL container creates this user automatically
DB_USERNAME=your_db_user
DB_PASSWORD=strong_password
DB_ROOT_PASSWORD=strong_root_password

TELEGRAM_BOT_TOKEN="11111:xxxxxxxxxxxx"
TELEGRAM_BOT_NAME="name_bot"
TELEGRAM_BOT_ALLOWED_ID="[1234345, 4789456]"
TELEGRAM_SEARCH_PER_PAGE=5
TELEGRAM_ENABLE_START_PHONE_SEARCH=false
TELEGRAM_HISTORY_RETENTION_DAYS=7
TELEGRAM_HISTORY_TRACK_LIMIT=5000
TELEGRAM_HISTORY_PURGE_INTERVAL_SECONDS=300
TELEGRAM_HISTORY_DELETE_BATCH_SIZE=25
TELEGRAM_DEFAULT_PHONE_COUNTRY_CODE=

MIKBILL_HOST="https://admin.your-domain.tld"
MIKBILL_CABINET_HOST="https://stat.your-domain.tld"
MIKBILL_LOGIN=admin
MIKBILL_PASSWORD=admin

WILDCORE_ENABLED=false
WILDCORE_URL="https://wildcore.your-domain.tld"
WILDCORE_API_KEY="your_wildcore_api_key"
```

3. Start containers:

```shell script
docker compose up -d --build
```

4. Initialize Laravel:

> **Note:** due to Docker bind-mount constraints, `.env` cannot be written from inside the container.  
> Generate keys with `--show` and paste the value manually into `.env` on the host.

```shell script
# Print APP_KEY (paste into .env as APP_KEY=base64:...)
docker compose exec app php artisan key:generate --show

# Restart to apply new .env values
docker compose down && docker compose up -d

docker compose exec app php artisan migrate --force
docker compose exec app php artisan config:cache
docker compose exec app php artisan route:cache
```

5. Set up Telegram webhook:

```shell script
docker compose exec app php artisan telebot:webhook --setup
```

6. The application is available at:

```shell script
http://localhost:8088
```

In production, use an HTTPS domain via an external reverse proxy.

Stop containers:

```shell script
docker compose down
```
