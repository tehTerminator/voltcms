<?php /** @var array $report @var array $mediaReport @var array $codeReport */ ?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h3 mb-0">Content integrity</h1>
  <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('/admin/content')) ?>">Back to content</a>
</div>
<p class="text-secondary">This report only reads. It never changes or deletes anything.</p>
<?php if ($report['missing'] === [] && $report['orphans'] === [] && $mediaReport['missing'] === [] && $mediaReport['orphans'] === [] && $mediaReport['missing_thumbs'] === [] && $codeReport['missing'] === [] && $codeReport['orphans'] === []): ?>
  <div class="alert alert-success" role="alert">Every record has its file and every file has a record (content, media, stylesheets and scripts).</div>
<?php endif; ?>
<?php if ($report['missing'] !== []): ?>
  <div class="card shadow-sm mb-4"><div class="card-body">
    <h2 class="h5">Records with a missing body file (<?= e(count($report['missing'])) ?>)</h2>
    <p class="small text-secondary">Visitors see an "unavailable" page for these. Open the item and save it to recreate the file, or restore the file from a backup.</p>
    <ul class="mb-0"><?php foreach ($report['missing'] as $m): ?>
      <li><a href="<?= e(url('/admin/content/' . (int) $m['id'])) ?>"><?= e($m['title']) ?></a> <span class="text-secondary">(id <?= e($m['id']) ?>, /<?= e($m['slug']) ?><?= $m['deleted_at'] !== null ? ', in trash' : '' ?>)</span></li>
    <?php endforeach; ?></ul>
  </div></div>
<?php endif; ?>
<?php if ($report['orphans'] !== []): ?>
  <div class="card shadow-sm"><div class="card-body">
    <h2 class="h5">Body files without a record (<?= e(count($report['orphans'])) ?>)</h2>
    <p class="small text-secondary">Left untouched. Review them in <code>user/content/posts/</code> before removing anything.</p>
    <ul class="mb-0"><?php foreach ($report['orphans'] as $o): ?><li><code><?= e($o) ?>.html</code></li><?php endforeach; ?></ul>
  </div></div>
<?php endif; ?>
<?php if ($mediaReport['missing'] !== [] || $mediaReport['orphans'] !== [] || $mediaReport['missing_thumbs'] !== []): ?>
  <div class="card shadow-sm mt-4"><div class="card-body">
    <h2 class="h5">Media</h2>
    <?php if ($mediaReport['missing'] !== []): ?><p class="small text-secondary mb-1">Records whose file is missing from <code>user/uploads/</code>:</p>
      <ul><?php foreach ($mediaReport['missing'] as $m): ?><li><a href="<?= e(url('/admin/media/' . (int) $m['id'])) ?>"><?= e($m['original_name']) ?></a> <span class="text-secondary">(<?= e($m['path']) ?>)</span></li><?php endforeach; ?></ul><?php endif; ?>
    <?php if ($mediaReport['missing_thumbs'] !== []): ?><p class="small text-secondary mb-1">Thumbnails that are recorded but missing (open the file and press "Create again"):</p>
      <ul><?php foreach ($mediaReport['missing_thumbs'] as $m): ?><li><a href="<?= e(url('/admin/media/' . (int) $m['id'])) ?>"><?= e($m['original_name']) ?></a></li><?php endforeach; ?></ul><?php endif; ?>
    <?php if ($mediaReport['orphans'] !== []): ?><p class="small text-secondary mb-1">Files without a record (left untouched):</p>
      <ul class="mb-0"><?php foreach ($mediaReport['orphans'] as $o): ?><li><code><?= e($o) ?></code></li><?php endforeach; ?></ul><?php endif; ?>
  </div></div>
<?php endif; ?>
<?php if ($codeReport['missing'] !== [] || $codeReport['orphans'] !== []): ?>
  <div class="card shadow-sm mt-4"><div class="card-body">
    <h2 class="h5">Stylesheets and scripts</h2>
    <?php if ($codeReport['missing'] !== []): ?><p class="small text-secondary mb-1">Records whose file is missing (open and save to recreate):</p>
      <ul><?php foreach ($codeReport['missing'] as $c): ?><li><a href="<?= e(url('/admin/code/' . (int) $c['id'])) ?>"><?= e($c['name']) ?></a></li><?php endforeach; ?></ul><?php endif; ?>
    <?php if ($codeReport['orphans'] !== []): ?><p class="small text-secondary mb-1">Files without a record (left untouched):</p>
      <ul class="mb-0"><?php foreach ($codeReport['orphans'] as $o): ?><li><code><?= e($o) ?></code></li><?php endforeach; ?></ul><?php endif; ?>
  </div></div>
<?php endif; ?>
