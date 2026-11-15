# EAM — журнал прогресса

## 2026-11-15 — реализация

### Исходные данные

- Оригинал: WordPress-плагин **Ejabberd Account Tools** 2.11 (GPLv3).
- Задача: standalone PHP-аналог без WordPress, конфиг-файл, landing + 4 раздела:
  «Регистрация», «Изменить email», «Удалить аккаунт», «Сброс пароля».
- Референс интерфейса: `https://jabber.name`.

### Изучение оригинала

- Связь с ejabberd: `mod_http_api`, JSON POST, Basic Auth или OAuth Bearer.
- Команды: `register`, `unregister`, `change_password`, `check_account`,
  `check_password`, `registered_vhosts`, `private_get`, `private_set`,
  `ban_account`, `unban_account`, `get_ban_details`, `kick_user`, `unban_ip`,
  `send_message`, `get_vcard`.
- Приватный email: XEP-0049 private storage, `<private xmlns='email'>...</private>`.
- Two-step: `register` → `private_set` → `ban_account(reason='Activation required')`
  → письмо → `unban_account`.
- `private_get` возвращает обёртку
  `<query xmlns='jabber:iq:private'><private xmlns='email'>...</private></query>`.
- `get_ban_details` при отсутствии бана возвращает `{}`.
- `get_vcard` без vCard возвращает HTTP 400 `"error_no_vcard_found"`.
- В ejabberd 24.06+ `unban_account` возвращает старый пароль; в 25.08+
  `ban_account` больше не меняет пароль. Целевая версия — >= 24.06.

### Решения пользователя

- Хранилище: SQLite + файловый fallback.
- Языки: en, ru, uk, pl; русский по умолчанию.
- Капча: встроенная графическая + reCAPTCHA (v2/v3) с выбором в конфиге.
- Дизайн: структура и тексты как на jabber.name, но собственная вёрстка.
- Тестовый ejabberd предоставлен; все команды боту разрешены.
- Письма в тестах: локальный Mailpit.
- Лицензия: GPLv3.
- PHP: 8.2+.

### Проверка тестового API

- API: `https://ets.jabberworld.info:7283/api`, TLS валиден.
- Vhost: `ets.jabberworld.info`.
- Read-only: `registered_vhosts`, `check_account`, `check_password`,
  `get_ban_details`, `private_get`, `get_vcard` — доступны.
- Мутирующие команды проверены на одноразовом `eamtest*`:
  `register`, `private_set`, `ban_account`, `get_ban_details`,
  `check_password`, `unban_account`, `change_password`, `kick_user`,
  `send_message`, `unban_ip`, `unregister` — все работают.
- Тестовый аккаунт после проверки удалён. Существующие аккаунты не трогались.

### Окружение

- Debian 12, без sudo.
- `.testenv/php/php` — static PHP 8.4.23 (bulk: GD, SQLite3, curl, openssl,
  intl, dom, mbstring).
- `.testenv/mailpit/mailpit` — Mailpit 1.33.1.
- `pdo_sqlite` отсутствует, поэтому storage использует `SQLite3` напрямую.

### Реализовано

- Каркас: `public/` (front controller, router, assets), `src/`, `templates/`,
  `lang/`, `config/`, `docs/`, `tests/`.
- Ядро: `Config`, `Request`, `Response`, `Router`, `Session`, `Translator`,
  `View`, `Logger`, `PasswordStrength`, `Validator`, `DisposableEmailChecker`.
- Ejabberd: `Client` с Basic/OAuth, таймаутами, retry и разбором XML/quirk-ответов.
- Storage: `SqliteStorage`, `FileStorage`, `StorageFactory` (HMAC-хеши токенов,
  TTL, single-use, rate-limit).
- Mail: SMTP-клиент (STARTTLS/SSL, AUTH LOGIN) и `mail()`; multipart/alternative.
- Captcha: встроенная GD, математический fallback, reCAPTCHA v2/v3, `none`.
- Сервисы: регистрация + активация, смена email, сброс пароля, удаление аккаунта;
  rollback при ошибках, watchers, welcome-сообщение, лимиты.
- UI: landing, 4 раздела, подтверждения, мультиязычность en/ru/uk/pl,
  переключатель языка, адаптивный CSS, прогрессивное улучшение JS
  (проверка username/email, strength meter, reload captcha).
- Безопасность: POST+CSRF, honeypot, одноразовые токены, экранирование,
  security headers, маскирование email в логах, очистка CR/LF в письмах.
- Документация: `README.md`, `docs/INSTALL.md`, `docs/EJABBERD.md`,
  `docs/CONFIG.md`.
- Вспомогательное: `bin/serve.sh`, `bin/cleanup.php`.
- Shared-хостинг (`public_html` + `_app`):
  - `src/Bootstrap.php` — общий bootstrap, `EAM_APP_DIR`, выбор конфига;
  - `deploy/shared/index.php` и `.htaccess`;
  - `bin/package-shared.sh` — сборка `dist/eam-shared/`;
  - `docs/SHARED_HOSTING.md`;
  - приоритет конфига: `EAM_CONFIG` → `../config.php` → `_app/config/config.php`;
  - `_app` закрыт `.htaccess`, `storage` остаётся внутри `_app`.

### Проверки

- Синтаксис: все PHP-файлы проходят `php -l`.
- Unit: `tests/run.php` — 9 тестов, 0 ошибок.
- Integration: `tests/integration.sh` — fake ejabberd + Mailpit, полный цикл
  регистрация → активация → смена email → сброс пароля → удаление,
  повторное использование токена, CSRF, honeypot, API username, язык.
- Shared layout: `tests/shared-layout.sh` — внешний конфиг выигрывает,
  внутренний используется после удаления внешнего, `_app` закрыт, assets
  доступны, storage внутри `_app`, регистрация и активация работают.
- Real smoke: `tests/real-smoke.sh` против `ets.jabberworld.info` —
  все шаги прошли; создан и удалён только одноразовый `eamtestreal*`.
- Встроенная капча: в полном приложении проверены рендер PNG, ошибка при
  неверном коде, новая картинка после ошибки и `/api/captcha`.
- Первый коммит: `422823d feat: standalone ejabberd account manager`.
- Shared-хостинг: `3e4f8d3 feat: add shared-hosting layout`.

### Известные ограничения

- Web Presence не реализован (не входил в задачу).
- Отдельной админки нет: только `config/config.php`.
- reCAPTCHA проверена на уровне рендера/кода; для реальной проверки нужны
  ключи Google.
- Реальный ejabberd должен быть >= 24.06 с `mod_http_api`, `mod_private`,
  `mod_admin_extra`.
- Письма должны уходить через настроенный SMTP; `mail()` зависит от сервера.

### Статус

- [x] Исследование оригинала и API.
- [x] Проверка тестового сервера.
- [x] Установка тестового окружения.
- [x] Каркас проекта.
- [x] Ядро (конфиг, роутер, i18n, landing).
- [x] Ejabberd client, storage, mailer, captcha.
- [x] Регистрация и активация.
- [x] Смена email.
- [x] Сброс пароля.
- [x] Удаление аккаунта.
- [x] Тесты.
- [x] Документация.
- [x] Shared-хостинг: `_app`, внешний/внутренний `config.php`, упаковка.
