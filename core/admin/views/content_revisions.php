<?php /** @var array $item @var array $revisions */ ?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h3 mb-0">Revisions</h1>
  <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('/admin/content/' . (int) $item['id'])) ?>">Back to editing</a>
</div>
<p class="text-secondary">Each time the title or body changes, the previous version is kept (the newest 20 are stored). Restoring a revision replaces the current title and body and keeps what you replaced in this list.</p>
<?php if ($revisions === []): ?>
  <div class="card shadow-sm"><div class="card-body text-secondary text-center py-5">No earlier versions yet.</div></div>
<?php else: ?>
<form method="post" action="#">
  <?= csrf_field() ?>
  <div class="card shadow-sm"><div class="table-responsive"><table class="table align-middle mb-0">
    <thead><tr><th scope="col">Saved</th><th scope="col">Title at that time</th><th scope="col">Note</th><th scope="col">By</th><th scope="col" class="text-end"><span class="visually-hidden">Action</span></th></tr></thead>
    <tbody>
    <?php foreach ($revisions as $r): ?>
      <tr>
        <td class="text-nowrap"><?= e($r['created_at']) ?> UTC</td>
        <td><?= e($r['title']) ?></td>
        <td><?= e($r['note']) ?></td>
        <td><?= e($r['username'] ?? '') ?></td>
        <td class="text-end"><button class="btn btn-outline-secondary btn-sm" type="submit"
          formaction="<?= e(url('/admin/content/' . (int) $item['id'] . '/revisions/' . (int) $r['id'] . '/restore')) ?>"
          data-confirm="Restore this version? The current version will be kept in the history.">Restore</button></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div></div>
</form>
<?php endif; ?>
