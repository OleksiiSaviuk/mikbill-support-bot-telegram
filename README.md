

## MB Support Bot
Бот предназначен в помощь операторам тех поддержки когда не совсем удобно заходить в админ панель


### Возможности:
 - поиск абонента по логин/договор/uid
 - просмотр базовой информации по абоненту
 - просмотр истории платежей
 - просмотр истории сессий
 - просмотр услуг
 - вход в ЛК 
 
### Changelog:
#### 23.04.2022
- Добавлена локализация бота uk, en, ru
- Валюта берется из настроек биллинга

#### 03.05.2022
- Добавлена кнопка выкидывания абонента
- Добавлена история изменений абонента
- Добавлена история авторизаций абонента
- Добавлена история изменений тикетов
- Добавлен поиск абонента по телефону


 
![png image](https://github.com/kagatan/mb-support-bot/blob/master/resources/img/image.png?raw=true)

### Требования

- PHP >= 7.4 (Рекомендуем PHP 8)
- Composer version 2.2.x


### 1. Установка

Устанвливаем пакеты и зависимости
```shell script
cd /var/www/
git clone https://github.com/mikbill/support-bot-telegram.git
cd support-bot-telegram

composer install

# даем права
mkdir -p /var/www/support-bot-telegram/storage/{sessions,views,cache}
sudo chown -R www-data:www-data /var/www/support-bot-telegram
sudo chmod -R 775 /var/www/support-bot-telegram/storage/
```

### 2. Nginx 

создаем конфиг на публичную диреторию
/var/www/support-bot-telegram/public

в идеале вынести на отдельный поддомен, и указать его в конфиге APP_URL
для вебхука телеграма обязателен валидный сертификат
  
p.s. необходима если будет использовать вебхук

```shell script
...

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
      fastcgi_pass unix:/var/run/php/php7.4-fpm.sock;
      fastcgi_index index.php;
      fastcgi_param SCRIPT_FILENAME /var/www/support-bot-telegram/public$fastcgi_script_name;
   }

...

```

### 2.1 Apache

создаем конфиг на публичную диреторию
/var/www/support-bot-telegram/public


пример .htaccess
```shell script

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
### 3.1 Настраиваем .env

Конфиг находится в корне диреткории, файл .env.example
Скопируйте его переименовав в .env

Необходимые к заполнению:

```shell script
APP_URL=https://my-domen.ru
TELEGRAM_BOT_TOKEN="11111:xxxxxxxxxxxx"
TELEGRAM_BOT_NAME="name_bot"
# Список ID пользователей Telegram, которым разрешён доступ (в виде JSON-массива)
TELEGRAM_BOT_ALLOWED_ID="[1234345, 4789456]"

MIKBILL_CABINET_HOST="https://stat.my-domen.ru"
MIKBILL_HOST="https://admin.my-domen.ru"
MIKBILL_LOGIN=admin
MIKBILL_PASSWORD=admin

```

### 3.2 Ключ приложения

```shell script
php artisan key:generate
```

### 4. Webhook

Установить webhook
```php
php artisan telebot:webhook --setup
```

Удалить webhook
```php
php artisan telebot:webhook --remove
```

### 5. Long polling

Запустить в режиме поллинга без вебхука.

Чтоб запустить необходимо сначала выполнить команду 
"удалить вебхук" если он установлен
```php
php artisan telebot:polling --all
```

### Смена локализации
 Поддерживаемы локали:
- uk - Ukraine
- en - English
- ru - Russian 

Для того чтобы сменить локализацию бота , к примеру, на EN необходимо добавить переменную в файл конфига .env:
```shell script
APP_LOCALE=en
```

Файлы локализаций находятся по пути:
```shell script
/resources/lang/ru.json
```

### Запуск через Docker Compose

В проект добавлен `docker-compose.yml` c сервисами:

- `app` (Apache + PHP 8.1)
- `mysql` (MySQL 8)

Локальный Nginx в compose не используется.
Если у вас уже есть отдельный reverse proxy (Nginx/Traefik/Caddy), проксируйте его на порт этого хоста:

- `8088` -> HTTP Laravel

1. Подготовить env-файл:

```shell script
cp .env.example .env
```

2. Для production окружения проверьте (или добавьте) в `.env`:

```shell script
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain.tld

DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=your_db_name
# DB_USERNAME не должен быть root — MySQL контейнер создаст этого пользователя автоматически
DB_USERNAME=your_db_user
DB_PASSWORD=strong_password
DB_ROOT_PASSWORD=strong_root_password

TELEGRAM_BOT_TOKEN="11111:xxxxxxxxxxxx"
TELEGRAM_BOT_NAME="name_bot"
TELEGRAM_BOT_ALLOWED_ID="[1234345, 4789456]"

MIKBILL_HOST="https://admin.my-domen.ru"
MIKBILL_CABINET_HOST="https://stat.my-domen.ru"
MIKBILL_LOGIN=admin
MIKBILL_PASSWORD=admin
```

3. Запустить контейнеры:

```shell script
docker compose up -d --build
```

4. Выполнить инициализацию Laravel:

> **Примечание:** из-за ограничений Docker bind-mounted `.env` невозможна.  
> Генерируйте ключи с флагом `--show` и вставляйте значения вручную в `.env` на хосте.

```shell script
# Показать APP_KEY (вставить в .env: APP_KEY=base64:...)
docker compose exec app php artisan key:generate --show

# Затем перезапустить, чтобы применить новые значения из .env
docker compose down && docker compose up -d

docker compose exec app php artisan migrate --force
docker compose exec app php artisan config:cache
docker compose exec app php artisan route:cache
```

5. Установить Telegram webhook:

```shell script
docker compose exec app php artisan telebot:webhook --setup
```

6. Приложение доступно по адресу:

```shell script
http://localhost:8088
```

В production рекомендуется использовать только HTTPS-домен через внешний reverse proxy.

Остановка:

```shell script
docker compose down
```
