# Настройка ejabberd

EAM работает через **mod_http_api** и требует ejabberd **>= 24.06**.
Для двухэтапной регистрации нужен `mod_private`: приватный email хранится в
XEP-0049 private storage как `<private xmlns='email'>...</private>`, а
`ban_account`/`unban_account` используют private storage для бана.

## Модули

В `modules` должны быть включены:

```yaml
modules:
  mod_http_api: {}
  mod_private: {}
  mod_admin_extra: {}
  mod_register: {}
```

`mod_admin_extra` предоставляет `check_account`, `check_password`,
`ban_account`, `unban_account`, `get_ban_details`, `change_password`,
`unregister`, `kick_user`, `send_message`, `get_vcard` и другие команды.
`mod_register` предоставляет `register`.

## HTTP listener

```yaml
listen:
  -
    port: 7283
    ip: "::"
    module: ejabberd_http
    tls: true
    request_handlers:
      /api: mod_http_api
```

URL для `ejabberd.rest_url` в конфиге EAM:

```text
https://ets.example.com:7283/api
```

Если TLS терминируется на reverse proxy, listener может быть HTTP на
`127.0.0.1`, а наружу — HTTPS через Nginx.

## Права API

Создайте отдельный аккаунт бота (например, `eam@ets.example.com`) и выдайте
ему только нужные команды:

```yaml
acl:
  eam_bot:
    user: "eam@ets.example.com"

api_permissions:
  "eam web":
    from:
      - mod_http_api
    who:
      acl: eam_bot
    what:
      - register
      - unregister
      - change_password
      - check_account
      - check_password
      - registered_vhosts
      - private_get
      - private_set
      - ban_account
      - unban_account
      - get_ban_details
      - kick_user
      - unban_ip
      - send_message
      - get_vcard
```

Не выдавайте `stop`, `start`, `restart` и другие опасные команды.
Если EAM и ejabberd на одной машине, можно ограничить доступ по IP
(`who: ip: 127.0.0.1/8`) вместо Basic Auth, но для внешнего веб-сервера
используйте отдельного бота и HTTPS.

## Basic Auth

В `_app/config/config.php` (или внешнем `../config.php`):

```php
'ejabberd' => [
    'rest_url' => 'https://ets.example.com:7283/api',
    'login' => 'eam@ets.example.com',
    'password' => '...',
    'oauth_token' => '',
    'verify_tls' => true,
    'timeout' => 15,
    'retries' => 1,
],
```

Проверка:

```bash
curl -u 'eam@ets.example.com:PASSWORD' \
  -H 'Content-Type: application/json' \
  -d '{}' \
  'https://ets.example.com:7283/api/registered_vhosts'
```

Ожидаемый ответ: JSON-массив vhosts.

## OAuth

Вместо Basic Auth можно выпустить долгоживущий токен:

```bash
ejabberdctl oauth_issue_token eam@ets.example.com 315360000 ejabberd:admin
```

и указать его в `ejabberd.oauth_token`. Поле `login`/`password` тогда можно
оставить пустым.

## Двухэтапная регистрация

Последовательность EAM:

1. `register` — создаёт аккаунт с паролем пользователя.
2. `private_set` — сохраняет приватный email.
3. `ban_account(reason="Activation required")` — блокирует вход.
4. Письмо со ссылкой `/registration?code=...`.
5. `unban_account` — снимает бан.

В ejabberd 24.06–25.07 `ban_account` меняет пароль на случайный, а
`unban_account` возвращает прежний. В 25.08+ `ban_account` больше не меняет
пароль, `unban_account` просто снимает бан. Обе схемы поддерживаются.

## Проверка команд

```bash
API='https://ets.example.com:7283/api'
AUTH='eam@ets.example.com:PASSWORD'

curl -u "$AUTH" -H 'Content-Type: application/json' -d '{"user":"test","host":"ets.example.com"}' "$API/check_account"
curl -u "$AUTH" -H 'Content-Type: application/json' -d '{"user":"test","host":"ets.example.com","element":"private","ns":"email"}' "$API/private_get"
curl -u "$AUTH" -H 'Content-Type: application/json' -d '{"user":"test","host":"ets.example.com"}' "$API/get_ban_details"
```

Ожидаемые особенности ответов:

- `check_account`: `0` — существует, `1` — нет.
- `check_password`: `0` — верный, `1` — неверный.
- `get_ban_details`: `{}` — не забанен; объект с `reason` — забанен.
- `private_get`: JSON-строка с XML
  `<query xmlns='jabber:iq:private'><private xmlns='email'>...</private></query>`.
- `get_vcard`: HTTP 400 `"error_no_vcard_found"`, если vCard нет.

## Безопасность

- Используйте HTTPS для API и для сайта.
- Не используйте основного администратора ejabberd; создайте отдельного бота.
- Ограничьте `api_permissions` только перечисленными командами.
- Не публикуйте `_app/config/config.php`; внешний `../config.php` держите вне webroot.
- Не логируйте пароли и OAuth-токены.
