<div class="card shadow-sm"><div class="card-body">
  <h1 class="h3 mb-3">Installation complete</h1>
  <p>The administrator account was created and the installer is now locked.</p>
  <?php if ($removed): ?>
    <p class="text-success">install.php was removed from the server.</p>
  <?php else: ?>
    <div class="alert alert-warning" role="alert"><strong>Delete install.php yourself</strong> (the server did not allow removing it). It cannot run again while the lock file exists, but it should not stay online.</div>
  <?php endif; ?>
  <?php if (!$lockWritten): ?>
    <div class="alert alert-danger" role="alert">Could not create <code>user/config/installed.lock</code>. Create an empty file with that name manually, otherwise the site will keep asking for installation.</div>
  <?php endif; ?>
  <a class="btn btn-primary" href="<?= e(url('/admin/login')) ?>">Sign in</a>
</div></div>
