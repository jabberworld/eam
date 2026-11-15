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
Options -Indexes
DirectoryIndex index.php

<IfModule mod_rewrite.c>
    RewriteEngine On

    RewriteRule ^_app(/|$) - [F,L,NC]
    RewriteRule (^|/)\. - [F,L]
    RewriteRule \.(sqlite|db|log|bak|old|zip|tar|gz|env)$ - [F,L,NC]

    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteRule ^ index.php [QSA,L]
</IfModule>
```

Нужно, чтобы `AllowOverride` разрешал `FileInfo` (rewrite), а для
`Options -Indexes` — `Options`. Если провайдер не разрешает `Options`,
уберите эту строку, но оставьте deny для `_app`.

Если `mod_rewrite` недоступен, но доступен `mod_alias`, можно добавить
`RedirectMatch 403 ^/_app(/|$)`; однако чистые URL всё равно потребуют
rewrite/try_files.

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

## Сборка пакета

```bash
bin/package-shared.sh
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
