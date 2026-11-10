<h1 class="h3 mb-4">Set up two-factor authentication</h1>
<div class="card shadow-sm"><div class="card-body">
  <ol>
    <li>In your authenticator app, add a new account and choose <em>Enter a setup key</em> (or <em>manual entry</em>).</li>
    <li>Type this key (spaces do not matter), account type <em>time-based</em>:<br>
      <code class="fs-5 user-select-all"><?= e($secretGroups) ?></code></li>
    <li>Enter the 6-digit code the app now shows:</li>
  </ol>
  <form method="post" action="<?= e(url('/admin/security/2fa/confirm')) ?>" class="row g-2 align-items-end mb-3">
    <?= csrf_field() ?>
    <div class="col-sm-4">
      <label class="form-label visually-hidden" for="code">6-digit code</label>
      <input class="form-control" id="code" name="code" inputmode="numeric" pattern="[0-9 ]{6,7}" autocomplete="one-time-code" maxlength="7" required autofocus>
    </div>
    <div class="col-auto"><button class="btn btn-primary" type="submit">Verify and turn on</button></div>
  </form>
  <details>
    <summary>Apps that accept a setup link</summary>
    <p class="mt-2 mb-1">Some apps can import this link instead (it contains the same key, so treat it as secret):</p>
    <input class="form-control" readonly value="<?= e($uri) ?>" aria-label="Setup link">
  </details>
  <p class="text-secondary small mt-3 mb-0">No QR code is shown on purpose: generating one would need a third-party service or extra code, and the key never leaves your server this way.</p>
</div></div>
