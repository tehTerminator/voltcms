<h1 class="h3 mb-4">Security</h1>

<div class="card shadow-sm mb-4"><div class="card-body">
  <h2 class="h5">Two-factor authentication</h2>
  <?php if ((int) $user['totp_enabled'] === 1): ?>
    <p>Status: <strong>On</strong>. Each sign-in asks for a code from your authenticator app.</p>
    <form method="post" action="<?= e(url('/admin/security/2fa/disable')) ?>" class="row g-2 align-items-end">
      <?= csrf_field() ?>
      <div class="col-sm-6">
        <label class="form-label" for="d_pw">Current password</label>
        <input class="form-control" id="d_pw" name="current_password" type="password" autocomplete="current-password" required>
      </div>
      <div class="col-auto"><button class="btn btn-outline-danger" type="submit">Turn off two-factor</button></div>
    </form>
  <?php else: ?>
    <p>Status: <strong>Off</strong>. Use any authenticator app (Google Authenticator, Aegis, 2FAS, Microsoft Authenticator).</p>
    <form method="post" action="<?= e(url('/admin/security/2fa/start')) ?>">
      <?= csrf_field() ?>
      <button class="btn btn-primary" type="submit">Set up two-factor</button>
    </form>
  <?php endif; ?>
</div></div>

<div class="card shadow-sm"><div class="card-body">
  <h2 class="h5">Change password</h2>
  <form method="post" action="<?= e(url('/admin/security/password')) ?>">
    <?= csrf_field() ?>
    <div class="mb-3"><label class="form-label" for="c_pw">Current password</label>
      <input class="form-control" id="c_pw" name="current_password" type="password" autocomplete="current-password" required></div>
    <div class="mb-3"><label class="form-label" for="n_pw">New password</label>
      <input class="form-control" id="n_pw" name="new_password" type="password" autocomplete="new-password" minlength="12" maxlength="72" required>
      <div class="form-text">12 to 72 characters. A long passphrase works well.</div></div>
    <div class="mb-3"><label class="form-label" for="r_pw">Repeat new password</label>
      <input class="form-control" id="r_pw" name="confirm_password" type="password" autocomplete="new-password" minlength="12" maxlength="72" required></div>
    <button class="btn btn-primary" type="submit">Change password</button>
  </form>
</div></div>
