<?php /** Home: latest articles. Variables: $articles (rows), $page, $pages. */ ?>
<h1 class="h2 mb-4">Latest articles</h1>
<?php if ($articles === []): ?>
  <p class="text-secondary">Nothing has been published yet.</p>
<?php endif; ?>
<?php foreach ($articles as $a): ?>
  <article class="mb-4">
    <h2 class="h4 mb-1"><a href="<?= e(content_url($a)) ?>"><?= e($a['title']) ?></a></h2>
    <div class="text-secondary small mb-1"><time datetime="<?= e(iso_date($a['published_at'])) ?>"><?= e(format_date($a['published_at'])) ?></time></div>
    <?php if ((string) $a['excerpt'] !== ''): ?><p class="mb-0"><?= e($a['excerpt']) ?></p><?php endif; ?>
  </article>
<?php endforeach; ?>
<?php partial('pagination', ['base' => '/']); ?>
