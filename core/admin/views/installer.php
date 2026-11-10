<?php /** @var array $checks @var array $form @var array $errors @var bool $blocked */ ?>
<h1 class="h3 mb-4">Install the CMS</h1>

<?php foreach ($errors as $err): ?>
  <div class="alert alert-danger" role="alert"><?= e($err) ?></div>
<?php endforeach; ?>

<div class="card shadow-sm mb-4"><div class="card-body">
  <h2 class="h5">1. Server check</h2>
  <table class="table table-sm mb-0">
    <?php foreach ($checks as $c): ?>
      <tr>
        <td><?= e($c['label']) ?></td>
        <td class="text-end">
          <?php if ($c['ok']): ?><span class="badge text-bg-success">OK</span>
          <?php elseif ($c['required']): ?><span class="badge text-bg-danger">Missing</span>
          <?php else: ?><span class="badge text-bg-warning">Recommended</span><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>
  <?php if ($blocked): ?><p class="text-danger mt-3 mb-0">Fix the items marked Missing, then reload this page.</p><?php endif; ?>
</div></div>

<form method="post" action="<?= e(url('/install.php')) ?>" autocomplete="off">
  <?= csrf_field() ?>
  <div class="card shadow-sm mb-4"><div class="card-body">
    <h2 class="h5">2. Database</h2>
    <p class="text-secondary">Create an empty MySQL database and user in your hosting control panel first.</p>
    <div class="row g-3">
      <div class="col-md-8"><label class="form-label" for="db_host">Host</label>
        <input class="form-control" id="db_host" name="db_host" value="<?= e($form['db_host']) ?>" required></div>
      <div class="col-md-4"><label class="form-label" for="db_port">Port</label>
        <input class="form-control" id="db_port" name="db_port" value="<?= e($form['db_port']) ?>" inputmode="numeric" required></div>
      <div class="col-md-6"><label class="form-label" for="db_name">Database name</label>
        <input class="form-control" id="db_name" name="db_name" value="<?= e($form['db_name']) ?>" required></div>
      <div class="col-md-6"><label class="form-label" for="prefix">Table prefix</label>
        <input class="form-control" id="prefix" name="prefix" value="<?= e($form['prefix']) ?>" required></div>
      <div class="col-md-6"><label class="form-label" for="db_user">Database user</label>
        <input class="form-control" id="db_user" name="db_user" value="<?= e($form['db_user']) ?>" required></div>
      <div class="col-md-6"><label class="form-label" for="db_pass">Database password</label>
        <input class="form-control" id="db_pass" name="db_pass" type="password" autocomplete="new-password"></div>
    </div>
  </div></div>

  <div class="card shadow-sm mb-4"><div class="card-body">
    <h2 class="h5">3. Site and administrator</h2>
    <div class="row g-3">
      <div class="col-12"><label class="form-label" for="site_name">Site name</label>
        <input class="form-control" id="site_name" name="site_name" value="<?= e($form['site_name']) ?>" maxlength="100" required></div>
      <div class="col-md-6"><label class="form-label" for="admin_username">Admin username</label>
        <input class="form-control" id="admin_username" name="admin_username" value="<?= e($form['admin_username']) ?>" maxlength="32" autocomplete="off" required></div>
      <div class="col-md-6"><label class="form-label" for="admin_email">Admin email</label>
        <input class="form-control" id="admin_email" name="admin_email" type="email" value="<?= e($form['admin_email']) ?>" maxlength="190" required></div>
      <div class="col-md-6"><label class="form-label" for="admin_password">Password</label>
        <input class="form-control" id="admin_password" name="admin_password" type="password" autocomplete="new-password" minlength="12" maxlength="72" required>
        <div class="form-text">12 to 72 characters.</div></div>
      <div class="col-md-6"><label class="form-label" for="admin_password2">Repeat password</label>
        <input class="form-control" id="admin_password2" name="admin_password2" type="password" autocomplete="new-password" minlength="12" maxlength="72" required></div>
    </div>
  </div></div>

  <button class="btn btn-primary" type="submit"<?= $blocked ? ' disabled' : '' ?>>Install</button>
</form>
