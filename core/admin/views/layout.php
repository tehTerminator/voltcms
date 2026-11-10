<?php
/** @var string $content @var string $title @var bool $nav @var array $flashes */
$allowed = ['success', 'danger', 'warning', 'info'];
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($title) ?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
<link rel="stylesheet" href="<?= e(url('/admin/assets/admin.css')) ?>?v=<?= e(CMS_VERSION) ?>">
</head>
<body class="bg-body-tertiary">
<?php if ($nav): ?>
<nav class="navbar navbar-expand bg-body border-bottom">
  <div class="container">
    <a class="navbar-brand" href="<?= e(url('/admin')) ?>">Admin</a>
    <ul class="navbar-nav me-auto flex-row flex-wrap column-gap-3">
      <li class="nav-item"><a class="nav-link" href="<?= e(url('/admin')) ?>">Dashboard</a></li>
      <li class="nav-item"><a class="nav-link" href="<?= e(url('/admin/content')) ?>">Content</a></li>
      <li class="nav-item"><a class="nav-link" href="<?= e(url('/admin/media')) ?>">Media</a></li>
      <li class="nav-item"><a class="nav-link" href="<?= e(url('/admin/tags')) ?>">Tags</a></li>
      <li class="nav-item"><a class="nav-link" href="<?= e(url('/admin/code')) ?>">Code</a></li>
      <li class="nav-item"><a class="nav-link" href="<?= e(url('/admin/themes')) ?>">Themes</a></li>
      <li class="nav-item"><a class="nav-link" href="<?= e(url('/admin/settings')) ?>">Settings</a></li>
      <li class="nav-item"><a class="nav-link" href="<?= e(url('/admin/security')) ?>">Security</a></li>
    </ul>
    <form method="post" action="<?= e(url('/admin/logout')) ?>" class="d-flex">
      <?= csrf_field() ?>
      <button class="btn btn-outline-secondary btn-sm" type="submit">Sign out</button>
    </form>
  </div>
</nav>
<?php endif; ?>
<main class="container py-4">
<?php foreach ($flashes as [$type, $message]): ?>
  <div class="alert alert-<?= e(in_array($type, $allowed, true) ? $type : 'info') ?>" role="alert"><?= e($message) ?></div>
<?php endforeach; ?>
<?= $content ?>
</main>
<script src="<?= e(url('/admin/assets/admin.js')) ?>?v=<?= e(CMS_VERSION) ?>" defer></script>
<script src="<?= e(url('/admin/assets/autocomplete.js')) ?>?v=<?= e(CMS_VERSION) ?>" defer></script>
</body>
</html>
