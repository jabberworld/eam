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
    <h1><?= e($t('form.delete_account.title')) ?></h1>
    <p class="lead"><?= e($t('form.delete_account.lead')) ?></p>
</div>

<?php if ($result !== null && $result->message !== null): ?>
    <div class="alert alert-<?= e($this->alertClass($result->status)) ?>" role="status"><?= e($result->message) ?></div>
<?php endif; ?>

<div class="alert alert-error"><?= e($t('form.delete_account.warning')) ?></div>

<?php if ($mode === 'confirm'): ?>
    <form method="post" action="<?= e(url('/delete-account')) ?>" class="form-card">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="action" value="confirm">
        <input type="hidden" name="code" value="<?= e($token) ?>">
        <p><?= e($t('confirm.delete_account.text', [':jid' => $user . '@' . $host])) ?></p>
        <div class="field<?= isset($errors['password']) ? ' has-error' : '' ?>">
            <label for="password"><?= e($t('common.password')) ?></label>
            <input id="password" type="password" name="password"
                   placeholder="<?= e($t('form.delete_account.password_placeholder')) ?>"
                   autocomplete="current-password" required>
            <?php if (isset($errors['password'])): ?><small class="field-error"><?= e($errors['password']) ?></small><?php endif; ?>
        </div>
        <button class="button button-danger" type="submit"><?= e($t('confirm.delete_account.submit')) ?></button>
    </form>
<?php else: ?>
<form method="post" action="<?= e(url('/delete-account')) ?>" class="form-card" data-eam-form>
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
    <div class="hp" aria-hidden="true">
        <label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label>
    </div>

    <div class="field<?= isset($errors['login']) ? ' has-error' : '' ?>">
        <label for="login"><?= e($t('common.full_jid')) ?></label>
        <input id="login" type="text" name="login" value="<?= e($value('login')) ?>"
               placeholder="<?= e($t('form.delete_account.login_placeholder')) ?>"
               autocomplete="username" autocapitalize="none" spellcheck="false" required>
        <small class="hint"><?= e($t('form.delete_account.hint.login')) ?></small>
        <?php if (isset($errors['login'])): ?><small class="field-error"><?= e($errors['login']) ?></small><?php endif; ?>
    </div>

    <div class="field<?= isset($errors['password']) ? ' has-error' : '' ?>">
        <label for="password"><?= e($t('common.password')) ?></label>
        <input id="password" type="password" name="password"
               placeholder="<?= e($t('form.delete_account.password_placeholder')) ?>"
               autocomplete="current-password" required>
        <?php if (isset($errors['password'])): ?><small class="field-error"><?= e($errors['password']) ?></small><?php endif; ?>
    </div>

    <?php if ($captchaHtml !== ''): ?>
        <div class="field<?= isset($errors['captcha']) ? ' has-error' : '' ?>">
            <?= $captchaHtml ?>
            <?php if (isset($errors['captcha'])): ?><small class="field-error"><?= e($errors['captcha']) ?></small><?php endif; ?>
        </div>
    <?php endif; ?>

    <button class="button button-danger" type="submit"><?= e($t('form.delete_account.submit')) ?></button>
</form>
<?php endif; ?>
