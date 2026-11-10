<?php /** @var array $item @var string $error */ ?>
<div class="row justify-content-center"><div class="col-md-6 col-lg-4">
  <h1 class="h4 mb-3">This page is private</h1>
  <?php if ($error !== ''): ?><div class="alert alert-danger" role="alert"><?= e($error) ?></div><?php endif; ?>
  <form method="post" action="<?= e(content_url($item)) ?>">
    <?= csrf_field() ?>
    <div class="mb-3">
      <label class="form-label" for="password">Password</label>
      <input class="form-control" id="password" name="password" type="password" autocomplete="off" required autofocus>
    </div>
    <button class="btn btn-primary" type="submit">View page</button>
  </form>
</div></div>
