<?php /** Search results. Variables: $q (the query), $searched (true when the query was long enough), $results (rows: type, path, slug, title, excerpt, published_at), $total, $page, $pages. */ ?>
<h1 class="h2 mb-3">Search</h1>
<div class="mb-4"><?php partial('search-form'); ?></div>
<?php if (!$searched): ?>
  <p class="text-secondary">Type at least 2 characters to search.</p>
<?php elseif ($total === 0): ?>
  <p>No results for &ldquo;<?= e($q) ?>&rdquo;. Try different or fewer words.</p>
<?php else: ?>
  <p class="text-secondary"><?= e($total) ?> <?= $total === 1 ? 'result' : 'results' ?> for &ldquo;<?= e($q) ?>&rdquo;</p>
  <?php foreach ($results as $r): ?>
    <article class="mb-4">
      <h2 class="h5 mb-1"><a href="<?= e(content_url($r)) ?>"><?= e($r['title']) ?></a></h2>
      <div class="text-secondary small mb-1"><?= e(Cms\ContentRepository::TYPES[$r['type']] ?? 'Page') ?><?php if ($r['published_at'] !== null): ?> &middot; <time datetime="<?= e(iso_date($r['published_at'])) ?>"><?= e(format_date($r['published_at'])) ?></time><?php endif; ?></div>
      <?php if ((string) $r['excerpt'] !== ''): ?><p class="mb-0"><?= e($r['excerpt']) ?></p><?php endif; ?>
    </article>
  <?php endforeach; ?>
  <?php partial('pagination', ['base' => '/search', 'query' => ['q' => $q]]); ?>
<?php endif; ?>
