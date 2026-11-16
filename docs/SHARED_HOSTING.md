# Shared-хостинг: раскладка `public_html` + `_app`

Этот режим нужен, когда домен смотрит на `public_html`, а менять document root
неудобно или нельзя. В webroot лежит front controller и публичные assets,
остальное — в закрытом каталоге `_app`.

> **Обязательное условие:** Apache должен обрабатывать `.htaccess` и `mod_rewrite`.
> Если `.htaccess` игнорируется, каталог `_app` окажется доступен по HTTP.
> В этом случае используйте стандартную раскладку с document root = `public/`.

## Раскладка

```text
/home/user/
  config.php                  # необязательный внешний конфиг (приоритетный)
  public_html/                # webroot
    index.php                 # front controller
    .htaccess
    assets/
      css/style.css
      js/app.js
    _app/                     # закрыт .htaccess
      src/
      templates/
      lang/
      storage/
      config/
        config.example.php
        config.php            # необязательный внутренний конфиг
```

## Конфиг

Приоритет:

1. `EAM_CONFIG` — абсолютный путь из окружения;
2. `../config.php` — внешний файл на уровень выше `public_html`;
3. `_app/config/config.php` — внутренний файл.

Внешний конфиг выигрывает. Поэтому внутренний файл можно просто переместить:

```bash
mv public_html/_app/config/config.php ../config.php
```

Используйте `EAM_APP_DIR` для путей, чтобы конфиг не зависел от расположения:

```php
'storage' => [
    'sqlite_path'   => EAM_APP_DIR . '/storage/eam.sqlite',
    'files_path'    => EAM_APP_DIR . '/storage/data',
    'sessions_path' => EAM_APP_DIR . '/storage/sessions',
],
```

`EAM_APP_DIR` определяется bootstrap как `public_html/_app`.
`open_basedir` должен включать `public_html/_app`, а если используется внешний
конфиг — и каталог, где он лежит (например, `/home/user`).

## Apache

`public_html/.htaccess`:

```apache
Options -Indexes -MultiViews
DirectoryIndex index.php

<IfModule mod_rewrite.c>
    RewriteEngine On

    # Для установки в подкаталоге раскомментируйте и укажите путь:
    # RewriteBase /eam/

    RewriteRule ^_app(/|$) - [F,L,NC]
    RewriteRule (^|/)\. - [F,L]
    RewriteRule \.(sqlite|db|log|bak|old|zip|tar|gz|env)$ - [F,L,NC]

    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteRule ^ index.php [QSA,L]
</IfModule>
```

Нужно, чтобы `AllowOverride` разрешал `FileInfo` (rewrite), а для
`Options -Indexes -MultiViews` — `Options`. Если провайдер не разрешает
`Options`, уберите эту строку, но оставьте deny для `_app`.

Если `mod_rewrite` недоступен, но доступен `mod_alias`, можно добавить
`RedirectMatch 403 ^/_app(/|$)`; однако чистые URL всё равно потребуют
rewrite/try_files.

### Установка в подкаталоге

Если сайт открывается как `https://example.com/eam`, нужно:

1. В `public_html/.htaccess` добавить:
   ```apache
   RewriteBase /eam/
   ```
   или собрать пакет сразу с базовым путём:
   ```bash
   BASE_PATH=/eam bin/package-shared.sh
   ```
2. В конфиге указать:
   ```php
   'base_url' => 'https://example.com/eam',
   ```
   Не `https://example.com` и не `http://localhost`, иначе ссылки и разбор
   пути будут неверными.
3. Проверить:
   ```bash
   curl -I https://example.com/eam/registration
   curl -I https://example.com/eam/assets/css/style.css
   curl -I https://example.com/eam/_app/config/config.php
   ```

## Nginx

```nginx
server {
    root /home/user/public_html;
    index index.php;

    location ^~ /_app/ {
        deny all;
    }

    location ~ /\. {
        deny all;
    }

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }
}
```

## Права

```bash
chmod -R u+rwX public_html/_app/storage
```

`storage/` остаётся внутри `_app`; доступ по HTTP к нему закрыт тем же
правилом, что и ко всему `_app`. Если хостинг позволяет, дополнительно
ограничьте права:

```bash
chmod 700 public_html/_app
chmod 600 ../config.php
```

## Проверка после установки

```bash
curl -I https://example.com/registration             # 200
curl -I https://example.com/assets/css/style.css     # 200
curl -I https://example.com/_app/config/config.php   # 403/404
curl -I https://example.com/_app/storage/eam.sqlite  # 403/404
curl -I https://example.com/_app/src/App.php         # 403/404
```

Если хотя бы один `_app`-URL отдаёт `200`, немедленно закройте доступ:
проверьте `AllowOverride`, `mod_rewrite` и расположение `.htaccess`.
В `_app/config` и `_app/storage` могут быть секреты и пользовательские данные.

## 404 на подразделах

Главная страница работает через `DirectoryIndex`, даже если rewrite выключен.
Если `/registration` отдаёт **404 Apache** (`Content-Type: text/html; charset=iso-8859-1`,
нет заголовков приложения), запрос не доходит до `index.php`.

Проверьте по порядку:

1. `mod_rewrite` включён:
   ```bash
   apache2ctl -M | grep rewrite
   ```
   Если пусто:
   ```bash
   sudo a2enmod rewrite
   sudo systemctl reload apache2
   ```
2. `.htaccess` действительно лежит в webroot и читается Apache:
   ```bash
   ls -la public_html
   tail -n 50 /var/log/apache2/error.log
   ```
3. `AllowOverride` разрешает `FileInfo` и `Options`.
4. Для подкаталога задан `RewriteBase /eam/`.
5. `app.base_url` включает подкаталог: `https://example.com/eam`.

Если после rewrite приходит уже **404 приложения** (стилизованная страница,
заголовки CSP), значит rewrite работает, но `base_url` не содержит `/eam`
или приложение установлено не туда, куда указывает URL.

JS использует `window.EAM_BASE_PATH`, вычисленный из `base_url`, для запросов
`/api/captcha`, `/api/check-username` и `/api/validate-email`. Если `base_url`
не содержит подкаталог, кнопка «Обновить код» и живая проверка полей будут
обращаться не туда. После изменения `base_url` обновите страницу с очисткой
кеша; версия assets задаётся в `app.asset_version`.

## Сборка пакета

```bash
bin/package-shared.sh
```

Для установки в подкаталог:

```bash
BASE_PATH=/eam bin/package-shared.sh
```

Скрипт добавит в `.htaccess`:

```apache
RewriteBase /eam/
```

Результат:

```text
dist/eam-shared/
  public_html/
    index.php
    .htaccess
    assets/
    _app/
      src/ templates/ lang/ storage/ config/
  config.example.php
  README-SHARED.txt
  docs/
  README.md
  LICENSE
```

В пакет не попадают `tests/`, `.testenv/`, `config/config.php` и рабочий
`storage/`.

## Обновление

1. Сохраните внешний `config.php` (или `_app/config/config.php`) и `_app/storage/`.
2. Замените `_app/src/`, `_app/templates/`, `_app/lang/`, `assets/`,
   `index.php`, `.htaccess`.
3. Проверьте `/health` и разделы сайта.
4. Не копируйте `config.example.php` поверх рабочего `config.php`.

## Отличия от стандартной раскладки

- Стандартная: document root = `public/`, код лежит рядом с `public/`.
- Shared: document root = `public_html/`, код в `public_html/_app/`, закрыт
  `.htaccess`; конфиг может быть снаружи.
- Оба режима используют один и тот же `Eam\Bootstrap` и `Eam\App`.
