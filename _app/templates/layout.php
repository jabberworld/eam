<?php
/** @var callable $t */
/** @var string $title */
/** @var string $content */
/** @var string $active */
/** @var array $flash */
/** @var string $returnTo */
/** @var array $locales */
$siteName = (string) config('app.name', 'Jabber');
$basePath = (string) (parse_url(url('/'), PHP_URL_PATH) ?: '');
$basePath = rtrim($basePath, '/');
$nav = [
    'home' => ['/','nav.home'],
    'registration' => ['/registration','nav.registration'],
    'change_email' => ['/change-email','nav.change_email'],
    'reset_password' => ['/reset-password','nav.reset_password'],
    'delete_account' => ['/delete-account','nav.delete_account'],
];
?>
<!doctype html>
<html lang="<?= e($locale) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($title) ?><?= $title !== $siteName ? ' — ' . e($siteName) : '' ?></title>
    <link rel="stylesheet" href="<?= e(asset('css/style.css')) ?>">
</head>
<body>
<header class="site-header">
    <div class="container header-inner">
        <a class="brand" href="<?= e(url('/')) ?>">
            <span class="brand-mark">J</span>
            <span><?= e($siteName) ?></span>
        </a>
        <nav class="main-nav" aria-label="<?= e($t('nav.menu')) ?>">
            <?php foreach ($nav as $key => [$path, $label]): ?>
                <a href="<?= e(url($path)) ?>" class="<?= $active === $key ? 'active' : '' ?>">
                    <?= e($t($label)) ?>
                </a>
            <?php endforeach; ?>
        </nav>
        <div class="lang-switch" aria-label="<?= e($t('nav.language')) ?>">
            <?php foreach ($locales as $code): ?>
                <a href="<?= e(url('/language', ['to' => $code, 'return' => $returnTo])) ?>"
                   class="<?= $locale === $code ? 'active' : '' ?>"
                   hreflang="<?= e($code) ?>"><?= e(strtoupper($code)) ?></a>
            <?php endforeach; ?>
        </div>
    </div>
</header>

<main class="container main">
    <?php foreach (['success', 'error', 'warning', 'info'] as $type): ?>
        <?php if (!empty($flash[$type])): ?>
            <div class="alert alert-<?= e($type) ?>" role="status"><?= e($flash[$type]) ?></div>
        <?php endif; ?>
    <?php endforeach; ?>
    <?= $content ?>
</main>

<footer class="site-footer">
    <div class="container footer-inner">
        <?= $footerHtml ?>
    </div>
</footer>
<script>window.EAM_I18N = <?= json_encode([
    'checking' => $t('js.checking'),
    'password_strength_0' => $t('hint.password_strength.0'),
    'password_strength_1' => $t('hint.password_strength.1'),
    'password_strength_2' => $t('hint.password_strength.2'),
    'password_strength_3' => $t('hint.password_strength.3'),
    'password_strength_4' => $t('hint.password_strength.4'),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
window.EAM_BASE_PATH = <?= json_encode($basePath, JSON_UNESCAPED_SLASHES) ?>;</script>
<script src="<?= e(asset('js/app.js')) ?>" defer></script>
</body>
</html>
