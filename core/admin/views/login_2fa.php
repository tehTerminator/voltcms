<div class="row justify-content-center">
  <div class="col-md-6 col-lg-4">
    <div class="card shadow-sm"><div class="card-body p-4">
      <h1 class="h4 mb-3">Two-factor verification</h1>
      <form method="post" action="<?= e(url('/admin/login/2fa')) ?>">
        <?= csrf_field() ?>
        <div class="mb-3">
          <label class="form-label" for="code">Authenticator code or recovery code</label>
          <input class="form-control" id="code" name="code" inputmode="text" autocomplete="one-time-code" maxlength="20" required autofocus>
          <div class="form-text">Enter the 6-digit code from your app. Lost your phone? Use one of your recovery codes.</div>
        </div>
        <button class="btn btn-primary w-100" type="submit">Verify</button>
      </form>
    </div></div>
  </div>
</div>
