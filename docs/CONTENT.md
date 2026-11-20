# Контент landing и футера

Landing и футер редактируются HTML-файлами в `_app/content/`.
Файлы лежат внутри `_app`, поэтому недоступны по HTTP.

## Файлы

```text
_app/content/
  landing/
    ru.html
    en.html
    uk.html
    pl.html
  footer/
    ru.html
    en.html
    uk.html
    pl.html
```

- `landing/*.html` — полный HTML тела главной страницы. Вставляется внутрь
  `<main class="container main">`.
- `footer/*.html` — внутренний HTML футера. Вставляется внутрь
  `<div class="container footer-inner">`.
- Header, навигация, селектор языка, `<main>` и `<footer>` остаются в
  `_app/templates/layout.php`.

## Placeholders

| Placeholder | Значение |
|---|---|
| `{{site_name}}` | `app.name` |
| `{{hosts_chips}}` | `<span class="host-chip">домен</span>` для каждого домена |
| `{{hosts_list}}` | `<li><code>домен</code></li>` для каждого домена |
| `{{url_registration}}` | URL регистрации |
| `{{url_change_email}}` | URL смены email |
| `{{url_reset_password}}` | URL сброса пароля |
| `{{url_delete_account}}` | URL удаления |
| `{{year}}` | текущий год |

Домены берутся из `registration.hosts` в конфиге. Простые значения
экранируются; `hosts_chips` и `hosts_list` — заранее сгенерированный
безопасный HTML.

## Пример

`_app/content/landing/ru.html`:

```html
<section class="hero">
    <p class="eyebrow">Jabber / XMPP</p>
    <h1>Добро пожаловать!</h1>
    <p class="lead">...</p>
    <div class="host-chips">{{hosts_chips}}</div>
</section>

<section class="section prose">
    <h2>Доступные домены</h2>
    <ul class="host-list">{{hosts_list}}</ul>
</section>

<a href="{{url_registration}}">Регистрация</a>
```

`_app/content/footer/ru.html`:

```html
<p>Управление аккаунтом для {{site_name}}.</p>
<p class="muted">Ссылки подтверждения одноразовые и ограничены по времени.</p>
```

## Язык и fallback

Сначала выбирается файл текущей локали. Если его нет — файл
`app.default_locale`, затем `en`. Если нет и `en`, блок остаётся пустым,
а в лог пишется warning.

## Безопасность

- HTML-файлы — доверенные, как шаблоны. Не выводите в них пользовательский
  ввод.
- Placeholders экранируются. `hosts_*` генерируются с экранированием.
- Файлы не исполняются как PHP; это обычный HTML.
- `_app/content` закрыт так же, как весь `_app`.

## Название сайта

`app.name` — единый источник названия для header, `<title>`, писем,
XMPP `{site}` и `{{site_name}}`. Если `mail.from_name` пуст, для писем
используется `app.name`.
