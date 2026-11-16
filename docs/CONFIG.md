# Конфигурация EAM

Все настройки находятся в `config/config.php` (не коммитится).
Шаблон — `config/config.example.php`.

## app

| Ключ | Назначение |
|---|---|
| `name` | Название сайта в шапке и письмах. |
| `base_url` | Полный внешний URL без завершающего `/`. Используется в ссылках писем. |
| `default_locale` | Язык по умолчанию: `en`, `ru`, `uk`, `pl`. |
| `locales` | Доступные языки. |
| `timezone` | Часовой пояс PHP, например `UTC` или `Europe/Moscow`. |
| `secret` | Случайная строка >= 16 символов для HMAC-хешей токенов. Обязательно замените. |
| `asset_version` | Версия CSS/JS для сброса кеша браузера. Меняйте при обновлении assets. |
| `session_cookie` | Имя cookie сессии. |
| `session_secure` | `true`/`false` или `null` (авто по HTTPS). |
| `session_samesite` | `Lax`, `Strict` или `None`. |
| `debug` | Показывать текст ошибок на странице 500. В production — `false`. |

Пример генерации `secret`:

```bash
openssl rand -hex 32
# или
php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
# или
head -c 32 /dev/urandom | base64
```

Минимум — 16 символов, рекомендуется 32 байта (64 hex-символа).
Не используйте placeholder `CHANGE_ME...`: он специально запрещён.

## ejabberd

| Ключ | Назначение |
|---|---|
| `rest_url` | URL `mod_http_api`, например `https://host:7283/api`. |
| `login` | JID бота для Basic Auth. |
| `password` | Пароль бота. |
| `oauth_token` | Bearer-токен; если задан, используется вместо Basic Auth. |
| `verify_tls` | Проверять TLS-сертификат. В production — `true`. |
| `timeout` | Таймаут запроса, секунды. |
| `retries` | Число повторов при сетевой ошибке. |

## registration

| Ключ | Назначение |
|---|---|
| `enabled` | Включена ли регистрация. |
| `hosts` | Массив доменов для формы регистрации. |
| `two_step` | Требовать активацию по email. |
| `activation_ttl` | Время жизни ссылки активации, секунды. |
| `rate_limit.count` / `window` | Лимит успешных регистраций с одного IP за окно. |
| `allowed_username_regexp` | Регулярное выражение без delimiters; проверяется с флагом `i`. |
| `blocked_username_regexp` | Запрещённые имена; тоже без delimiters. |
| `welcome.enabled` | Отправлять XMPP-приветствие после активации/регистрации. |
| `welcome.subject` / `body` | Текст приветствия; подстановки `{jid}`, `{username}`, `{host}`, `{site}`. |
| `watchers` | Массив JID для уведомлений. |
| `watch.registration` | Уведомлять о новых регистрациях. |
| `watch.activation` | Уведомлять об активациях. |

## change_email

| Ключ | Назначение |
|---|---|
| `enabled` | Включена ли смена email. |
| `token_ttl` | Время жизни ссылки подтверждения, секунды. |
| `rate_limit` | Лимит запросов с IP. |
| `watch` | Уведомлять watchers о запросе и смене. |

## password_reset

| Ключ | Назначение |
|---|---|
| `enabled` | Включён ли сброс пароля. |
| `token_ttl` | Время жизни ссылки, секунды. |
| `rate_limit` | Лимит запросов с IP. |
| `limit_count` / `limit_window` | Лимит запросов на один JID за окно. |
| `unban_ip` | Вызывать `unban_ip` при успешной смене пароля. |
| `watch` | Уведомлять watchers. |

## delete_account

| Ключ | Назначение |
|---|---|
| `enabled` | Включено ли удаление. |
| `token_ttl` | Время жизни ссылки, секунды. |
| `rate_limit` | Лимит запросов с IP. |
| `watch` | Уведомлять watchers. |

## mail

| Ключ | Назначение |
|---|---|
| `transport` | `smtp` или `mail`. |
| `from_email` | Адрес отправителя. |
| `from_name` | Имя отправителя. |
| `smtp.host` / `port` | SMTP-сервер. |
| `smtp.encryption` | `none`, `tls` (STARTTLS) или `ssl`. |
| `smtp.username` / `password` | SMTP Auth; пусто — без авторизации. |
| `smtp.verify_peer` | Проверять TLS-сертификат SMTP. |
| `smtp.timeout` | Таймаут SMTP, секунды. |

Письма отправляются в формате multipart/alternative (HTML + text).
Заголовки очищаются от CR/LF.

## captcha

| Ключ | Назначение |
|---|---|
| `provider` | `builtin`, `recaptcha` или `none`. |
| `builtin.length` | Длина кода. |
| `builtin.width` / `height` | Размер картинки. |
| `builtin.ttl` | Время жизни кода, секунды. |
| `recaptcha.version` | `v2` (checkbox) или `v3`. |
| `recaptcha.site_key` | Site key Google. |
| `recaptcha.secret_key` | Secret key Google. |
| `recaptcha.v3_threshold` | Минимальный score для v3 (0..1). |
| `recaptcha.v3_action` | Action для v3. |

Если `provider=builtin`, а расширение `gd` недоступно, автоматически
используется математическая капча.

## validation

| Ключ | Назначение |
|---|---|
| `check_mx` | Проверять MX-запись домена email. |
| `block_disposable` | Блокировать одноразовые email через UserCheck. |
| `usercheck_url` | Базовый URL API UserCheck. |
| `timeout` | Таймаут проверки, секунды. |
| `cache_ttl` | Время кеширования результата, секунды. |

Если UserCheck недоступен, проверка завершается «разрешить» (fail-open), как
в оригинальном плагине.

## password

| Ключ | Назначение |
|---|---|
| `min_length` / `max_length` | Длина пароля. |
| `min_strength` | Минимальная оценка 0..4 (3 — «хороший», 4 — «сильный»). |

Оценка считается и на сервере, и в JavaScript. Серверная проверка обязательна.

## security

| Ключ | Назначение |
|---|---|
| `honeypot` | Скрытое поле-ловушка для ботов. |
| `trusted_proxies` | Массив IP reverse proxy, которым доверяем `X-Forwarded-For`. |

## storage

| Ключ | Назначение |
|---|---|
| `driver` | `auto`, `sqlite` или `files`. |
| `sqlite_path` | Путь к SQLite-базе. |
| `files_path` | Каталог файлового fallback. |
| `sessions_path` | Каталог PHP-сессий. |

`auto` использует SQLite при наличии расширения `sqlite3`, иначе файлы.

В bootstrap определена константа `EAM_APP_DIR`. Используйте её, чтобы конфиг
можно было переносить без правки путей:

```php
'storage' => [
    'sqlite_path'   => EAM_APP_DIR . '/storage/eam.sqlite',
    'files_path'    => EAM_APP_DIR . '/storage/data',
    'sessions_path' => EAM_APP_DIR . '/storage/sessions',
],
```

## Расположение конфига

Приоритет:

1. `EAM_CONFIG` — абсолютный путь;
2. `../config.php` — внешний файл на уровень выше webroot (shared-режим);
3. `_app/config/config.php` — внутренний файл;
4. `config/config.php` — стандартная раскладка с document root `public/`.

Для shared-режима внешний файл выигрывает, поэтому внутренний можно просто
переместить на уровень выше без переименования. Подробнее:
`docs/SHARED_HOSTING.md`.

## logging

| Ключ | Назначение |
|---|---|
| `enabled` | Включить логирование. |
| `path` | Путь к лог-файлу. |
| `level` | `debug`, `info`, `warning`, `error`. |

В логах маскируются email; пароли, токены, OAuth и XML с email не пишутся.

## Пример запуска с другим конфигом

```bash
EAM_CONFIG=/path/to/config.php php -S 127.0.0.1:8080 -t public public/router.php
```
