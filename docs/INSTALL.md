# Установка на shared-хостинг

Корень проекта — это webroot. Отдельного каталога `public/` нет.

## Требования

- PHP **8.2+**.
- Расширения:
  - обязательные: `curl`, `openssl`, `dom`, `session`, `json`;
  - рекомендуемые: `mbstring`, `sqlite3`, `gd`.
- Apache с `mod_rewrite` и работающим `.htaccess` (или Nginx с `try_files`).
- HTTPS.
- ejabberd >= 24.06 с `mod_http_api`, `mod_private`, `mod_admin_extra`.
- SMTP или настроенный `mail()`.

## Раскладка

```text
/
  index.php
  .htaccess
  README.md
  LICENSE
  assets/
  _app/
    src/
    templates/
    lang/
    storage/
    config/
      config.example.php
      config.php
  server/
  docs/
  tests/
```

`_app`, `server`, `docs`, `tests`, `AGENTS.md`, `CLAUDE.md` и скрытые файлы
закрываются `.htaccess`/Nginx. Публичны `index.php`, `assets/`, `README.md`,
`LICENSE`.

## Установка

1. Загрузите содержимое проекта в webroot (или в подкаталог).

2. Создайте конфиг:

   ```bash
   cp _app/config/config.example.php _app/config/config.php
   ```

   Или вынесите его на уровень выше корня проекта как `config.php`.
   Внешний файл имеет приоритет:

   ```text
   EAM_CONFIG → ../config.php → _app/config/config.php
   ```

3. Заполните `base_url`, ejabberd, SMTP и домены.

   Для корня домена:

   ```php
   'base_url' => 'https://example.com',
   ```

   Для подкаталога:

   ```php
   'base_url' => 'https://example.com/eam',
   ```

4. Сгенерируйте `app.secret`:

   ```bash
   openssl rand -hex 32
   # или
   php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
   # или
   head -c 32 /dev/urandom | base64
   ```

   Минимум 16 символов, рекомендуется 32 байта.

5. Выдайте права на storage:

   ```bash
   mkdir -p _app/storage/sessions _app/storage/data _app/storage/logs
   chmod -R u+rwX _app/storage
   ```

6. Проверьте Apache:

   ```bash
   apache2ctl -M | grep rewrite
   ```

   Если пусто:

   ```bash
   sudo a2enmod rewrite
   sudo systemctl reload apache2
   ```

   `AllowOverride` должен разрешать `FileInfo` и `Options`.

7. Для подкаталога при необходимости добавьте в `.htaccess`:

   ```apache
   RewriteBase /eam/
   ```

8. Проверьте:

   ```bash
   curl -I https://example.com/registration             # 200
   curl -I https://example.com/assets/css/style.css     # 200
   curl -I https://example.com/_app/config/config.php   # 403/404
   curl -I https://example.com/_app/storage/eam.sqlite  # 403/404
   curl -I https://example.com/server/README.md         # 403/404
   curl -I https://example.com/docs/INSTALL.md          # 403/404
   curl -I https://example.com/tests/config.test.php    # 403/404
   ```

Если `_app` отдаёт `200`, немедленно закройте доступ: проверьте
`AllowOverride`, `mod_rewrite` и расположение `.htaccess`.

## Nginx

Для Nginx используйте `server/nginx.conf.example` как основу. Ключевые
моменты:

```nginx
root /path/to/eam;
index index.php;

location ~ ^/(_app|server|tests|docs)/ { deny all; }
location ~ /\.(?!well-known) { deny all; }
location / { try_files $uri $uri/ /index.php?$query_string; }
```

Для подкаталога:

```nginx
location /eam/ { try_files $uri $uri/ /eam/index.php?$query_string; }
```

## Контент landing и футера

Тексты главной и футера лежат в `_app/content/landing/{locale}.html` и
`_app/content/footer/{locale}.html`. Поддерживаются placeholders
`{{site_name}}`, `{{hosts_chips}}`, `{{hosts_list}}`, URL-ы и `{{year}}`.
Подробно: `docs/CONTENT.md`.

## Конфиг

- Шаблон: `_app/config/config.example.php`.
- Внутренний конфиг: `_app/config/config.php` (gitignored).
- Внешний: `../config.php`.
- `EAM_CONFIG` — абсолютный путь, высший приоритет.

Storage-пути используют `EAM_APP_DIR`, поэтому конфиг можно перемещать.
Подробно: `docs/CONFIG.md`.

## Обновление

1. Сохраните конфиг и `_app/storage/`.
2. Замените `index.php`, `.htaccess`, `assets/`, `_app/src/`,
   `_app/templates/`, `_app/lang/`, `_app/config/config.example.php`.
3. Проверьте `/health` и разделы сайта.
4. Увеличьте `app.asset_version`, чтобы сбросить кеш CSS/JS.

## Не-shared хостинг

DocumentRoot, Apache/Nginx vhost, PHP-FPM, cron и dev-сервер описаны в
`server/README.md`. Отдельной раскладки с `public/` больше нет: document root
всегда указывает на корень проекта.

## Тесты

```bash
.testenv/php/php tests/run.php
tests/integration.sh
tests/subdir.sh
tests/real-smoke.sh   # требует .testenv/config.real.php
```

- `tests/run.php` — unit.
- `tests/integration.sh` — fake ejabberd + Mailpit, полный цикл.
- `tests/subdir.sh` — установка в `/eam`, `EAM_BASE_PATH`, `/eam/api/captcha`.
- `tests/real-smoke.sh` — smoke против реального ejabberd.
