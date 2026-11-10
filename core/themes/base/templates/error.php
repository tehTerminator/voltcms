<?php /** @var int $code @var string $message */ ?>
<div class="text-center py-5">
  <h1 class="display-6"><?= e($code) ?></h1>
  <p class="lead"><?= e($message) ?></p>
  <p><a href="<?= e(url('/')) ?>">Back to the home page</a></p>
</div>
