<div class="row justify-content-center">
  <div class="col-md-6 col-lg-4">
    <div class="card shadow-sm"><div class="card-body p-4">
      <h1 class="h4 mb-3">Sign in</h1>
      <form method="post" action="<?= e(url('/admin/login')) ?>">
        <?= csrf_field() ?>
        <div class="mb-3">
          <label class="form-label" for="username">Username</label>
          <input class="form-control" id="username" name="username" value="<?= e($username) ?>" autocomplete="username" maxlength="64" required autofocus>
        </div>
        <div class="mb-3">
          <label class="form-label" for="password">Password</label>
          <input class="form-control" id="password" name="password" type="password" autocomplete="current-password" required>
        </div>
        <button class="btn btn-primary w-100" type="submit">Sign in</button>
      </form>
    </div></div>
  </div>
</div>
