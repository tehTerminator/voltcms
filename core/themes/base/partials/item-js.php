<?php /** Scripts linked to this page or article in the editor (Admin > Code). $itemAssets['js'] is a list of URLs. */ ?>
<?php foreach ($itemAssets['js'] as $assetUrl): ?><script src="<?= e($assetUrl) ?>" defer></script>
<?php endforeach; ?>
