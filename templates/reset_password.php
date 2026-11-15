<?php
/** @var callable $t */
/** @var string $mode */
/** @var null|\Eam\Service\Result $result */
/** @var string $token */
/** @var string $user */
/** @var string $host */
/** @var array $form */
/** @var string $captchaHtml */
$errors = $result?->fields ?? [];
$value = static fn (string $key): string => (string) ($form[$key] ?? '');
?>
<div class="page-head">
    <h1><?= e($t('form.reset_password.title')) ?></h1>
    <p class="lead"><?= e($t('form.reset_password.lead')) ?></p>
</div>

<?php if ($result !== null && $result->message !== null): ?>
    <div class="alert alert-<?= e($this->alertClass($result->status)) ?>" role="status"><?= e($result->message) ?></div>
<?php endif; ?>

<?php if ($mode === 'confirm'): ?>
    <form method="post" action="<?= e(url('/reset-password')) ?>" class="form-card" data-eam-form data-min-strength="<?= e((string) config('password.min_strength', 3)) ?>">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="action" value="confirm">
        <input type="hidden" name="code" value="<?= e($token) ?>">
        <p><?= e($t('confirm.reset_password.text', [':jid' => $user . '@' . $host])) ?></p>
        <div class="field<?= isset($errors['password']) ? ' has-error' : '' ?>">
            <label for="password"><?= e($t('common.new_password')) ?></label>
            <input id="password" type="password" name="password"
                   placeholder="<?= e($t('form.reset_password.password_placeholder')) ?>"
                   autocomplete="new-password" required data-password-strength>
            <div class="strength" data-strength aria-live="polite"></div>
            <?php if (isset($errors['password'])): ?><small class="field-error"><?= e($errors['password']) ?></small><?php endif; ?>
        </div>
        <button class="button" type="submit"><?= e($t('confirm.reset_password.submit')) ?></button>
    </form>
<?php else: ?>
<form method="post" action="<?= e(url('/reset-password')) ?>" class="form-card" data-eam-form>
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
    <div class="hp" aria-hidden="true">
        <label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label>
    </div>

    <div class="field<?= isset($errors['login']) ? ' has-error' : '' ?>">
        <label for="login"><?= e($t('common.full_jid')) ?></label>
        <input id="login" type="text" name="login" value="<?= e($value('login')) ?>"
               placeholder="<?= e($t('form.reset_password.login_placeholder')) ?>"
               autocomplete="username" autocapitalize="none" spellcheck="false" required>
        <small class="hint"><?= e($t('form.reset_password.hint.login')) ?></small>
        <?php if (isset($errors['login'])): ?><small class="field-error"><?= e($errors['login']) ?></small><?php endif; ?>
    </div>

    <?php if ($captchaHtml !== ''): ?>
        <div class="field<?= isset($errors['captcha']) ? ' has-error' : '' ?>">
            <?= $captchaHtml ?>
            <?php if (isset($errors['captcha'])): ?><small class="field-error"><?= e($errors['captcha']) ?></small><?php endif; ?>
        </div>
    <?php endif; ?>

    <button class="button" type="submit"><?= e($t('form.reset_password.submit')) ?></button>
</form>
<?php endif; ?>
