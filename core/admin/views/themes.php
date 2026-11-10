<?php /** @var array $themes @var string $active @var bool $activeMissing @var bool $activeBroken */ ?>
<h1 class="h3 mb-3">Themes</h1>
<p class="text-secondary">Your site uses the <strong>Base</strong> theme or a <strong>child theme</strong> that overrides only the files it needs. Child themes live in <code>user/themes/</code>, so updates never overwrite them.</p>

<?php if ($activeMissing || $activeBroken): ?>
  <div class="alert alert-warning" role="alert">The active theme "<?= e($active) ?>" could not be found, so visitors are seeing the Base theme. Activate a theme below to fix this.</div>
<?php endif; ?>

<div class="row g-3 mb-4">
<?php foreach ($themes as $t): $isActive = $t['name'] === $active || ($t['name'] === 'base' && ($active === 'base' || $activeMissing || $activeBroken)); $ok = $t['errors'] === []; ?>
  <div class="col-lg-6"><div class="card shadow-sm h-100"><div class="card-body">
    <div class="d-flex justify-content-between align-items-start gap-2">
      <h2 class="h5 mb-1"><?= e($t['meta']['title']) ?>
        <?php if ($isActive): ?><span class="badge text-bg-success ms-1">Active</span><?php endif; ?>
        <span class="badge text-bg-secondary ms-1"><?= $t['name'] === 'base' ? 'Base' : 'Child' ?></span></h2>
      <span class="text-secondary small text-nowrap">v<?= e($t['meta']['version'] ?: '?') ?></span>
    </div>
    <div class="text-secondary small mb-2"><code><?= e($t['name'] === 'base' ? 'core/themes/base' : 'user/themes/' . $t['name']) ?></code><?= $t['meta']['author'] !== '' ? ' &middot; ' . e($t['meta']['author']) : '' ?></div>
    <?php if ($t['meta']['description'] !== ''): ?><p class="mb-2"><?= e($t['meta']['description']) ?></p><?php endif; ?>
    <?php if ($t['overrides'] !== []): ?><p class="small mb-2"><strong>Overrides:</strong> <?= e(implode(', ', $t['overrides'])) ?></p><?php endif; ?>
    <?php if (!$ok): ?>
      <div class="alert alert-danger small mb-2"><strong>Cannot be activated:</strong>
        <ul class="mb-0"><?php foreach (array_slice($t['errors'], 0, 6) as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>
    <?php if ($t['warnings'] !== []): ?>
      <div class="alert alert-warning small mb-2"><ul class="mb-0"><?php foreach (array_slice($t['warnings'], 0, 4) as $w): ?><li><?= e($w) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>
    <?php if (!$isActive): ?>
      <form method="post" action="<?= e(url('/admin/themes/activate')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="name" value="<?= e($t['name']) ?>">
        <button class="btn btn-<?= $t['name'] === 'base' ? 'outline-secondary' : 'primary' ?> btn-sm" type="submit"<?= $ok ? '' : ' disabled' ?>><?= $t['name'] === 'base' ? 'Switch back to Base' : 'Activate' ?></button>
      </form>
    <?php endif; ?>
  </div></div></div>
<?php endforeach; ?>
</div>

<div class="card shadow-sm"><div class="card-body">
  <h2 class="h5">Create a child theme</h2>
  <p class="text-secondary small">Creates a ready-to-edit folder in <code>user/themes/</code> with a manifest and a stylesheet. Add template or partial files with the same names as the base theme's to override them. Editing happens over FTP or your hosting file manager (an in-browser editor comes in a later phase).</p>
  <form method="post" action="<?= e(url('/admin/themes/create')) ?>" class="row g-2 align-items-end">
    <?= csrf_field() ?>
    <div class="col-md-4"><label class="form-label" for="t_title">Theme name</label>
      <input class="form-control" id="t_title" name="title" maxlength="60" placeholder="My site theme" required></div>
    <div class="col-md-4"><label class="form-label" for="t_slug">Folder name</label>
      <input class="form-control" id="t_slug" name="slug" maxlength="39" pattern="[a-z0-9][a-z0-9\-]*" placeholder="my-site-theme" required></div>
    <div class="col-auto"><button class="btn btn-primary" type="submit">Create child theme</button></div>
  </form>
</div></div>
