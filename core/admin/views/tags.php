<?php /** @var array $rows @var int $total @var int $page @var int $pages */ ?>
<div class="d-flex justify-content-between align-items-center mb-3"><h1 class="h3 mb-0">Tags</h1></div>
<p class="text-secondary">Tags are added to pages and articles in the content editor (comma-separated). Here you can rename or remove them. "Visible" counts published public items; private pages and drafts never show on public tag pages.</p>
<?php if ($rows === []): ?>
  <div class="card shadow-sm"><div class="card-body text-center text-secondary py-5">No tags yet. Type tags into the "Tags" box when you edit a page or article.</div></div>
<?php else: ?>
<div class="card shadow-sm"><div class="table-responsive"><table class="table align-middle mb-0">
  <thead><tr><th scope="col">Tag</th><th scope="col">Address</th><th scope="col">Items</th><th scope="col">Visible</th><th scope="col" class="text-end">Actions</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $t): ?>
    <tr>
      <td>
        <form method="post" action="<?= e(url('/admin/tags/' . (int) $t['id'])) ?>" class="d-flex gap-2">
          <?= csrf_field() ?>
          <label class="visually-hidden" for="tag<?= e($t['id']) ?>">Name of tag <?= e($t['name']) ?></label>
          <input class="form-control form-control-sm" id="tag<?= e($t['id']) ?>" name="name" value="<?= e($t['name']) ?>" maxlength="60" required>
          <button class="btn btn-outline-secondary btn-sm" type="submit">Rename</button>
        </form>
      </td>
      <td class="small text-nowrap"><code>/tag/<?= e($t['slug']) ?></code></td>
      <td><?= e($t['items']) ?></td>
      <td><?= e($t['public_items']) ?></td>
      <td class="text-end">
        <form method="post" action="<?= e(url('/admin/tags/' . (int) $t['id'] . '/delete')) ?>">
          <?= csrf_field() ?>
          <button class="btn btn-outline-danger btn-sm" type="submit" data-confirm="Delete the tag &quot;<?= e($t['name']) ?>&quot;? It is removed from all <?= e($t['items']) ?> item(s); the items are kept.">Delete</button>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div></div>
<?php if ($pages > 1): ?>
<nav class="mt-3" aria-label="Pagination"><ul class="pagination pagination-sm">
  <li class="page-item<?= $page <= 1 ? ' disabled' : '' ?>"><a class="page-link" href="<?= e(url('/admin/tags?page=' . ($page - 1))) ?>">Previous</a></li>
  <li class="page-item disabled"><span class="page-link">Page <?= e($page) ?> of <?= e($pages) ?> (<?= e($total) ?> tags)</span></li>
  <li class="page-item<?= $page >= $pages ? ' disabled' : '' ?>"><a class="page-link" href="<?= e(url('/admin/tags?page=' . ($page + 1))) ?>">Next</a></li>
</ul></nav>
<?php endif; endif; ?>
