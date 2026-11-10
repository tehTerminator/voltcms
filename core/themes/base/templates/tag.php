<?php /** Tag page. Variables: $tag (id, name, slug), $items (rows: type, path, slug, title, excerpt, published_at), $total, $page, $pages. */ ?>
<h1 class="h2 mb-1">Tag: <?= e($tag['name']) ?></h1>
<p class="text-secondary"><?= e($total) ?> <?= $total === 1 ? 'item' : 'items' ?></p>
<?php foreach ($items as $it): ?>
  <article class="mb-4">
    <h2 class="h5 mb-1"><a href="<?= e(content_url($it)) ?>"><?= e($it['title']) ?></a></h2>
    <div class="text-secondary small mb-1"><?= e(Cms\ContentRepository::TYPES[$it['type']] ?? 'Page') ?><?php if ($it['published_at'] !== null): ?> &middot; <time datetime="<?= e(iso_date($it['published_at'])) ?>"><?= e(format_date($it['published_at'])) ?></time><?php endif; ?></div>
    <?php if ((string) $it['excerpt'] !== ''): ?><p class="mb-0"><?= e($it['excerpt']) ?></p><?php endif; ?>
  </article>
<?php endforeach; ?>
<?php partial('pagination', ['base' => '/tag/' . rawurlencode($tag['slug'])]); ?>
