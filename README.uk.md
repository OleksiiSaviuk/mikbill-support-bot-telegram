
# MB Support Bot

> 🇬🇧 [English documentation](README.md)

> **Форк від** [mikbill/support-bot-telegram](https://github.com/mikbill/support-bot-telegram) — кастомна версія з розширеним функціоналом.

Telegram-бот для операторів технічної підтримки ISP — для швидкого пошуку інформації про абонентів без входу до адмін-панелі.

### Можливості

- Пошук абонента за логіном / договором / UID / телефоном
- Картка абонента (баланс, тариф, статус, IP, MAC тощо)
- Історія платежів
- Історія сесій
- Список послуг
- Перехід до особистого кабінету абонента
- Інтеграція з Wildcore: діагностика ONU/ONT і порту доступу
- Кнопка оновлення Wildcore прямо в картці абонента
- Визначення типу підключення: ONU / порт комутатора / невідомо
- Форматування номерів телефону у міжнародному форматі
- Багатомовний інтерфейс: 🇺🇦 Українська, 🇬🇧 English
- Тікети MikBill: список, перегляд повідомлень, відповідь оператора, зміна статусу (прямий MySQL)
- Автопошук за телефоном з дип-посилання `/start`
- Автоочистка історії повідомлень бота

### Changelog

#### Upstream (mikbill/support-bot-telegram)

**23.04.2022**
- Додана локалізація бота: uk, en, ru
- Валюта береться з налаштувань білінгу

**03.05.2022**
- Додана кнопка викидання абонента
- Додана історія змін абонента
- Додана історія авторизацій
- Додана історія тікетів
- Додано пошук за номером телефону

#### Custom fork (OleksiiSaviuk)

**25.04.2026**
- Інтеграція Wildcore: діагностика ONU/ONT і порту доступу
- Кнопка оновлення Wildcore прямо в картці (зберігає меню і spoiler)
- Блок ONU: іконка статусу, причина падіння, події підняття/падіння, тривалість онлайн
- Визначення типу підключення: ONU / порт комутатора / невідомо
- Форматування телефонів у міжнародному форматі з налаштованим кодом країни
- Підтримка часового поясу через `APP_TIMEZONE` (PHP і Docker-контейнери)
- Відображення тривалості онлайн у картці абонента та блоці ONU
- Видалено поле Local IP з картки абонента
- Розширена локалізація: uk / en (ru залишено для сумісності)
- Видалено коментарі російською з коду
- Docker Compose із сервісами `app` + `mysql`

**27.04.2026**
- Інтеграція тікетів MikBill: список сортований за статусом/активністю, повна історія повідомлень, відповідь оператора, зміна статусу (`opened` → `in_work` → `performed` → `closed`)
- Окреме MySQL-підключення для тікетів (`MIKBILL_TICKETS_DB_*`) з мінімальними необхідними правами
- Мапа операторів через `MIKBILL_TICKETS_OPERATORS` (MikBill operator id ↔ Telegram user id)
- Автопошук за телефоном з дип-посилання `/start` (`TELEGRAM_ENABLE_START_PHONE_SEARCH`)
- Автоочистка історії повідомлень бота з налаштовуваним періодом зберігання, розміром пачіта і інтервалом
- Налаштовувана кількість результатів пошуку на сторінку (`TELEGRAM_SEARCH_PER_PAGE`)


### Вимоги

- PHP >= 8.1
- Composer 2.x


### 1. Встановлення

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

Вкажіть кореневу директорію `/var/www/support-bot-telegram/public`.  
Рекомендується використовувати окремий піддомен і вказати його в `APP_URL`.  
Для вебхука Telegram необхідний дійсний TLS-сертифікат.

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

Вкажіть кореневу директорію `/var/www/support-bot-telegram/public`.

Приклад `.htaccess`:

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

### 3. Конфігурація

Скопіюйте `.env.example` в `.env` та заповніть необхідні значення:

```shell script
APP_URL=https://your-domain.tld
APP_TIMEZONE=Europe/Kiev

TELEGRAM_BOT_TOKEN="11111:xxxxxxxxxxxx"
TELEGRAM_BOT_NAME="name_bot"
# JSON-масив Telegram ID користувачів, яким дозволений доступ
TELEGRAM_BOT_ALLOWED_ID="[1234345, 4789456]"
# Кількість результатів пошуку на сторінку (за замовчуванням: 5)
TELEGRAM_SEARCH_PER_PAGE=5
# Увімкнути автопошук за номером з /start payload виду phone_380971234567
TELEGRAM_ENABLE_START_PHONE_SEARCH=false
# Кількість днів зберігання історії повідомлень (автоочистка)
TELEGRAM_HISTORY_RETENTION_DAYS=7
# Максимальна кількість message_id у кеші для одного чату
TELEGRAM_HISTORY_TRACK_LIMIT=5000
# Мінімальний інтервал між запусками автоочистки (секунди)
TELEGRAM_HISTORY_PURGE_INTERVAL_SECONDS=300
# Скільки старих повідомлень видаляти за один запуск
TELEGRAM_HISTORY_DELETE_BATCH_SIZE=25
# Опціональний код країни для локальних номерів без +
# Приклади: 38 (UA), 48 (PL), 1 (US/CA)
TELEGRAM_DEFAULT_PHONE_COUNTRY_CODE=

MIKBILL_CABINET_HOST="https://stat.your-domain.tld"
MIKBILL_HOST="https://admin.your-domain.tld"
MIKBILL_LOGIN=admin
MIKBILL_PASSWORD=admin

# Інтеграція Wildcore
WILDCORE_ENABLED=false
WILDCORE_URL="https://wildcore.your-domain.tld"
WILDCORE_API_KEY="your_wildcore_api_key"
```

### 3.1 Ключ застосунку

```shell script
php artisan key:generate
```

### 3.2 Інтеграція Wildcore (ONU/ONT)

Якщо `WILDCORE_ENABLED=true`, у картці абонента відображається блок діагностики:

- **ONU/ONT**: статус, RX-сигнал, події підняття/падіння, причина падіння, тривалість онлайн, LAN/MAC, локація, висновок
- **Порт комутатора**: статус порту, тип, LAN/MAC, локація, висновок
- **Невідомо**: технічний блок знайденого підключення

Кнопка `🔄 Wildcore оновити` отримує актуальні дані безпосередньо з пристрою.

### 3.3 Формат номерів телефону

Телефони в картці відображаються у міжнародному форматі з `+`:

- `+380...` — залишається без змін
- `00380...` — перетворюється на `+380...`
- Локальні номери без коду країни можна нормалізувати через `TELEGRAM_DEFAULT_PHONE_COUNTRY_CODE`

Приклад:

```shell script
TELEGRAM_DEFAULT_PHONE_COUNTRY_CODE=38
```

Якщо змінна не задана, неоднозначні локальні номери відображаються без змін.

### 3.4 Часовий пояс

Час у картці абонента (події ONU, дати підняття/падіння) відображається з урахуванням часового поясу, заданого через `APP_TIMEZONE`.

Значення за замовчуванням: `Europe/Kiev`.

```shell script
APP_TIMEZONE=Europe/Kiev
```

Змінна застосовується як для PHP/Laravel, так і для системного часу Docker-контейнерів (через `TZ` у `docker-compose.yml`).  
Допустимі значення: [PHP timezones](https://www.php.net/manual/en/timezones.php).

### 3.5 Мова бота

Підтримувані локалі:

- `uk` — Українська
- `en` — English

Щоб змінити мову бота, додайте до `.env`:

```shell script
APP_LOCALE=uk
```

Файли локалізацій знаходяться у:

```shell script
resources/lang/uk.json
resources/lang/en.json
```

### 3.6 Тікети MikBill у Telegram (прямий MySQL)

Бот підтримує роботу з тікетами MikBill напряму через MySQL:

- Показ останніх тікетів у Telegram (кнопка `🎫 Тікети` в головному меню)
- Відкриття тікета і перегляд повної історії повідомлень
- Відповідь оператора в `tickets_messages`
- Зміна статусу тікета (`opened`, `in_work`, `performed`, `closed`)
- Відкриття інформації абонента по `useruid` із тікета

Доступ обмежений:

- Функціонал має бути увімкнений в env
- Telegram користувач має бути зіставлений з operator id у `MIKBILL_TICKETS_OPERATORS`

Додайте в `.env`:

```shell script
# Увімкнути/вимкнути розділ тікетів у боті
MIKBILL_TICKETS_ENABLED=true

# Кількість тікетів у списку
MIKBILL_TICKETS_LIMIT=10

# Мапа: MikBill operator_id : Telegram user_id
# Приклад для одного оператора:
MIKBILL_TICKETS_OPERATORS="[1:111111]"
# Приклад для кількох операторів:
# MIKBILL_TICKETS_OPERATORS="[1:111111,2:222222]"

# Максимальна довжина відповіді оператора
MIKBILL_TICKETS_MAX_MESSAGE_LENGTH=500

# Окреме MySQL-підключення до таблиць тікетів
MIKBILL_TICKETS_DB_HOST=127.0.0.1
MIKBILL_TICKETS_DB_PORT=3306
MIKBILL_TICKETS_DB_NAME=mikbill
MIKBILL_TICKETS_DB_USER=tg_ticket_bot
MIKBILL_TICKETS_DB_PASSWORD=strong_password
```

### 3.7 Мінімальні права MySQL для функціоналу тікетів

Для роботи з тікетами потрібні лише такі операції:

- `SELECT` з:
    - `tickets_tickets`
    - `tickets_messages`
    - `tickets_status_types`
    - `tickets_categories_list`
    - `tickets_priorities_types`
- `INSERT` у `tickets_messages`
- `UPDATE` у `tickets_tickets`
- `UPDATE` поля `tickets_messages.unread`, щоб позначати клієнтські повідомлення як прочитані після відкриття тікета

Приклад (MySQL/MariaDB):

```sql
CREATE USER 'tg_ticket_bot'@'127.0.0.1' IDENTIFIED BY 'strong_password';

GRANT SELECT ON mikbill.tickets_tickets TO 'tg_ticket_bot'@'127.0.0.1';
GRANT SELECT ON mikbill.tickets_messages TO 'tg_ticket_bot'@'127.0.0.1';
GRANT SELECT ON mikbill.tickets_status_types TO 'tg_ticket_bot'@'127.0.0.1';
GRANT SELECT ON mikbill.tickets_categories_list TO 'tg_ticket_bot'@'127.0.0.1';
GRANT SELECT ON mikbill.tickets_priorities_types TO 'tg_ticket_bot'@'127.0.0.1';

GRANT INSERT ON mikbill.tickets_messages TO 'tg_ticket_bot'@'127.0.0.1';
GRANT UPDATE (unread) ON mikbill.tickets_messages TO 'tg_ticket_bot'@'127.0.0.1';
GRANT UPDATE ON mikbill.tickets_tickets TO 'tg_ticket_bot'@'127.0.0.1';

FLUSH PRIVILEGES;
```

Якщо у вашій збірці MySQL/MariaDB недоступні колонкові `UPDATE`-права, замість цього видайте табличний `UPDATE` на `tickets_messages`.

Якщо хост БД інший, замініть `'127.0.0.1'` на потрібний хост (наприклад `'%'` тільки якщо це дійсно необхідно).

### 4. Webhook

Встановити webhook:

```shell script
php artisan telebot:webhook --setup
```

Видалити webhook:

```shell script
php artisan telebot:webhook --remove
```

### 5. Long polling

Спочатку видаліть webhook (якщо встановлено), потім запустіть:

```shell script
php artisan telebot:polling --all
```

### 6. Автоочистка історії повідомлень

- `TELEGRAM_HISTORY_RETENTION_DAYS` — скільки днів зберігати повідомлення. За замовчуванням: `7`.
- `TELEGRAM_HISTORY_TRACK_LIMIT` — максимальна кількість message_id у кеші для чату. За замовчуванням: `5000`.
- `TELEGRAM_HISTORY_PURGE_INTERVAL_SECONDS` — мінімальний інтервал між запусками очистки. За замовчуванням: `300`.
- `TELEGRAM_HISTORY_DELETE_BATCH_SIZE` — скільки повідомлень видаляти за один запуск. За замовчуванням: `25`.

Рекомендовані значення для кращої продуктивності:

```shell script
TELEGRAM_HISTORY_RETENTION_DAYS=7
TELEGRAM_HISTORY_TRACK_LIMIT=5000
TELEGRAM_HISTORY_PURGE_INTERVAL_SECONDS=900
TELEGRAM_HISTORY_DELETE_BATCH_SIZE=10
```

### 7. Docker Compose

У проекті є `docker-compose.yml` з двома сервісами:

- `app` — Apache + PHP 8.1
- `mysql` — MySQL 8

Nginx у Compose відсутній. Якщо є зовнішній reverse proxy (Nginx / Traefik / Caddy) — проксуйте на:

- `8088` → HTTP Laravel

**Кроки:**

1. Підготуйте env-файл:

```shell script
cp .env.example .env
```

2. Для production вкажіть у `.env`:

```shell script
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain.tld
APP_TIMEZONE=Europe/Kiev

DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=your_db_name
# DB_USERNAME не повинен бути root — MySQL-контейнер автоматично створить цього користувача
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

3. Запустіть контейнери:

```shell script
docker compose up -d --build
```

4. Ініціалізуйте Laravel:

> **Примітка:** через обмеження Docker bind-mount запис у `.env` зсередини контейнера неможливий.  
> Генеруйте ключі з прапором `--show` і вставляйте значення вручну у `.env` на хості.

```shell script
# Показати APP_KEY (вставити в .env: APP_KEY=base64:...)
docker compose exec app php artisan key:generate --show

# Перезапустити, щоб застосувати нові значення з .env
docker compose down && docker compose up -d

docker compose exec app php artisan migrate --force
docker compose exec app php artisan config:cache
docker compose exec app php artisan route:cache
```

5. Налаштуйте Telegram webhook:

```shell script
docker compose exec app php artisan telebot:webhook --setup
```

6. Застосунок доступний за адресою:

```shell script
http://localhost:8088
```

У production використовуйте HTTPS-домен через зовнішній reverse proxy.

Зупинити контейнери:

```shell script
docker compose down
```
