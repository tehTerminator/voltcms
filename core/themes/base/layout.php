<?php
/**
 * Base layout. Wraps every public template.
 * Variables: $site (name, lang), $title, $metaDescription, $noindex, $preview, $customCss, $customJs, $nonce,
 * $seo (title, description, canonical, robots, og, twitter, feed), $template (name of the template used),
 * $theme (name, title, version, author, is_child), $content (rendered template HTML).
 * Override this file, or just one of the partials it includes, in a child theme.
 */
$heading = ($seo['title'] ?? '') !== '' ? $seo['title'] : $title; // an SEO title set in the editor wins
$pageTitle = $heading !== '' ? $heading . ' | ' . $site['name'] : $site['name'];
?><!doctype html>
<html lang="<?= e($site['lang']) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle) ?></title>
<?php partial('meta'); ?>
<?php partial('vendor-css'); ?>
<?= theme_head_assets() ?>
<?php partial('item-css'); ?>
</head>
<body class="template-<?= e($template) ?>">
<?php partial('preview-banner'); ?>
<?php partial('header'); ?>
<main class="container py-4">
<?= $content ?>
</main>
<?php partial('footer'); ?>
<?php partial('vendor-js'); ?>
<?= theme_footer_assets() ?>
<?php partial('item-js'); ?>
</body>
</html>
