<?php
/** @var array $rows @var int $total @var array $filters @var int $page @var int $pages @var bool $outline @var array $counts @var array $types */
$qs = static fn (array $extra = []): string => '?' . http_build_query(array_filter(array_merge($filters, $extra), static fn ($v): bool => $v !== '' && $v !== null));
$inTrash = $filters['status'] === 'trash';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <h1 class="h3 mb-0"><?= $inTrash ? 'Trash' : 'Content' ?></h1>
  <div class="d-flex flex-wrap gap-2">
    <a class="btn btn-primary btn-sm" href="<?= e(url('/admin/content/new?type=article')) ?>">New article</a>
    <a class="btn btn-outline-primary btn-sm" href="<?= e(url('/admin/content/new?type=page')) ?>">New page</a>
    <a class="btn btn-outline-primary btn-sm" href="<?= e(url('/admin/content/new?type=book')) ?>">New book article</a>
  </div>
</div>

<ul class="nav nav-pills mb-3">
  <li class="nav-item"><a class="nav-link<?= $filters['status'] === '' ? ' active' : '' ?>" href="<?= e(url('/admin/content') . $qs(['status' => '', 'page' => ''])) ?>">All (<?= e($counts['published'] + $counts['draft']) ?>)</a></li>
  <li class="nav-item"><a class="nav-link<?= $filters['status'] === 'published' ? ' active' : '' ?>" href="<?= e(url('/admin/content') . $qs(['status' => 'published', 'page' => ''])) ?>">Published (<?= e($counts['published']) ?>)</a></li>
  <li class="nav-item"><a class="nav-link<?= $filters['status'] === 'draft' ? ' active' : '' ?>" href="<?= e(url('/admin/content') . $qs(['status' => 'draft', 'page' => ''])) ?>">Drafts (<?= e($counts['draft']) ?>)</a></li>
  <li class="nav-item"><a class="nav-link<?= $inTrash ? ' active' : '' ?>" href="<?= e(url('/admin/content') . $qs(['status' => 'trash', 'page' => ''])) ?>">Trash (<?= e($counts['trash']) ?>)</a></li>
  <li class="nav-item ms-auto"><a class="nav-link" href="<?= e(url('/admin/content/integrity')) ?>">Integrity check</a></li>
</ul>

<form method="get" action="<?= e(url('/admin/content')) ?>" class="row g-2 mb-3">
  <input type="hidden" name="status" value="<?= e($filters['status']) ?>">
  <div class="col-sm-5"><label class="visually-hidden" for="q">Search titles</label>
    <input class="form-control" id="q" name="q" value="<?= e($filters['q']) ?>" placeholder="Search titles" maxlength="100"></div>
  <div class="col-sm-3"><label class="visually-hidden" for="ftype">Type</label>
    <select class="form-select" id="ftype" name="type">
      <option value="">All types</option>
      <?php foreach ($types as $k => $label): ?><option value="<?= e($k) ?>"<?= $filters['type'] === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
    </select></div>
  <div class="col-auto"><button class="btn btn-outline-secondary" type="submit">Filter</button></div>
</form>

<?php if ($rows === []): ?>
  <div class="card shadow-sm"><div class="card-body text-center text-secondary py-5">
    <?= $inTrash ? 'The trash is empty.' : 'No content found. Create your first article, page or book article with the buttons above.' ?>
  </div></div>
<?php else: ?>
<form method="post" action="<?= e(url('/admin/content/bulk')) ?>">
  <?= csrf_field() ?>
  <div class="card shadow-sm"><div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead><tr>
        <th scope="col" class="text-center"><span class="visually-hidden">Select</span></th>
        <th scope="col">Title</th><th scope="col">Type</th><th scope="col">Status</th><th scope="col">Updated</th><th scope="col" class="text-end">Actions</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r): $id = (int) $r['id']; ?>
        <tr>
          <td class="text-center"><input class="form-check-input" type="checkbox" name="ids[]" value="<?= e($id) ?>" aria-label="Select <?= e($r['title']) ?>"></td>
          <td><div class="ms-<?= $outline ? [0, 3, 4, 5][min(3, (int) $r['depth'])] : 0 ?>">
            <a href="<?= e(url('/admin/content/' . $id)) ?>"><?= e($r['title']) ?></a>
            <div class="text-secondary small text-break">/<?= e($r['path']) ?></div></div></td>
          <td><?= e($types[$r['type'] ?? 'book'] ?? 'Book Article') ?></td>
          <td>
            <span class="badge text-bg-<?= $r['status'] === 'published' ? 'success' : 'secondary' ?>"><?= e(ucfirst($r['status'])) ?></span>
            <?php if (($r['visibility'] ?? 'public') === 'private'): ?><span class="badge text-bg-warning">Private</span><?php endif; ?>
          </td>
          <td class="text-nowrap small"><?= e(format_date($r['updated_at'])) ?></td>
          <td class="text-end text-nowrap">
            <?php if ($inTrash): ?>
              <button class="btn btn-outline-secondary btn-sm" type="submit" formaction="<?= e(url('/admin/content/' . $id . '/restore')) ?>">Restore</button>
              <button class="btn btn-outline-danger btn-sm" type="submit" formaction="<?= e(url('/admin/content/' . $id . '/purge')) ?>" data-confirm="Delete this permanently? Its files will be archived in user/backups/deleted/.">Delete</button>
            <?php else: ?>
              <?php if ($outline): ?>
                <button class="btn btn-outline-secondary btn-sm" type="submit" name="dir" value="up" formaction="<?= e(url('/admin/content/' . $id . '/move')) ?>" aria-label="Move up">&uarr;</button>
                <button class="btn btn-outline-secondary btn-sm" type="submit" name="dir" value="down" formaction="<?= e(url('/admin/content/' . $id . '/move')) ?>" aria-label="Move down">&darr;</button>
              <?php endif; ?>
              <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('/admin/content/' . $id)) ?>">Edit</a>
              <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('/admin/content/' . $id . '/preview')) ?>" target="_blank" rel="noopener">Preview</a>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div></div>
  <?php if (!$inTrash): ?>
  <div class="row g-2 mt-2 align-items-center">
    <div class="col-auto"><label class="visually-hidden" for="bulk_action">Bulk action</label>
      <select class="form-select form-select-sm" id="bulk_action" name="bulk_action">
        <option value="">Bulk action...</option><option value="publish">Publish</option><option value="draft">Move to drafts</option><option value="trash">Move to trash</option>
      </select></div>
    <div class="col-auto"><button class="btn btn-outline-secondary btn-sm" type="submit">Apply to selected</button></div>
  </div>
  <?php endif; ?>
</form>
<?php if ($pages > 1): ?>
<nav class="mt-3" aria-label="Pagination"><ul class="pagination pagination-sm">
  <li class="page-item<?= $page <= 1 ? ' disabled' : '' ?>"><a class="page-link" href="<?= e(url('/admin/content') . $qs(['page' => $page - 1])) ?>">Previous</a></li>
  <li class="page-item disabled"><span class="page-link">Page <?= e($page) ?> of <?= e($pages) ?> (<?= e($total) ?> items)</span></li>
  <li class="page-item<?= $page >= $pages ? ' disabled' : '' ?>"><a class="page-link" href="<?= e(url('/admin/content') . $qs(['page' => $page + 1])) ?>">Next</a></li>
</ul></nav>
<?php endif; ?>
<?php endif; ?>
