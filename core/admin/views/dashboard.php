<?php if ($installerPresent): ?>
<div class="alert alert-warning" role="alert"><strong>Delete install.php.</strong> The installer is locked and cannot run again, but the file should not stay on your server.</div>
<?php endif; ?>
<?php if ($maintenanceOn): ?>
<div class="alert alert-warning" role="alert"><strong>Maintenance mode is on.</strong> Visitors see a "back soon" page; you can still browse the site while signed in. <a href="<?= e(url('/admin/settings')) ?>">Turn it off in Site settings</a>.</div>
<?php endif; ?>
<?php if ($siteUrlMissing): ?>
<div class="alert alert-info" role="alert">Set your public site URL in <a href="<?= e(url('/admin/settings')) ?>">Site settings</a>. Search engines, the sitemap and the RSS feed need it.</div>
<?php endif; ?>
<?php if ((int) $user['totp_enabled'] !== 1): ?>
<div class="alert alert-info" role="alert">Two-factor authentication is off. <a href="<?= e(url('/admin/security')) ?>">Turn it on</a> to protect your login.</div>
<?php endif; ?>
<h1 class="h3 mb-4"><?= e($siteName) ?></h1>
<div class="row g-3 mb-4">
  <div class="col-sm-4"><div class="card shadow-sm"><div class="card-body"><div class="text-secondary small">Published</div><div class="fs-3"><?= e($counts['published']) ?></div></div></div></div>
  <div class="col-sm-4"><div class="card shadow-sm"><div class="card-body"><div class="text-secondary small">Drafts</div><div class="fs-3"><?= e($counts['draft']) ?></div></div></div></div>
  <div class="col-sm-4"><div class="card shadow-sm"><div class="card-body"><div class="text-secondary small">In trash</div><div class="fs-3"><?= e($counts['trash']) ?></div></div></div></div>
</div>
<p class="d-flex flex-wrap gap-2">
  <a class="btn btn-primary" href="<?= e(url('/admin/content/new?type=article')) ?>">New article</a>
  <a class="btn btn-outline-primary" href="<?= e(url('/admin/content/new?type=page')) ?>">New page</a>
  <a class="btn btn-outline-primary" href="<?= e(url('/admin/content/new?type=book')) ?>">New book article</a>
  <a class="btn btn-outline-secondary" href="<?= e(url('/admin/content')) ?>">All content</a>
</p>
<div class="card shadow-sm"><div class="card-body">
  <dl class="row mb-0">
    <dt class="col-sm-4">Signed in as</dt><dd class="col-sm-8"><?= e($user['username']) ?></dd>
    <dt class="col-sm-4">Two-factor</dt><dd class="col-sm-8"><?= (int) $user['totp_enabled'] === 1 ? 'On' : 'Off' ?></dd>
    <dt class="col-sm-4">Theme</dt><dd class="col-sm-8"><?= e($themeName) ?> <a class="small" href="<?= e(url('/admin/themes')) ?>">manage</a></dd>
    <dt class="col-sm-4">CMS version</dt><dd class="col-sm-8"><?= e(CMS_VERSION) ?></dd>
    <dt class="col-sm-4">PHP</dt><dd class="col-sm-8"><?= e(PHP_VERSION) ?></dd>
    <dt class="col-sm-4">Database</dt><dd class="col-sm-8"><?= e($dbVersion) ?></dd>
  </dl>
</div></div>

