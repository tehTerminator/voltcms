<?php /** Stylesheets linked to this page or article in the editor (Admin > Code). $itemAssets['css'] is a list of URLs. */ ?>
<?php foreach ($itemAssets['css'] as $assetUrl): ?><link rel="stylesheet" href="<?= e($assetUrl) ?>">
<?php endforeach; ?>
