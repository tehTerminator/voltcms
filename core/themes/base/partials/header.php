<?php /** Site header: logo (if one is set in Site settings), site name and the search box. Navigation links are plain markup here (blocks, regions and menus are not part of this CMS). $site: name, url, logo, logo_alt. */ ?>
<nav class="navbar bg-body-tertiary border-bottom">
  <div class="container gap-2">
    <a class="navbar-brand d-flex align-items-center gap-2" href="<?= e(url('/')) ?>">
      <?php if ($site['logo'] !== ''): ?><img src="<?= e($site['logo']) ?>" alt="<?= e($site['logo_alt']) ?>" height="32"><?php endif; ?>
      <span><?= e($site['name']) ?></span></a>
    <?php partial('search-form'); ?>
  </div>
</nav>
