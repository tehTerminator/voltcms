<?php /** Book Article. Variables: $item, $body, $crumbs (public ancestors), $children (public children), $neighbors (prev/next). */ ?>
<?php partial('breadcrumbs'); ?>
<article>
  <h1 class="mb-4"><?= e($item['title']) ?></h1>
  <div class="cms-content"><?= $body ?></div>
</article>
<?php partial('tags'); ?>
<?php partial('book-nav'); ?>
