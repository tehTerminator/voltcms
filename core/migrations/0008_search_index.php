<?php
declare(strict_types=1);

/**
 * 0008_search_index: the table behind site search. The FULLTEXT index is optional: if the database refuses it, the table
 * is created without it and search uses LIKE only (setting search_mode = like). Existing content is indexed here, within a
 * time limit; if the limit is hit, setting search_index_complete stays 0 and the admin offers "Rebuild search index".
 */
return static function (Cms\Database $db): void {
    $columns = 'content_id INT UNSIGNED NOT NULL, title VARCHAR(255) NOT NULL, tags_text VARCHAR(1000) NOT NULL DEFAULT \'\', '
        . 'body_text MEDIUMTEXT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (content_id)';
    $tail = ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    $mode = 'fulltext';
    try {
        $db->exec('CREATE TABLE IF NOT EXISTS {p}search_index (' . $columns . ', FULLTEXT KEY ft_search (title, tags_text, body_text)' . $tail);
    } catch (\Throwable $e) {
        $mode = 'like';
        $db->exec('CREATE TABLE IF NOT EXISTS {p}search_index (' . $columns . $tail);
    }
    $settings = new Cms\Settings($db);
    $settings->set('search_mode', $mode);
    $result = (new Cms\SearchIndex($db))->rebuild(new Cms\ContentStore(CMS_ROOT . '/user'), 0, 100, microtime(true) + 20.0);
    $settings->set('search_index_complete', $result['next'] === null ? '1' : '0');
    $settings->set('search_index_after', (string) ($result['next'] ?? 0));
};
