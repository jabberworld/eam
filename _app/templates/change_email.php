<?php
/** @var callable $t */
/** @var string $mode */
/** @var null|\Eam\Service\Result $result */
/** @var string $token */
/** @var string $user */
/** @var string $host */
/** @var string $email */
/** @var array $form */
/** @var string $captchaHtml */
$errors = $result?->fields ?? [];
$value = static fn (string $key): string => (string) ($form[$key] ?? '');
?>
<div class="page-head">
    <h1><?= e($t('form.change_email.title')) ?></h1>
    <p class="lead"><?= e($t('form.change_email.lead')) ?></p>
</div>

<?php if ($result !== null && $result->message !== null): ?>
    <div class="alert alert-<?= e($this->alertClass($result->status)) ?>" role="status"><?= e($result->message) ?></div>
<?php endif; ?>

<?php if ($mode === 'confirm'): ?>
    <div class="form-card">
        <p class="confirm-text"><?= e($t('confirm.change_email.text', [':jid' => $user . '@' . $host, ':email' => $email])) ?></p>
        <form method="post" action="<?= e(url('/change-email')) ?>">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" value="confirm">
            <input type="hidden" name="code" value="<?= e($token) ?>">
            <button class="button" type="submit"><?= e($t('confirm.change_email.submit')) ?></button>
        </form>
        <p><a href="<?= e(url('/change-email')) ?>"><?= e($t('common.cancel')) ?></a></p>
    </div>
<?php else: ?>
<form method="post" action="<?= e(url('/change-email')) ?>" class="form-card" data-eam-form>
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
    <div class="hp" aria-hidden="true">
        <label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label>
    </div>

    <div class="field<?= isset($errors['login']) ? ' has-error' : '' ?>">
        <label for="login"><?= e($t('common.full_jid')) ?></label>
        <input id="login" type="text" name="login" value="<?= e($value('login')) ?>"
               placeholder="<?= e($t('form.change_email.login_placeholder')) ?>"
               autocomplete="username" autocapitalize="none" spellcheck="false" required>
        <small class="hint"><?= e($t('form.change_email.hint.login')) ?></small>
        <?php if (isset($errors['login'])): ?><small class="field-error"><?= e($errors['login']) ?></small><?php endif; ?>
    </div>

    <div class="field<?= isset($errors['password']) ? ' has-error' : '' ?>">
        <label for="password"><?= e($t('common.password')) ?></label>
        <input id="password" type="password" name="password"
               placeholder="<?= e($t('form.change_email.password_placeholder')) ?>"
               autocomplete="current-password" required>
        <?php if (isset($errors['password'])): ?><small class="field-error"><?= e($errors['password']) ?></small><?php endif; ?>
    </div>

    <div class="field<?= isset($errors['email']) ? ' has-error' : '' ?>">
        <label for="email"><?= e($t('common.new_email')) ?></label>
        <input id="email" type="email" name="email" value="<?= e($value('email')) ?>"
               placeholder="<?= e($t('form.change_email.email_placeholder')) ?>"
               autocomplete="email" required data-validate-email>
        <small class="field-status" data-status="email"></small>
        <?php if (isset($errors['email'])): ?><small class="field-error"><?= e($errors['email']) ?></small><?php endif; ?>
    </div>

    <?php if ($captchaHtml !== ''): ?>
        <div class="field<?= isset($errors['captcha']) ? ' has-error' : '' ?>">
            <?= $captchaHtml ?>
            <?php if (isset($errors['captcha'])): ?><small class="field-error"><?= e($errors['captcha']) ?></small><?php endif; ?>
        </div>
    <?php endif; ?>

    <button class="button" type="submit"><?= e($t('form.change_email.submit')) ?></button>
</form>
<?php endif; ?>
