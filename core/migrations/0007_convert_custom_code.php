<?php
declare(strict_types=1);

/**
 * 0007_convert_custom_code: per-page custom CSS/JavaScript becomes reusable code assets linked to the page.
 * The work is done by Cms\CodeAssetConverter (so it can be tested); old columns stay as a safety copy and are no longer read.
 */
return static function (Cms\Database $db): void {
    (new Cms\CodeAssetConverter())->run($db, new Cms\CodeAssetStore(CMS_ROOT . '/user'));
};
