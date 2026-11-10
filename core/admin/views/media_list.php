<?php /** @var array $rows @var int $total @var string $q @var int $page @var int $pages @var string $limit @var bool $gd @var int $missingThumbs */ ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <h1 class="h3 mb-0">Media</h1>
  <form method="get" action="<?= e(url('/admin/media')) ?>" class="d-flex gap-2">
    <label class="visually-hidden" for="q">Search media</label>
    <input class="form-control form-control-sm" id="q" name="q" value="<?= e($q) ?>" placeholder="Search by name or alt text" maxlength="100">
    <button class="btn btn-outline-secondary btn-sm" type="submit">Search</button>
  </form>
</div>

<div class="card shadow-sm mb-4"><div class="card-body">
  <h2 class="h6">Upload files</h2>
  <form method="post" action="<?= e(url('/admin/media/upload')) ?>" enctype="multipart/form-data" class="row g-2 align-items-end">
    <?= csrf_field() ?>
    <div class="col-md-8"><label class="form-label visually-hidden" for="files">Files</label>
      <input class="form-control" id="files" name="files[]" type="file" multiple accept=".jpg,.jpeg,.png,.gif,.webp,.pdf" required></div>
    <div class="col-auto"><button class="btn btn-primary" type="submit">Upload</button></div>
  </form>
  <div class="form-text">JPG, PNG, GIF, WebP and PDF, up to <?= e($limit) ?> each (your server's limit), 20 files at a time. File types are checked from the file's content, not its name, and files are stored under random names.</div>
</div></div>

<?php if (!$gd): ?>
  <div class="alert alert-info small" role="alert">Thumbnails need the PHP <strong>GD</strong> extension, which this server does not have. Until it is enabled, the grid loads the full images. Ask your host to enable GD, then use "Generate thumbnails" here.</div>
<?php elseif ($missingThumbs > 0): ?>
  <div class="alert alert-warning d-flex flex-wrap justify-content-between align-items-center gap-2" role="alert">
    <span><?= e($missingThumbs) ?> image(s) have no thumbnail yet, so the grid loads them at full size.</span>
    <form method="post" action="<?= e(url('/admin/media/thumbnails')) ?>"><?= csrf_field() ?><button class="btn btn-warning btn-sm" type="submit">Generate thumbnails</button></form>
  </div>
<?php endif; ?>

<?php if ($rows === []): ?>
  <div class="card shadow-sm"><div class="card-body text-center text-secondary py-5"><?= $q !== '' ? 'No files match your search.' : 'No files yet. Upload your first image above.' ?></div></div>
<?php else: ?>
<div class="row g-3">
  <?php foreach ($rows as $m): $isImg = Cms\MediaRepository::isImage($m); ?>
  <div class="col-6 col-md-4 col-lg-3 col-xl-2">
    <a class="card h-100 shadow-sm text-decoration-none" href="<?= e(url('/admin/media/' . (int) $m['id'])) ?>">
      <div class="ratio ratio-1x1 bg-body-tertiary">
        <?php if ($isImg): ?><img src="<?= e(Cms\MediaRepository::thumbUrl($m)) ?>" alt="<?= e((string) $m['alt_text']) ?>" loading="lazy" decoding="async" class="object-fit-cover w-100 h-100 rounded-top">
        <?php else: ?><div class="d-flex align-items-center justify-content-center fs-4 text-secondary">PDF</div><?php endif; ?>
      </div>
      <div class="card-body p-2 small text-body text-truncate"><?= e($m['original_name']) ?></div>
    </a>
  </div>
  <?php endforeach; ?>
</div>
<?php if ($pages > 1): ?>
<nav class="mt-3" aria-label="Pagination"><ul class="pagination pagination-sm">
  <li class="page-item<?= $page <= 1 ? ' disabled' : '' ?>"><a class="page-link" href="<?= e(url('/admin/media') . '?' . http_build_query(['q' => $q, 'page' => $page - 1])) ?>">Previous</a></li>
  <li class="page-item disabled"><span class="page-link">Page <?= e($page) ?> of <?= e($pages) ?> (<?= e($total) ?> files)</span></li>
  <li class="page-item<?= $page >= $pages ? ' disabled' : '' ?>"><a class="page-link" href="<?= e(url('/admin/media') . '?' . http_build_query(['q' => $q, 'page' => $page + 1])) ?>">Next</a></li>
</ul></nav>
<?php endif; endif; ?>
