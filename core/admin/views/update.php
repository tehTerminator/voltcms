<?php /** @var array $pending @var string $from */ ?>
<div class="row justify-content-center"><div class="col-lg-7">
  <div class="card shadow-sm"><div class="card-body p-4">
    <h1 class="h4 mb-3">Update required</h1>
    <p>New program files (version <strong><?= e(CMS_VERSION) ?></strong>) are installed, but this site is still on version <strong><?= e($from) ?></strong>.
       Applying the update finishes the upgrade. Visitors see a short "updating" notice until you do.</p>
    <?php if ($pending !== []): ?>
      <p class="mb-1">Database changes to apply:</p>
      <ul><?php foreach ($pending as $p): ?><li><code><?= e($p) ?></code></li><?php endforeach; ?></ul>
    <?php else: ?>
      <p>No database changes are needed for this version.</p>
    <?php endif; ?>
    <div class="alert alert-info small">Before you continue, export a copy of your database in your hosting panel (phpMyAdmin, Export) and keep a copy of the <code>user/</code> folder. A built-in backup tool arrives in a later phase.</div>
    <form method="post" action="<?= e(url('/admin/update/apply')) ?>">
      <?= csrf_field() ?>
      <button class="btn btn-primary" type="submit">Apply update</button>
    </form>
  </div></div>
</div></div>
