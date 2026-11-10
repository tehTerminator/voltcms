<?php
declare(strict_types=1);

/**
 * 0010_media_thumbnails: columns that record each image's thumbnail.
 * thumb_status: NULL = not attempted yet (the "Generate thumbnails" tool picks these up), ok, small (no thumbnail needed),
 * skipped (cannot be done on this server), failed.
 */
return static function (Cms\Database $db): void {
    $has = static fn (string $col): bool => $db->one("SHOW COLUMNS FROM {p}media LIKE '" . $col . "'") !== null;
    if (!$has('thumb_path')) {
        $db->exec('ALTER TABLE {p}media ADD COLUMN thumb_path VARCHAR(190) NULL, ADD COLUMN thumb_width INT UNSIGNED NULL, '
            . 'ADD COLUMN thumb_height INT UNSIGNED NULL, ADD COLUMN thumb_status VARCHAR(10) NULL');
    }
};
