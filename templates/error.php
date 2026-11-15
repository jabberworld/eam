<?php
/** @var callable $t */
/** @var string $title */
/** @var string $message */
?>
<div class="page-head">
    <h1><?= e($title) ?></h1>
</div>
<div class="alert alert-error"><?= e($message) ?></div>
<p><a class="button" href="<?= e(url('/')) ?>"><?= e($t('common.back_home')) ?></a></p>
