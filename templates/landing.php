<?php
/** @var callable $t */
/** @var array $hosts */
/** @var array $features */
?>
<section class="hero">
    <p class="eyebrow"><?= e($t('landing.eyebrow')) ?></p>
    <h1><?= e($t('landing.welcome')) ?></h1>
    <p class="lead"><?= e($t('landing.intro')) ?></p>
    <?php if ($hosts !== []): ?>
        <div class="host-chips">
            <?php foreach ($hosts as $host): ?>
                <span class="host-chip">@<?= e($host) ?></span>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<section class="section">
    <h2><?= e($t('landing.actions_title')) ?></h2>
    <div class="action-grid">
        <?php
        $actions = [
            ['registration', '/registration', 'landing.action.registration.title', 'landing.action.registration.text', 'primary'],
            ['change_email', '/change-email', 'landing.action.change_email.title', 'landing.action.change_email.text', ''],
            ['reset_password', '/reset-password', 'landing.action.reset_password.title', 'landing.action.reset_password.text', ''],
            ['delete_account', '/delete-account', 'landing.action.delete_account.title', 'landing.action.delete_account.text', ''],
        ];
        ?>
        <?php foreach ($actions as [$key, $path, $titleKey, $textKey, $class]): ?>
            <a class="action-card <?= e($class) ?>" href="<?= e(url($path)) ?>">
                <span class="action-title"><?= e($t($titleKey)) ?></span>
                <span class="action-text"><?= e($t($textKey)) ?></span>
                <span class="action-arrow" aria-hidden="true">→</span>
            </a>
        <?php endforeach; ?>
    </div>
</section>

<?php if ($features !== []): ?>
<section class="section">
    <h2><?= e($t('landing.features_title')) ?></h2>
    <ul class="feature-list">
        <?php foreach ($features as $feature): ?>
            <li><?= e((string) $feature) ?></li>
        <?php endforeach; ?>
    </ul>
</section>
<?php endif; ?>

<section class="section prose">
    <h2><?= e($t('landing.hosts_title')) ?></h2>
    <p><?= e($t('landing.hosts_text')) ?></p>
    <?php if ($hosts !== []): ?>
        <ul class="host-list">
            <?php foreach ($hosts as $host): ?>
                <li><code>@<?= e($host) ?></code></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
