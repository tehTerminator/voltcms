<?php /** @var array $formats @var array $zones @var array $v @var array $errors @var string $detectedUrl @var string $logoLabel @var string $logoThumb @var string $socialLabel @var string $socialThumb @var string $searchMode @var bool $indexComplete @var int $indexed */ ?>
<h1 class="h3 mb-3">Site settings</h1>
<?php if ($errors !== []): ?>
  <div class="alert alert-danger" role="alert"><strong>Please fix the following:</strong><ul class="mb-0"><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>
<?php if ($v['site_url'] === ''): ?>
  <div class="alert alert-warning" role="alert">The site URL is not set. Search engines, the sitemap and the RSS feed need it.<?php if ($detectedUrl !== ''): ?> It looks like your site is <strong><?= e($detectedUrl) ?></strong>; enter it below if that is right.<?php endif; ?></div>
<?php endif; ?>
<form method="post" action="<?= e(url('/admin/settings')) ?>" data-warn-unsaved>
  <?= csrf_field() ?>
  <div class="card shadow-sm mb-4"><div class="card-body">
    <div class="row g-3">
      <div class="col-md-6"><label class="form-label" for="site_name">Site name</label>
        <input class="form-control" id="site_name" name="site_name" value="<?= e($v['site_name']) ?>" maxlength="100" required></div>
      <div class="col-md-6"><label class="form-label" for="site_url">Site URL</label>
        <input class="form-control" id="site_url" name="site_url" value="<?= e($v['site_url']) ?>" maxlength="200" placeholder="<?= e($detectedUrl !== '' ? $detectedUrl : 'https://example.com') ?>" inputmode="url">
        <div class="form-text">The public address, without a trailing slash. Include the folder if the site is in a subfolder.</div></div>
      <div class="col-md-4"><label class="form-label" for="site_lang">Language code</label>
        <input class="form-control" id="site_lang" name="site_lang" value="<?= e($v['site_lang']) ?>" maxlength="12" placeholder="en">
        <div class="form-text">For example en, hi or en-IN. Used for the page language.</div></div>
      <div class="col-12"><label class="form-label" for="site_description">Default description</label>
        <textarea class="form-control" id="site_description" name="site_description" rows="2" maxlength="320"><?= e($v['site_description']) ?></textarea>
        <div class="form-text">Shown to search engines and in the RSS feed when a page has no description of its own (up to 320 characters).</div></div>
      <div class="col-md-6"><label class="form-label" for="site_logo_media_id">Logo</label>
        <?= Cms\AutocompleteField::render(['id' => 'site_logo_media_id', 'name' => 'site_logo_media_id', 'source' => 'media-image', 'value' => $v['site_logo_media_id'], 'label' => $logoLabel, 'thumb' => $logoThumb, 'placeholder' => 'Search images (empty = show the site name)']) ?></div>
      <div class="col-md-6"><label class="form-label" for="site_social_media_id">Default social preview image</label>
        <?= Cms\AutocompleteField::render(['id' => 'site_social_media_id', 'name' => 'site_social_media_id', 'source' => 'media-image', 'value' => $v['site_social_media_id'], 'label' => $socialLabel, 'thumb' => $socialThumb, 'placeholder' => 'Search images (empty = none)']) ?>
        <div class="form-text">Used when a page has no social image. Upload images in <a href="<?= e(url('/admin/media')) ?>">Media</a> first, then search for them here.</div></div>
    </div>
  </div></div>
  <div class="card shadow-sm mb-4"><div class="card-body">
    <h2 class="h5">Display</h2>
    <div class="row g-3">
      <div class="col-md-4"><label class="form-label" for="items_per_page">Items per page</label>
        <input class="form-control" id="items_per_page" name="items_per_page" value="<?= e($v['items_per_page']) ?>" inputmode="numeric" maxlength="2">
        <div class="form-text">Home page, tag pages and search results. 5 to 50.</div></div>
      <div class="col-md-4"><label class="form-label" for="timezone">Time zone</label>
        <input class="form-control" id="timezone" name="timezone" value="<?= e($v['timezone']) ?>" list="zone-list" autocomplete="off" placeholder="UTC">
        <datalist id="zone-list"><?php foreach ($zones as $z): ?><option value="<?= e($z) ?>"><?php endforeach; ?></datalist>
        <div class="form-text">Dates are stored in UTC and shown in this zone, for example <code>Asia/Kolkata</code>.</div></div>
      <div class="col-md-4"><label class="form-label" for="date_format">Date format</label>
        <select class="form-select" id="date_format" name="date_format">
          <?php foreach ($formats as $f => $example): ?><option value="<?= e($f) ?>"<?= $v['date_format'] === $f ? ' selected' : '' ?>><?= e($example) ?></option><?php endforeach; ?>
        </select></div>
      <div class="col-12"><label class="form-label" for="footer_text">Footer text</label>
        <input class="form-control" id="footer_text" name="footer_text" value="<?= e($v['footer_text']) ?>" maxlength="300" placeholder="&copy; {year} Your name. All rights reserved.">
        <div class="form-text">Plain text shown in the footer by the default theme; <code>{year}</code> becomes the current year. Empty shows a copyright line with the site name.</div></div>
    </div>
  </div></div>

  <div class="card shadow-sm mb-4"><div class="card-body">
    <h2 class="h5">Maintenance mode</h2>
    <div class="form-check mb-3"><input class="form-check-input" type="checkbox" id="maintenance_mode" name="maintenance_mode" value="1"<?= $v['maintenance_mode'] === '1' ? ' checked' : '' ?>>
      <label class="form-check-label" for="maintenance_mode">Take the public site offline for visitors</label>
      <div class="form-text">Visitors, search engines and feed readers get a "back soon" page (HTTP 503, which tells search engines it is temporary). You, signed in, can still browse the site; the admin area and its sign-in always work.</div></div>
    <label class="form-label" for="maintenance_message">Message for visitors (optional)</label>
    <textarea class="form-control" id="maintenance_message" name="maintenance_message" rows="2" maxlength="500" placeholder="The site is down for maintenance. Please check back soon."><?= e($v['maintenance_message']) ?></textarea>
  </div></div>

  <button class="btn btn-primary" type="submit">Save settings</button>
</form>

<?php if ($v['site_url'] !== ''): ?>
<div class="card shadow-sm mt-4"><div class="card-body">
  <h2 class="h5">Addresses for search engines and readers</h2>
  <ul class="mb-2">
    <li>Sitemap: <a href="<?= e(url('/sitemap.xml')) ?>" target="_blank" rel="noopener"><code><?= e($v['site_url']) ?>/sitemap.xml</code></a></li>
    <li>RSS feed: <a href="<?= e(url('/feed.xml')) ?>" target="_blank" rel="noopener"><code><?= e($v['site_url']) ?>/feed.xml</code></a></li>
    <li>Robots file: <a href="<?= e(url('/robots.txt')) ?>" target="_blank" rel="noopener"><code><?= e($v['site_url']) ?>/robots.txt</code></a></li>
  </ul>
  <p class="small text-secondary mb-0">Submit the sitemap address in Google Search Console and Bing Webmaster Tools. Drafts, private pages, trashed items and pages marked "do not index" are never listed. If your host serves a real <code>robots.txt</code> file from the site folder, that file is used instead of the generated one.</p>
</div></div>
<?php endif; ?>

<div class="card shadow-sm mt-4"><div class="card-body">
  <h2 class="h5">Search index</h2>
  <p class="mb-2">Search method: <strong><?= $searchMode === 'fulltext' ? 'full-text with LIKE fallback' : 'LIKE only (this database has no usable full-text index)' ?></strong>. Entries: <strong><?= e($indexed) ?></strong>.
    Status: <?= $indexComplete ? '<span class="badge text-bg-success">Complete</span>' : '<span class="badge text-bg-warning">Incomplete</span>' ?></p>
  <p class="small text-secondary">The index is updated every time you save content. Rebuild it after restoring a backup or if search results look wrong. Visitor-facing search arrives in the next release.</p>
  <form method="post" action="<?= e(url('/admin/settings/reindex')) ?>"><?= csrf_field() ?>
    <button class="btn btn-outline-secondary btn-sm" type="submit">Rebuild search index</button></form>
</div></div>
