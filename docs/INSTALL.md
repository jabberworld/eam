# Установка EAM

## Требования

- PHP **8.2+**.
- Расширения PHP:
  - обязательные: `curl`, `openssl`, `mbstring`, `dom`, `session`, `json`;
  - рекомендуется: `sqlite3` (хранилище токенов и rate-limit);
  - опционально: `gd` (встроенная графическая капча; без GD используется математическая).
- `pdo_sqlite` **не требуется**: SQLite работает через `SQLite3`.
- Веб-сервер с HTTPS (Apache, Nginx, Caddy) или PHP built-in server для разработки.
- ejabberd **>= 24.06** с `mod_http_api`, `mod_private`, `mod_admin_extra`
  (см. `docs/EJABBERD.md`).
- SMTP-сервер или настроенный `mail()`.

## Развёртывание

### Стандартный режим

Document root указывает на каталог `public/`.

```bash
git clone <repo> eam
cd eam
cp config/config.example.php config/config.php
# отредактируйте config/config.php
chmod -R u+rwX storage
```

### Shared-хостинг (`public_html`)

Если домен смотрит на `public_html` и менять document root неудобно,
используйте `_app`-раскладку:

```text
public_html/
  index.php
  .htaccess
  assets/
  _app/          # закрыт .htaccess
    src/ templates/ lang/ storage/ config/
config.php       # внешний конфиг, приоритетный
```

Соберите пакет:

```bash
bin/package-shared.sh
```

Или разложите вручную по `docs/SHARED_HOSTING.md`. Внешний `config.php`
на уровень выше webroot имеет приоритет над `_app/config/config.php`.
Для shared-режима обязательны работающий `.htaccess`/`mod_rewrite` и проверка,
что `/_app/...` отдаёт `403`/`404`.

Сгенерируйте `app.secret`:

```bash
openssl rand -hex 32
```

`config/config.php` содержит секреты и исключён из git.

## Apache

DocumentRoot: `/path/to/eam/public`. В `public/.htaccess` уже есть правила
rewrite. Убедитесь, что `mod_rewrite` включён и `AllowOverride` разрешает
`.htaccess`.

## Nginx

```nginx
server {
    listen 443 ssl http2;
    server_name accounts.example.com;
    root /path/to/eam/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }

    location ~ /\.(?!well-known) {
        deny all;
    }
}
```

Не отдавайте наружу `config/`, `storage/`, `tests/`, `.testenv/`.

## Права

PHP-процесс должен иметь право записи в:

- `storage/sessions/` — сессии;
- `storage/` или путь из `storage.sqlite_path` — SQLite;
- `storage/data/` — файловый fallback;
- `storage/logs/` — логи.

Пример:

```bash
mkdir -p storage/sessions storage/data storage/logs
chown -R www-data:www-data storage
chmod -R 750 storage
```

Для shared-режима каталог называется `public_html/_app/storage/`:

## Проверка конфигурации

```bash
php -l public/index.php
php -l config/config.php
php bin/cleanup.php      # проверка доступа к storage
```

Откройте `/health` — должен вернуться JSON `{"status":"ok", ...}`.

## Разработка

```bash
PHP_BIN=php bin/serve.sh
# или
php -S 127.0.0.1:8080 -t public public/router.php
```

Для тестовой почты:

```bash
.testenv/mailpit/mailpit --smtp 127.0.0.1:1025 --listen 127.0.0.1:8025
```

В `config/config.php`:

```php
'mail' => [
    'transport' => 'smtp',
    'smtp' => ['host' => '127.0.0.1', 'port' => 1025, 'encryption' => 'none'],
],
```

## Очистка истёкших токенов

Просроченные токены и rate-limit записи удаляются автоматически с небольшой
вероятностью при обработке запросов. Для гарантированной очистки добавьте cron:

```cron
*/15 * * * * cd /path/to/eam && EAM_CONFIG=/path/to/eam/config/config.php php bin/cleanup.php >/dev/null 2>&1
```

Для shared-раскладки `bin/cleanup.php` лежит вне пакета; очистка также
выполняется probabilistically при обычных запросах. При необходимости
запускайте cleanup из полного репозитория, указав внешний конфиг и каталог
приложения:

```bash
EAM_CONFIG=/home/user/config.php \
EAM_APP_DIR=/home/user/public_html/_app \
php bin/cleanup.php
```

## Тесты

```bash
.testenv/php/php tests/run.php
tests/integration.sh
tests/shared-layout.sh
tests/real-smoke.sh   # требует .testenv/config.real.php
```

- `tests/run.php` — unit-тесты.
- `tests/integration.sh` — полный поток с fake ejabberd API и Mailpit.
- `tests/shared-layout.sh` — shared-раскладка, внешний/внутренний конфиг,
  deny для `_app`, регистрация и активация.
- `tests/real-smoke.sh` — smoke-тест против реального тестового ejabberd;
  создаёт одноразовый `eamtestreal*` и удаляет только его.
