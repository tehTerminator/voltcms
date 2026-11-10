<?php /** Child list and previous/next links for Book Articles. Pass: children, neighbors. */ ?>
<?php if ($children !== []): ?>
<section class="mt-5" aria-labelledby="book-contents">
  <h2 class="h5" id="book-contents">In this section</h2>
  <ul><?php foreach ($children as $ch): ?><li><a href="<?= e(content_url($ch)) ?>"><?= e($ch['title']) ?></a></li><?php endforeach; ?></ul>
</section>
<?php endif; ?>
<?php if ($neighbors['prev'] !== null || $neighbors['next'] !== null): ?>
<nav class="d-flex justify-content-between mt-5" aria-label="Book navigation">
  <span><?php if ($neighbors['prev'] !== null): ?><a href="<?= e(content_url($neighbors['prev'])) ?>" rel="prev">&larr; <?= e($neighbors['prev']['title']) ?></a><?php endif; ?></span>
  <span><?php if ($neighbors['next'] !== null): ?><a href="<?= e(content_url($neighbors['next'])) ?>" rel="next"><?= e($neighbors['next']['title']) ?> &rarr;</a><?php endif; ?></span>
</nav>
<?php endif; ?>
