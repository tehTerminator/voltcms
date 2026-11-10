<?php
declare(strict_types=1);

/**
 * 0005_content_seo: SEO title and social image on content, and the table that keeps old URLs working after a slug change.
 * ALTER ... ADD COLUMN IF NOT EXISTS is not available on every MySQL version, so each column is checked first.
 */
return static function (Cms\Database $db): void {
    // SHOW statements cannot take bound parameters; $col is always one of the two constants below.
    $has = static fn (string $col): bool => $db->one("SHOW COLUMNS FROM {p}content LIKE '" . $col . "'") !== null;
    if (!$has('seo_title')) {
        $db->exec('ALTER TABLE {p}content ADD COLUMN seo_title VARCHAR(160) NULL AFTER meta_description');
    }
    if (!$has('og_image_media_id')) {
        $db->exec('ALTER TABLE {p}content ADD COLUMN og_image_media_id INT UNSIGNED NULL AFTER seo_title');
    }
    $db->exec(
        'CREATE TABLE IF NOT EXISTS {p}content_redirects ('
        . 'old_slug VARCHAR(120) NOT NULL, content_id INT UNSIGNED NOT NULL, created_at DATETIME NOT NULL, '
        . 'PRIMARY KEY (old_slug), KEY idx_redirects_content (content_id), '
        . 'CONSTRAINT fk_{p}redirects_content FOREIGN KEY (content_id) REFERENCES {p}content (id) ON DELETE CASCADE'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
};
