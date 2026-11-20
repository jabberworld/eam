<?php
/**
 * Пример конфигурации EAM.
 * Скопируйте в _app/config/config.php и заполните секреты.
 * _app/config/config.php исключён из git.
 *
 * Можно вынести этот файл на уровень выше корня проекта как config.php:
 * пути storage ниже используют EAM_APP_DIR и не зависят от расположения.
 */

$appDir = defined('EAM_APP_DIR') ? EAM_APP_DIR : dirname(__DIR__);

return [
    'app' => [
        // Единое название сайта: header, title, письма, footer.
        'name' => 'Jabber Account Manager',
        'base_url' => 'https://example.com',
        'default_locale' => 'ru',
        'locales' => ['en', 'ru', 'uk', 'pl'],
        'timezone' => 'UTC',
        // Сгенерируйте один раз и вставьте сюда (любой вариант):
        //   openssl rand -hex 32
        //   php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
        //   head -c 32 /dev/urandom | base64
        //
        // Минимум 16 символов; рекомендуется 32 байта (64 hex-символа).
        'secret' => 'CHANGE_ME_RANDOM_SECRET_AT_LEAST_32_CHARS',
        // Меняйте при обновлении CSS/JS, чтобы сбросить кеш браузера.
        'asset_version' => '1',
        'session_cookie' => 'eam_session',
        // null — автоопределение по HTTPS.
        'session_secure' => null,
        'session_samesite' => 'Lax',
        'debug' => false,
    ],

    'ejabberd' => [
        // URL из request_handlers: /api: mod_http_api
        'rest_url' => 'https://127.0.0.1:5285/api',
        // Basic Auth. Можно оставить login/password пустыми и задать oauth_token.
        'login' => 'bot@example.com',
        'password' => '',
        'oauth_token' => '',
        'verify_tls' => true,
        'timeout' => 15,
        'retries' => 1,
    ],

    'registration' => [
        'enabled' => true,
        'hosts' => ['example.com'],
        'two_step' => true,
        'activation_ttl' => 3600,
        'rate_limit' => ['count' => 3, 'window' => 3600],
        'allowed_username_regexp' => '^(?=^[a-z0-9].*)(?=.*[a-z0-9]$)(?!.*([_.-])\1+.*)(?!^([0-9]+)$)[a-z0-9_.-]{3,30}$',
        'blocked_username_regexp' => '^(.*(admin|blog|bot|contact|e-mail|ejabberd|email|ftp|hostmaster|http|https|imap|info|jabber|login|mail|office|owner|pop3|postmaster|root|smtp|ssh|support|team|webmaster|xmpp).*)$',
        'welcome' => [
            'enabled' => false,
            'subject' => '',
            'body' => 'Добро пожаловать, {jid}!',
        ],
        'watchers' => [],
        'watch' => [
            'registration' => false,
            'activation' => false,
        ],
    ],

    'change_email' => [
        'enabled' => true,
        'token_ttl' => 900,
        'rate_limit' => ['count' => 10, 'window' => 3600],
        'watch' => false,
    ],

    'password_reset' => [
        'enabled' => true,
        'token_ttl' => 900,
        'rate_limit' => ['count' => 10, 'window' => 3600],
        'limit_count' => 4,
        'limit_window' => 43200,
        'unban_ip' => true,
        'watch' => false,
    ],

    'delete_account' => [
        'enabled' => true,
        'token_ttl' => 900,
        'rate_limit' => ['count' => 5, 'window' => 3600],
        'watch' => false,
    ],

    'mail' => [
        // smtp | mail
        'transport' => 'smtp',
        'from_email' => 'noreply@example.com',
        // Пусто — использовать app.name.
        'from_name' => '',
        'smtp' => [
            'host' => '127.0.0.1',
            'port' => 1025,
            // none | tls | ssl
            'encryption' => 'none',
            'username' => '',
            'password' => '',
            'verify_peer' => true,
            'timeout' => 10,
        ],
    ],

    'captcha' => [
        // builtin | recaptcha | none
        'provider' => 'builtin',
        'builtin' => [
            'length' => 5,
            'width' => 150,
            'height' => 40,
            'ttl' => 600,
        ],
        'recaptcha' => [
            // v2 (checkbox) | v3
            'version' => 'v2',
            'site_key' => '',
            'secret_key' => '',
            'v3_threshold' => 0.5,
            'v3_action' => 'eam',
        ],
    ],

    'validation' => [
        'check_mx' => true,
        'block_disposable' => true,
        'usercheck_url' => 'https://api.usercheck.com/domain/',
        'timeout' => 5,
        'cache_ttl' => 86400,
    ],

    'password' => [
        'min_length' => 8,
        'max_length' => 128,
        // 0..4, где 3 — «хороший», 4 — «сильный».
        'min_strength' => 3,
    ],

    'security' => [
        'honeypot' => true,
        // IP прокси, которым доверяем X-Forwarded-For.
        'trusted_proxies' => [],
    ],

    'storage' => [
        // auto | sqlite | files
        'driver' => 'auto',
        'sqlite_path' => $appDir . '/storage/eam.sqlite',
        'files_path' => $appDir . '/storage/data',
        'sessions_path' => $appDir . '/storage/sessions',
    ],

    'logging' => [
        'enabled' => true,
        'path' => $appDir . '/storage/logs/app.log',
        'level' => 'warning',
    ],
];
