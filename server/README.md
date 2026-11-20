# Запуск вне shared-хостинга

Корень проекта одновременно является document root. Отдельного `public/` нет.

## Требования

- PHP 8.2+ (`curl`, `openssl`, `dom`, `session`, `json`; желательны `mbstring`, `sqlite3`, `gd`).
- Apache + `mod_rewrite` или Nginx.
- PHP-FPM (рекомендуется).
- ejabberd >= 24.06 с `mod_http_api`, `mod_private`, `mod_admin_extra`.
- SMTP или `mail()`.

## Конфиг

```bash
cp _app/config/config.example.php _app/config/config.php
chmod -R u+rwX _app/storage
```

Внешний вариант — файл `config.php` на уровень выше корня проекта; он имеет
приоритет. Также можно указать абсолютный путь через `EAM_CONFIG`.

`app.base_url` должен включать префикс, если сайт открывается не из корня:

```php
'base_url' => 'https://example.com/eam',
```

## Apache

Скопируйте и адаптируйте `server/apache.conf.example`:

- `DocumentRoot /path/to/eam`;
- `AllowOverride All` для этого каталога.

Затем:

```bash
sudo a2enmod rewrite
sudo systemctl reload apache2
```

Для подкаталога в `.htaccess` добавьте:

```apache
RewriteBase /eam/
```

## Nginx

Скопируйте и адаптируйте `server/nginx.conf.example`. Важно закрыть:

- `/_app/`
- `/server/`
- `/tests/`
- `/docs/`
- скрытые файлы.

## Права

Запись нужна только в `_app/storage/`:

```bash
chown -R www-data:www-data _app/storage
chmod -R 750 _app/storage
```

## Dev-сервер

```bash
PHP_BIN=php server/serve.sh
# http://127.0.0.1:8080
```

Или:

```bash
php -S 127.0.0.1:8080 server/router.php
```

`server/router.php` сам закрывает `_app`, `server`, `docs`, `tests` и скрытые
файлы.

## Cron

Пример в `server/cron.example`:

```cron
*/15 * * * * cd /path/to/eam && php server/cleanup.php >/dev/null 2>&1
```

Если используется внешний конфиг:

```bash
EAM_CONFIG=/path/to/config.php php server/cleanup.php
```

## Проверка

```bash
curl -I https://example.com/registration
curl -I https://example.com/assets/css/style.css
curl -I https://example.com/_app/config/config.php     # 403/404
curl -I https://example.com/_app/storage/eam.sqlite    # 403/404
curl -I https://example.com/server/README.md           # 403/404
curl -I https://example.com/docs/INSTALL.md            # 403/404
curl -I https://example.com/tests/config.test.php      # 403/404
```

## Обновление

1. Сохранить `_app/config/config.php` (или внешний `config.php`) и `_app/storage/`.
2. Заменить `index.php`, `.htaccess`, `assets/`, `_app/src/`, `_app/templates/`,
   `_app/lang/`, `_app/config/config.example.php`, `server/`, `docs/`, `tests/`.
3. Проверить `/health` и разделы сайта.
4. При обновлении CSS/JS увеличить `app.asset_version`.
