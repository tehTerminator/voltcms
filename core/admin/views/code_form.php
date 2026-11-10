<?php /** @var ?array $item @var string $kind @var array $v @var array $errors @var array $usedBy @var array $versions @var string $url */
$isNew = $item === null;
$label = $kind === 'css' ? 'stylesheet' : 'script';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <h1 class="h3 mb-0"><?= $isNew ? 'New ' . e($label) : 'Edit ' . e($label) ?></h1>
  <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('/admin/code')) ?>">Back to code</a>
</div>
<?php if ($errors !== []): ?>
  <div class="alert alert-danger" role="alert"><strong>Please fix the following:</strong><ul class="mb-0"><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>
<div class="row g-4">
  <div class="col-lg-8">
    <form method="post" action="<?= e($isNew ? url('/admin/code/new') : url('/admin/code/' . (int) $item['id'])) ?>" data-warn-unsaved>
      <?= csrf_field() ?>
      <input type="hidden" name="kind" value="<?= e($kind) ?>">
      <div class="row g-3 mb-3">
        <div class="col-md-8"><label class="form-label" for="name">Name</label>
          <input class="form-control" id="name" name="name" value="<?= e($v['name']) ?>" maxlength="100" required></div>
        <div class="col-md-4"><label class="form-label" for="load_order">Load order</label>
          <input class="form-control" id="load_order" name="load_order" value="<?= e($v['load_order']) ?>" inputmode="numeric" maxlength="4">
          <div class="form-text">Lower loads first.</div></div>
      </div>
      <div class="mb-3"><label class="form-label" for="description">Description (optional)</label>
        <input class="form-control" id="description" name="description" value="<?= e($v['description']) ?>" maxlength="255"></div>
      <div class="mb-3"><label class="form-label" for="code"><?= $kind === 'css' ? 'CSS' : 'JavaScript' ?></label>
        <textarea class="form-control font-monospace" id="code" name="code" rows="22" spellcheck="false" required><?= e($v['code']) ?></textarea>
        <div class="form-text">Paste only the code, without <code>&lt;<?= $kind === 'css' ? 'style' : 'script' ?>&gt;</code> tags. Up to 200 KB.
          <?php if ($kind === 'js'): ?>This script runs on every page that uses it, with full access to the page. Only paste code you trust.<?php endif; ?></div></div>
      <button class="btn btn-primary" type="submit">Save</button>
    </form>
  </div>
  <div class="col-lg-4">
    <?php if (!$isNew): ?>
    <div class="card shadow-sm mb-3"><div class="card-body">
      <h2 class="h6">Address</h2>
      <code class="small text-break user-select-all"><?= e($url) ?></code>
      <h2 class="h6 mt-3">Used by</h2>
      <?php if ($usedBy === []): ?><p class="small text-secondary mb-0">No page uses this yet. Tick it in a page's editor under "Stylesheets and scripts".</p>
      <?php else: ?><ul class="small mb-0"><?php foreach ($usedBy as $u): ?><li><a href="<?= e(url('/admin/content/' . (int) $u['id'])) ?>"><?= e($u['title']) ?></a><?= $u['deleted_at'] !== null ? ' (trash)' : ($u['status'] === 'draft' ? ' (draft)' : '') ?></li><?php endforeach; ?></ul><?php endif; ?>
    </div></div>
    <div class="card shadow-sm mb-3"><div class="card-body">
      <h2 class="h6">Earlier versions</h2>
      <?php if ($versions === []): ?><p class="small text-secondary mb-0">Each time the code changes, the previous version is kept here (newest 10).</p>
      <?php else: ?>
      <form method="post" action="<?= e(url('/admin/code/' . (int) $item['id'] . '/restore')) ?>">
        <?= csrf_field() ?>
        <ul class="list-unstyled small mb-0">
        <?php foreach ($versions as $ver): ?>
          <li class="d-flex justify-content-between align-items-center mb-1"><span><?= e(gmdate('j M Y H:i', $ver['time'])) ?> UTC, <?= e(Cms\MediaWriter::formatBytes($ver['size'])) ?></span>
            <button class="btn btn-outline-secondary btn-sm py-0" type="submit" name="version" value="<?= e($ver['name']) ?>" data-confirm="Restore this version? The current code is kept in the history.">Restore</button></li>
        <?php endforeach; ?>
        </ul>
      </form>
      <?php endif; ?>
    </div></div>
    <div class="card shadow-sm"><div class="card-body">
      <form method="post" action="<?= e(url('/admin/code/' . (int) $item['id'] . '/delete')) ?>">
        <?= csrf_field() ?>
        <button class="btn btn-outline-danger btn-sm" type="submit" data-confirm="Delete this <?= e($label) ?>? It is removed from every page that uses it. The file is archived, not destroyed.">Delete <?= e($label) ?></button>
      </form>
    </div></div>
    <?php endif; ?>
  </div>
</div>
