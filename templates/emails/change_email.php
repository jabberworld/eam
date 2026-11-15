<?php
/** @var callable $t */
/** @var string $name */
/** @var string $jid */
/** @var string $email */
/** @var string $link */
/** @var int $ttl_minutes */
?>
<p style="margin:0 0 14px;font-size:16px;"><?= e($t('email.greeting', [':name' => $name])) ?></p>
<p style="margin:0 0 14px;font-size:15px;line-height:1.6;">
    <?= e($t('email.change_email.intro', [':jid' => $jid, ':email' => $email])) ?>
</p>
<p style="margin:22px 0;">
    <a href="<?= e($link) ?>" style="display:inline-block;background:#2f6f4e;color:#ffffff;text-decoration:none;padding:12px 20px;border-radius:8px;font-weight:600;">
        <?= e($t('email.change_email.action')) ?>
    </a>
</p>
<p style="margin:0 0 14px;font-size:13px;line-height:1.6;color:#4f5b53;">
    <?= e($t('email.link_hint')) ?><br>
    <a href="<?= e($link) ?>" style="color:#2f6f4e;word-break:break-all;"><?= e($link) ?></a>
</p>
<p style="margin:0 0 14px;font-size:13px;color:#6b756e;">
    <?= e($t('email.expires', [':minutes' => $ttl_minutes])) ?>
</p>
<p style="margin:0;font-size:13px;line-height:1.6;color:#6b756e;">
    <?= e($t('email.change_email.ignore')) ?>
</p>
