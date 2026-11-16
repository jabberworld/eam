# EAM — Ejabberd Account Manager

Standalone PHP-аналог WordPress-плагина **Ejabberd Account Tools** (GPLv3).
Без WordPress, без отдельной админки: настройки — в `config/config.php`
(стандартная раскладка) или во внешнем `config.php` (shared-хостинг).

## Что делает

- Landing-страница с описанием сервиса и ссылками на разделы.
- Регистрация аккаунта на ejabberd с подтверждением по email (two-step).
- Смена приватного email с подтверждением на новый адрес.
- Сброс пароля с подтверждением по email и ограничением попыток.
- Удаление аккаунта с подтверждением по email и повторной проверкой пароля.
- Многоязычный интерфейс: en, ru, uk, pl; русский по умолчанию.
- Работа через `mod_http_api` ejabberd (>= 24.06), Basic Auth или OAuth Bearer.
- Хранилище токенов/rate-limit: SQLite (`ext-sqlite3`) или файловый fallback.
- Капча: встроенная графическая (GD) или reCAPTCHA v2/v3; выбор в конфиге.

## Структура

```
public/          document root: index.php, router.php, assets
config/          config.example.php; config.php — локальные секреты (gitignored)
src/             PHP-классы (Eam\)
templates/       HTML-шаблоны и шаблоны писем
lang/            словари en/ru/uk/pl
storage/         sqlite, сессии, логи (gitignored)
tests/           unit-, integration- и shared-layout тесты, fake ejabberd API
docs/            установка, настройка ejabberd, конфига и shared-хостинга
deploy/shared/   шаблоны index.php/.htaccess для public_html
bin/             serve, cleanup, package-shared
.testenv/        локальный PHP/Mailpit (gitignored)
```

## Shared-хостинг

Если домен смотрит на `public_html`, а менять document root неудобно:

```bash
bin/package-shared.sh
# если сайт открывается как https://example.com/eam:
BASE_PATH=/eam bin/package-shared.sh
```

Пакет `dist/eam-shared/` содержит front controller, `assets/` и закрытый
каталог `_app/`. Для подкаталога скрипт добавит `RewriteBase /eam/`; в конфиге
укажите `base_url => https://example.com/eam`.
Конфиг можно оставить в `_app/config/config.php` или вынести
на уровень выше как `config.php` — внешний файл имеет приоритет:

```text
EAM_CONFIG → ../config.php → _app/config/config.php
```

Подробно: `docs/SHARED_HOSTING.md`. Обязательны `.htaccess`/`mod_rewrite`
и проверка, что `/_app/...` отдаёт `403`/`404`.

## Быстрый старт (разработка)

```bash
cp config/config.example.php config/config.php
# отредактируйте config/config.php
php -S 127.0.0.1:8080 -t public public/router.php
```

Или `bin/serve.sh` (использует `PHP_BIN` или `php` из PATH).

## Тесты

```bash
.testenv/php/php tests/run.php            # unit
tests/integration.sh                      # integration с fake ejabberd + Mailpit
tests/shared-layout.sh                    # shared-раскладка и конфиг
tests/package-shared.sh                   # сборка shared-пакета
```

## Документация

- `docs/INSTALL.md` — установка, веб-сервер, права, тесты.
- `docs/EJABBERD.md` — `mod_http_api`, `api_permissions`, Basic/OAuth.
- `docs/CONFIG.md` — все параметры `config/config.php`.
- `docs/SHARED_HOSTING.md` — `public_html` + `_app`, внешний/внутренний конфиг.
- `docs/PROGRESS.md` — журнал решений и проверок.

## Правила проекта

См. `AGENTS.md`. Кратко: после изменений кода — коммит в локальный git;
автор `opencode (deepseek-v4.1-flash)`; пояснения для пользователя — на русском;
прогресс и решения ведутся в `docs/PROGRESS.md`.

## Лицензия

GPLv3, как и оригинальный плагин.
