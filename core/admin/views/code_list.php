<?php /** @var array $rows @var string $kind @var array $usage @var array $kinds @var array $integrity */ ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <h1 class="h3 mb-0">Code</h1>
  <div class="d-flex gap-2">
    <a class="btn btn-primary btn-sm" href="<?= e(url('/admin/code/new?kind=css')) ?>">New stylesheet</a>
    <a class="btn btn-outline-primary btn-sm" href="<?= e(url('/admin/code/new?kind=js')) ?>">New script</a>
  </div>
</div>
<p class="text-secondary">Write a stylesheet or script once, then tick it in the editor of every page or article that needs it. Each is a separate file that browsers cache. Site-wide styling belongs in your theme instead.</p>
<ul class="nav nav-pills mb-3">
  <li class="nav-item"><a class="nav-link<?= $kind === '' ? ' active' : '' ?>" href="<?= e(url('/admin/code')) ?>">All</a></li>
  <?php foreach ($kinds as $k => $label): ?><li class="nav-item"><a class="nav-link<?= $kind === $k ? ' active' : '' ?>" href="<?= e(url('/admin/code?kind=' . $k)) ?>"><?= e($label) ?>s</a></li><?php endforeach; ?>
</ul>
<?php if ($integrity['missing'] !== [] || $integrity['orphans'] !== []): ?>
  <div class="alert alert-warning small" role="alert"><strong>Integrity check:</strong>
    <?php if ($integrity['missing'] !== []): ?>files missing for <?= e(implode(', ', array_column($integrity['missing'], 'name'))) ?>. Open each and save to recreate it.<?php endif; ?>
    <?php if ($integrity['orphans'] !== []): ?>Files without a record (left untouched): <?= e(implode(', ', $integrity['orphans'])) ?>.<?php endif; ?></div>
<?php endif; ?>
<?php if ($rows === []): ?>
  <div class="card shadow-sm"><div class="card-body text-center text-secondary py-5">Nothing here yet. Create a stylesheet or script with the buttons above.</div></div>
<?php else: ?>
<div class="card shadow-sm"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
  <thead><tr><th scope="col">Name</th><th scope="col">Type</th><th scope="col">Load order</th><th scope="col">Used by</th><th scope="col">Size</th><th scope="col">Updated</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr>
      <td><a href="<?= e(url('/admin/code/' . (int) $r['id'])) ?>"><?= e($r['name']) ?></a><?php if ((string) $r['description'] !== ''): ?><div class="text-secondary small"><?= e($r['description']) ?></div><?php endif; ?></td>
      <td><span class="badge text-bg-<?= $r['kind'] === 'css' ? 'info' : 'warning' ?>"><?= e($kinds[$r['kind']] ?? $r['kind']) ?></span></td>
      <td><?= e($r['load_order']) ?></td>
      <td><?= e($usage[(int) $r['id']] ?? 0) ?> item(s)</td>
      <td class="text-nowrap small"><?= e(Cms\MediaWriter::formatBytes((int) $r['size'])) ?></td>
      <td class="text-nowrap small"><?= e(format_date($r['updated_at'])) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div></div>
<?php endif; ?>
