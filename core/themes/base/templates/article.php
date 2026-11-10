<?php /** Article. Variables: $item (row), $body (sanitised HTML). */ ?>
<article>
  <h1 class="mb-2"><?= e($item['title']) ?></h1>
  <?php if ($item['published_at'] !== null): ?>
    <div class="text-secondary small mb-4"><time datetime="<?= e(iso_date($item['published_at'])) ?>"><?= e(format_date($item['published_at'])) ?></time></div>
  <?php endif; ?>
  <div class="cms-content"><?= $body ?></div>
</article>
<?php partial('tags'); ?>
