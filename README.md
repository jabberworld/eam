# EAM — Ejabberd Account Manager

Standalone PHP-аналог WordPress-плагина **Ejabberd Account Tools** (GPLv3).
Без WordPress и отдельной админки: настройки — в `config.php`.

Корень проекта одновременно является webroot. Отдельного `public/` нет.

## Что делает

- Landing-страница и разделы:
  - регистрация с подтверждением по email;
  - смена приватного email;
  - сброс пароля;
  - удаление аккаунта.
- Работа через ejabberd `mod_http_api` (Basic Auth или OAuth).
- Языки: `en`, `ru`, `uk`, `pl`.
- Хранилище: SQLite3 или файловый fallback.
- Капча: встроенная GD, математическая или reCAPTCHA v2/v3.

## Структура

```text
/
  index.php               # front controller
  .htaccess               # deny приватных каталогов + rewrite
  README.md
  LICENSE
  assets/                 # публичные CSS/JS
  _app/                   # закрыт .htaccess
    src/
    templates/
    lang/
    storage/              # sqlite, sessions, logs
    config/
      config.example.php
      config.php          # gitignored
  server/                 # не-shared запуск и dev-скрипты
  docs/                   # документация
  tests/                  # тесты
  .testenv/               # локальное окружение, gitignored
```

`_app`, `server`, `docs`, `tests` и служебные markdown-файлы закрываются
`.htaccess`/Nginx. Публичны `README.md`, `LICENSE`, `assets/` и `index.php`.

## Быстрый старт на shared-хостинге

1. Загрузите содержимое проекта в webroot (или в подкаталог).
2. Создайте конфиг:

   ```bash
   cp _app/config/config.example.php _app/config/config.php
   ```

   Либо вынесите его на уровень выше корня проекта как `config.php` — внешний
   файл имеет приоритет.

3. Заполните `base_url`, ejabberd, SMTP и домены. `base_url` должен включать
   подкаталог, если сайт открывается не из корня:

   ```php
   'base_url' => 'https://example.com/eam',
   ```

4. Сгенерируйте `app.secret` (любой вариант):

   ```bash
   openssl rand -hex 32
   php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
   head -c 32 /dev/urandom | base64
   ```

5. Выдайте права на storage:

   ```bash
   chmod -R u+rwX _app/storage
   ```

6. Убедитесь, что Apache обрабатывает `.htaccess` и включён `mod_rewrite`.
   Для подкаталога при необходимости добавьте в `.htaccess`:

   ```apache
   RewriteBase /eam/
   ```

7. Проверьте:

   ```bash
   curl -I https://example.com/registration
   curl -I https://example.com/assets/css/style.css
   curl -I https://example.com/_app/config/config.php   # 403/404
   ```

Подробно: `docs/INSTALL.md`.

## Конфиг

Приоритет:

```text
EAM_CONFIG
→ ../config.php              # внешний, приоритетный
→ _app/config/config.php     # внутренний
```

Шаблон: `_app/config/config.example.php`.
Секреты не коммитятся.

## Не-shared хостинг

Скрипты и инструкции для Apache/Nginx/PHP-FPM:

- `server/README.md`
- `server/apache.conf.example`
- `server/nginx.conf.example`
- `server/serve.sh`
- `server/cleanup.php`
- `server/cron.example`

## Тесты

```bash
.testenv/php/php tests/run.php
tests/integration.sh
tests/subdir.sh
tests/real-smoke.sh   # требует .testenv/config.real.php
```

## Документация

- `docs/INSTALL.md` — установка на shared-хостинг.
- `docs/CONFIG.md` — параметры конфига.
- `docs/EJABBERD.md` — `mod_http_api`, `api_permissions`, Basic/OAuth.
- `docs/PROGRESS.md` — журнал решений и проверок.
- `server/README.md` — запуск вне shared-хостинга.

## Лицензия

GPLv3, как и оригинальный плагин.
