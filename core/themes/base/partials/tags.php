<?php /** Tag links of the page or article. Variable: $tags (rows with name and slug); empty for private pages. */ ?>
<?php if (!empty($tags)): ?>
<nav class="mt-4" aria-label="Tags"><ul class="list-inline mb-0">
  <?php foreach ($tags as $t): ?><li class="list-inline-item"><a class="badge text-bg-light border text-decoration-none" href="<?= e(tag_url($t)) ?>" rel="tag"><?= e($t['name']) ?></a></li>
  <?php endforeach; ?>
</ul></nav>
<?php endif; ?>
