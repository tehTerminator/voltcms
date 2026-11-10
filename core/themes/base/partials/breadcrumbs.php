<?php /** Breadcrumb trail for Book Articles. Pass: crumbs (ancestor rows), item (current row). */ ?>
<?php if ($crumbs !== []): ?>
<nav aria-label="breadcrumb"><ol class="breadcrumb">
  <?php foreach ($crumbs as $c): ?><li class="breadcrumb-item"><a href="<?= e(content_url($c)) ?>"><?= e($c['title']) ?></a></li><?php endforeach; ?>
  <li class="breadcrumb-item active" aria-current="page"><?= e($item['title']) ?></li>
</ol></nav>
<?php endif; ?>
