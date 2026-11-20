<?php
/** @var callable $t */
/** @var string $siteName */
/** @var string $content */
?>
<!doctype html>
<html lang="<?= e($locale) ?>">
<head>
    <meta charset="utf-8">
    <title><?= e($siteName) ?></title>
</head>
<body style="margin:0;padding:0;background:#f4f6f3;color:#1e2a23;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif;">
<div style="max-width:600px;margin:0 auto;padding:24px;">
    <div style="background:#ffffff;border:1px solid #dfe5dd;border-radius:12px;padding:28px;">
        <?= $content ?>
    </div>
    <p style="margin:18px 4px 0;font-size:12px;line-height:1.5;color:#6b756e;">
        <?= e($t('email.footer', [':site' => $siteName])) ?>
    </p>
</div>
</body>
</html>
