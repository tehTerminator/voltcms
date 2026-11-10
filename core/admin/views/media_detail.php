<?php /** @var array $item @var string $url @var bool $isImage @var array $usage @var string $size */
$snippet = $isImage
    ? '<img src="' . $url . '" alt="' . ($item['alt_text'] ?? '') . '" width="' . (int) $item['width'] . '" height="' . (int) $item['height'] . '" loading="lazy" class="img-fluid">'
    : '<a href="' . $url . '">' . $item['original_name'] . '</a>';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h3 mb-0 text-break"><?= e($item['original_name']) ?></h1>
  <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('/admin/media')) ?>">Back to media</a>
</div>
<div class="row g-4">
  <div class="col-lg-6">
    <div class="card shadow-sm"><div class="card-body text-center">
      <?php if ($isImage): ?><img class="img-fluid" src="<?= e($url) ?>" alt="<?= e((string) $item['alt_text']) ?>">
      <?php else: ?><p class="my-5"><a class="btn btn-outline-primary" href="<?= e($url) ?>" target="_blank" rel="noopener">Open PDF</a></p><?php endif; ?>
    </div></div>
  </div>
  <div class="col-lg-6">
    <div class="card shadow-sm mb-3"><div class="card-body">
      <dl class="row mb-0 small">
        <dt class="col-4">Type</dt><dd class="col-8"><?= e($item['mime']) ?></dd>
        <dt class="col-4">Size</dt><dd class="col-8"><?= e($size) ?></dd>
        <?php if ($isImage): ?><dt class="col-4">Dimensions</dt><dd class="col-8"><?= e($item['width']) ?> &times; <?= e($item['height']) ?> px</dd><?php endif; ?>
        <?php if ($isImage): ?><dt class="col-4">Thumbnail</dt><dd class="col-8">
          <?php if ($item['thumb_path'] !== null): ?><?= e($item['thumb_width']) ?> &times; <?= e($item['thumb_height']) ?> px
          <?php else: ?><?= e(['small' => 'not needed (the image is already small)', 'skipped' => 'could not be created on this server', 'failed' => 'creation failed'][(string) $item['thumb_status']] ?? 'not created yet') ?><?php endif; ?>
          <form method="post" action="<?= e(url('/admin/media/' . (int) $item['id'] . '/thumbnail')) ?>" class="d-inline"><?= csrf_field() ?>
            <button class="btn btn-outline-secondary btn-sm py-0 ms-1" type="submit">Create again</button></form></dd><?php endif; ?>
        <dt class="col-4">Uploaded</dt><dd class="col-8"><?= e($item['created_at']) ?> UTC</dd>
        <dt class="col-4">Address</dt><dd class="col-8"><code class="user-select-all text-break"><?= e($url) ?></code> <button class="btn btn-outline-secondary btn-sm py-0" type="button" data-copy="<?= e($url) ?>">Copy</button></dd>
      </dl>
    </div></div>
    <div class="card shadow-sm mb-3"><div class="card-body">
      <h2 class="h6"><?= $isImage ? 'HTML to paste into a page' : 'Link to paste into a page' ?></h2>
      <textarea class="form-control font-monospace small" rows="3" readonly aria-label="HTML snippet"><?= e($snippet) ?></textarea>
      <button class="btn btn-outline-secondary btn-sm mt-2" type="button" data-copy="<?= e($snippet) ?>">Copy HTML</button>
      <?php if ($isImage && (string) $item['alt_text'] === ''): ?><div class="form-text text-warning">Add alt text below so the image is accessible and the snippet includes it.</div><?php endif; ?>
    </div></div>
    <?php if ($isImage): ?>
    <div class="card shadow-sm mb-3"><div class="card-body">
      <form method="post" action="<?= e(url('/admin/media/' . (int) $item['id'])) ?>">
        <?= csrf_field() ?>
        <label class="form-label" for="alt_text">Alt text (describes the image for screen readers and search)</label>
        <input class="form-control mb-2" id="alt_text" name="alt_text" value="<?= e((string) $item['alt_text']) ?>" maxlength="255">
        <button class="btn btn-primary btn-sm" type="submit">Save</button>
      </form>
    </div></div>
    <?php endif; ?>
    <div class="card shadow-sm"><div class="card-body">
      <?php if ($usage !== []): ?><p class="small mb-2">In use as: <?= e(implode(', ', $usage)) ?>. Choose another image there before deleting this one.</p><?php endif; ?>
      <p class="small text-secondary">Pages that embed this file by its address (in their HTML) will show a broken image if you delete it. The file is archived in <code>user/backups/deleted/</code>, not destroyed.</p>
      <form method="post" action="<?= e(url('/admin/media/' . (int) $item['id'] . '/delete')) ?>">
        <?= csrf_field() ?>
        <button class="btn btn-outline-danger btn-sm" type="submit" data-confirm="Delete this file? It will be archived and removed from the library.">Delete file</button>
      </form>
    </div></div>
  </div>
</div>
