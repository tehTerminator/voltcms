<?php /** Generic content template (fallback for any content type). Variables: $item (row), $body (sanitised HTML). */ ?>
<article>
  <h1 class="mb-4"><?= e($item['title']) ?></h1>
  <div class="cms-content"><?= $body ?></div>
</article>
<?php partial('tags'); ?>
