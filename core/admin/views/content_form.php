<?php
/** @var ?array $item @var array $v @var array $errors @var array $types @var bool $hasPassword @var array $assets @var string $parentLabel @var string $ogLabel @var string $ogThumb */
$isNew = $item === null;
$action = $isNew ? url('/admin/content/new') : url('/admin/content/' . (int) $item['id']);
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <h1 class="h3 mb-0"><?= $isNew ? 'New content' : 'Edit content' ?></h1>
  <?php if (!$isNew): ?>
  <div class="d-flex flex-wrap gap-2">
    <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('/admin/content/' . (int) $item['id'] . '/preview')) ?>" target="_blank" rel="noopener">Preview saved version</a>
    <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('/admin/content/' . (int) $item['id'] . '/revisions')) ?>">Revisions</a>
    <?php if ($item['status'] === 'published' && $item['visibility'] === 'public' && $item['deleted_at'] === null): ?>
      <a class="btn btn-outline-secondary btn-sm" href="<?= e(content_url($item)) ?>" target="_blank" rel="noopener">View live</a>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>

<?php if (!$isNew && $item['deleted_at'] !== null): ?>
  <div class="alert alert-warning" role="alert">This item is in the trash and is not visible to visitors.</div>
<?php endif; ?>
<?php if ($errors !== []): ?>
  <div class="alert alert-danger" role="alert"><strong>Please fix the following:</strong>
    <ul class="mb-0"><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<form method="post" action="<?= e($action) ?>" data-warn-unsaved>
  <?= csrf_field() ?>
  <div class="row g-4">
    <div class="col-lg-8">
      <div class="mb-3"><label class="form-label" for="title">Title</label>
        <input class="form-control form-control-lg" id="title" name="title" value="<?= e($v['title']) ?>" maxlength="255" required></div>
      <div class="mb-3"><label class="form-label" for="body">Body (HTML)</label>
        <textarea class="form-control font-monospace" id="body" name="body" rows="22" spellcheck="false"><?= e($v['body']) ?></textarea>
        <div class="form-text">Normal publishing HTML and Bootstrap markup are kept. Scripts, iframes, forms, event handlers and unsafe links are removed when you save.</div></div>
      <div class="mb-3"><label class="form-label" for="excerpt">Excerpt</label>
        <textarea class="form-control" id="excerpt" name="excerpt" rows="2" maxlength="2000"><?= e($v['excerpt']) ?></textarea>
        <div class="form-text">Shown in article lists. Left blank, it is filled from the start of the body when you save.</div></div>
      <fieldset class="mb-3">
        <legend class="h6">Stylesheets and scripts for this item</legend>
        <?php if ($assets === []): ?>
          <p class="small text-secondary mb-0">None yet. <a href="<?= e(url('/admin/code')) ?>">Create a stylesheet or script</a> once, then tick it here for any page or article that needs it.</p>
        <?php else: ?>
          <div class="row g-2">
          <?php foreach ($assets as $a): ?>
            <div class="col-md-6"><div class="form-check">
              <input class="form-check-input" type="checkbox" name="asset_ids[]" id="asset<?= e($a['id']) ?>" value="<?= e($a['id']) ?>"<?= in_array((int) $a['id'], array_map('intval', $v['asset_ids']), true) ? ' checked' : '' ?>>
              <label class="form-check-label" for="asset<?= e($a['id']) ?>"><?= e($a['name']) ?> <span class="badge text-bg-<?= $a['kind'] === 'css' ? 'info' : 'warning' ?>"><?= $a['kind'] === 'css' ? 'CSS' : 'JS' ?></span></label>
            </div></div>
          <?php endforeach; ?>
          </div>
          <div class="form-text">Linked files load only on this item. Scripts run with full access to the page, so link only code you trust.</div>
        <?php endif; ?>
      </fieldset>
    </div>

    <div class="col-lg-4">
      <div class="card shadow-sm mb-3"><div class="card-body">
        <div class="mb-3"><label class="form-label" for="status">Status</label>
          <select class="form-select" id="status" name="status">
            <option value="draft"<?= $v['status'] === 'draft' ? ' selected' : '' ?>>Draft</option>
            <option value="published"<?= $v['status'] === 'published' ? ' selected' : '' ?>>Published</option>
          </select></div>
        <div class="mb-3"><label class="form-label" for="type">Type</label>
          <select class="form-select" id="type" name="type">
            <?php foreach ($types as $k => $label): ?><option value="<?= e($k) ?>"<?= $v['type'] === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
          </select></div>
        <div class="mb-3"><label class="form-label" for="parent_id">Parent (Book Articles only)</label>
          <?= Cms\AutocompleteField::render(['id' => 'parent_id', 'name' => 'parent_id', 'source' => 'content-parent', 'value' => $v['parent_id'], 'label' => $parentLabel, 'exclude' => $isNew ? null : (int) $item['id'], 'placeholder' => 'Search books by title or address (empty = top level)']) ?>
          <div class="form-text">Leave empty for a top-level book. Type to search; the address of a child page starts with its parent's address.</div></div>
        <div class="mb-3"><label class="form-label" for="slug">URL slug (last part of the address)</label>
          <div class="input-group"><span class="input-group-text">/</span>
            <input class="form-control" id="slug" name="slug" value="<?= e($v['slug']) ?>" maxlength="120" placeholder="from the title" autocomplete="off"></div>
          <div class="form-text">Leave blank to generate from the title. A Book Article's full address is its parent's address plus this slug (for example <code>/book/part-one/chapter</code>); pages, articles and top-level books use <code>/slug</code>.
            <?php if (!$isNew): ?><br>Current address: <code class="user-select-all text-break">/<?= e($item['path']) ?></code>. If you change it, the old address keeps redirecting.<?php endif; ?></div></div>
        <div class="mb-3"><label class="form-label" for="visibility">Visibility</label>
          <select class="form-select" id="visibility" name="visibility">
            <option value="public"<?= $v['visibility'] === 'public' ? ' selected' : '' ?>>Public</option>
            <option value="private"<?= $v['visibility'] === 'private' ? ' selected' : '' ?>>Private (password to view)</option>
          </select></div>
        <div class="mb-0"><label class="form-label" for="view_password">Page password</label>
          <input class="form-control" id="view_password" name="view_password" type="password" autocomplete="new-password" maxlength="72">
          <div class="form-text"><?= $hasPassword ? 'A password is set. Leave blank to keep it.' : 'Required when the page is private (8 to 72 characters).' ?> Private pages are hidden from lists, search, sitemap and feeds.</div></div>
      </div></div>

      <div class="card shadow-sm mb-3"><div class="card-body">
        <div class="mb-3"><label class="form-label" for="tags">Tags</label>
          <input class="form-control" id="tags" name="tags" value="<?= e($v['tags']) ?>" maxlength="600" placeholder="law, constitution, notes">
          <div class="form-text">Separate with commas (up to 15). New tags are created automatically.</div></div>
        <div class="mb-3"><label class="form-label" for="seo_title">SEO title (optional)</label>
          <input class="form-control" id="seo_title" name="seo_title" value="<?= e($v['seo_title']) ?>" maxlength="160">
          <div class="form-text">Shown in search results instead of the title, if you want it different.</div></div>
        <div class="mb-3"><label class="form-label" for="meta_description">Meta description</label>
          <textarea class="form-control" id="meta_description" name="meta_description" rows="3" maxlength="320"><?= e($v['meta_description']) ?></textarea>
          <div class="form-text">Up to 320 characters. Falls back to the excerpt.</div></div>
        <div class="mb-3"><label class="form-label" for="og_image_media_id">Social preview image</label>
          <?= Cms\AutocompleteField::render(['id' => 'og_image_media_id', 'name' => 'og_image_media_id', 'source' => 'media-image', 'value' => $v['og_image_media_id'], 'label' => $ogLabel, 'thumb' => $ogThumb, 'placeholder' => 'Search images (empty = site default)']) ?>
          <div class="form-text">Pick an image from <a href="<?= e(url('/admin/media')) ?>" target="_blank" rel="noopener">Media</a>; empty uses the site default.</div></div>
        <div class="form-check"><input class="form-check-input" type="checkbox" id="noindex" name="noindex" value="1"<?= (string) $v['noindex'] === '1' ? ' checked' : '' ?>>
          <label class="form-check-label" for="noindex">Ask search engines not to index this page</label></div>
      </div></div>

      <div class="d-grid gap-2">
        <button class="btn btn-primary" type="submit">Save</button>
        <?php if (!$isNew && $item['deleted_at'] === null): ?>
          <button class="btn btn-outline-danger" type="submit" formaction="<?= e(url('/admin/content/' . (int) $item['id'] . '/trash')) ?>" formnovalidate data-confirm="Move this item to the trash? You can restore it later.">Move to trash</button>
        <?php endif; ?>
      </div>
    </div>
  </div>
</form>
