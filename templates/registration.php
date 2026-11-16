<?php
/** @var callable $t */
/** @var bool $showForm */
/** @var null|\Eam\Service\Result $result */
/** @var array $form */
/** @var array $hosts */
/** @var string $captchaHtml */
$errors = $result?->fields ?? [];
$value = static fn (string $key): string => (string) ($form[$key] ?? '');
?>
<div class="page-head">
    <h1><?= e($t('form.registration.title')) ?></h1>
    <p class="lead"><?= e($t('form.registration.lead')) ?></p>
</div>

<?php if ($result !== null && $result->message !== null): ?>
    <div class="alert alert-<?= e($this->alertClass($result->status)) ?>" role="status"><?= e($result->message) ?></div>
<?php endif; ?>

<?php if (!$showForm): ?>
    <p><a class="button" href="<?= e(url('/registration')) ?>"><?= e($t('common.registration_again')) ?></a></p>
<?php else: ?>
<form method="post" action="<?= e(url('/registration')) ?>" class="form-card" data-eam-form data-min-strength="<?= e((string) config('password.min_strength', 3)) ?>">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
    <div class="hp" aria-hidden="true">
        <label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label>
    </div>

    <div class="field<?= isset($errors['username']) ? ' has-error' : '' ?>">
        <label for="username"><?= e($t('common.username')) ?></label>
        <input id="username" type="text" name="username" value="<?= e($value('username')) ?>"
               placeholder="<?= e($t('form.registration.username_placeholder')) ?>"
               autocomplete="username" autocapitalize="none" spellcheck="false"
               required data-check-username>
        <small class="hint"><?= e($t('form.registration.hint.username')) ?></small>
        <small class="field-status" data-status="username"></small>
        <?php if (isset($errors['username'])): ?><small class="field-error"><?= e($errors['username']) ?></small><?php endif; ?>
    </div>

    <div class="field<?= isset($errors['host']) ? ' has-error' : '' ?>">
        <label for="host"><?= e($t('common.host')) ?></label>
        <?php if ($hosts === []): ?>
            <input id="host" type="text" name="host" value="<?= e($value('host')) ?>" required>
        <?php else: ?>
            <select id="host" name="host" required>
                <?php foreach ($hosts as $host): ?>
                    <option value="<?= e($host) ?>" <?= $value('host') === $host ? 'selected' : '' ?>><?= e($host) ?></option>
                <?php endforeach; ?>
            </select>
        <?php endif; ?>
        <?php if (isset($errors['host'])): ?><small class="field-error"><?= e($errors['host']) ?></small><?php endif; ?>
    </div>

    <div class="field<?= isset($errors['password']) ? ' has-error' : '' ?>">
        <label for="password"><?= e($t('common.password')) ?></label>
        <input id="password" type="password" name="password"
               placeholder="<?= e($t('form.registration.password_placeholder')) ?>"
               autocomplete="new-password" required data-password-strength>
        <div class="strength" data-strength aria-live="polite"></div>
        <small class="hint"><?= e($t('form.registration.hint.password')) ?></small>
        <?php if (isset($errors['password'])): ?><small class="field-error"><?= e($errors['password']) ?></small><?php endif; ?>
    </div>

    <div class="field<?= isset($errors['email']) ? ' has-error' : '' ?>">
        <label for="email"><?= e($t('common.email')) ?></label>
        <input id="email" type="email" name="email" value="<?= e($value('email')) ?>"
               placeholder="<?= e($t('form.registration.email_placeholder')) ?>"
               autocomplete="email" required data-validate-email>
        <small class="hint"><?= e($t('form.registration.hint.email')) ?></small>
        <small class="field-status" data-status="email"></small>
        <?php if (isset($errors['email'])): ?><small class="field-error"><?= e($errors['email']) ?></small><?php endif; ?>
    </div>

    <?php if ($captchaHtml !== ''): ?>
        <div class="field<?= isset($errors['captcha']) ? ' has-error' : '' ?>">
            <?= $captchaHtml ?>
            <?php if (isset($errors['captcha'])): ?><small class="field-error"><?= e($errors['captcha']) ?></small><?php endif; ?>
        </div>
    <?php endif; ?>

    <button class="button" type="submit"><?= e($t('form.registration.submit')) ?></button>
</form>
<?php endif; ?>
