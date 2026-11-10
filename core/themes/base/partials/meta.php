<?php
/**
 * Meta tags: description, robots, canonical address, Open Graph and Twitter previews, feed link.
 * Variables: $seo (title, description, canonical, robots, og, twitter, feed), plus the older $metaDescription and $noindex.
 * Everything in $seo is plain text; escape it with e() when printing.
 */
$description = ($seo['description'] ?? '') !== '' ? $seo['description'] : $metaDescription;
$robots = ($seo['robots'] ?? '') !== '' ? $seo['robots'] : ($noindex ? 'noindex, nofollow' : '');
?>
<?php if ($description !== ''): ?><meta name="description" content="<?= e($description) ?>">
<?php endif; ?>
<?php if ($robots !== ''): ?><meta name="robots" content="<?= e($robots) ?>">
<?php endif; ?>
<?php if (($seo['canonical'] ?? '') !== ''): ?><link rel="canonical" href="<?= e($seo['canonical']) ?>">
<?php endif; ?>
<?php foreach ($seo['og'] ?? [] as $property => $content): ?><meta property="<?= e($property) ?>" content="<?= e($content) ?>">
<?php endforeach; ?>
<?php foreach ($seo['twitter'] ?? [] as $name => $content): ?><meta name="<?= e($name) ?>" content="<?= e($content) ?>">
<?php endforeach; ?>
<?php if (($seo['feed'] ?? '') !== ''): ?><link rel="alternate" type="application/rss+xml" title="<?= e($site['name']) ?>" href="<?= e($seo['feed']) ?>">
<?php endif; ?>
