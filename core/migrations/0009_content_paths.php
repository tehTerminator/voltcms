<?php
declare(strict_types=1);

/**
 * 0009_content_paths: hierarchical addresses. Every item gets a full address in content.path ("book/part-one/article-1" for a
 * Book Article under two parents; just the slug for pages, articles and top-level books). The path is unique across the site, so
 * the same slug may now be used under different parents.
 *
 * Existing child pages were reachable at their flat slug; that address is kept as a redirect (content_redirects) to the new one.
 * The redirect column becomes old_path (it can now hold several segments).
 *
 * Needs an InnoDB row format that allows 2000-byte index keys (MySQL 5.7+, MariaDB 10.2+). If the server refuses, the migration
 * fails with a clear message and nothing is marked as applied.
 */
return static function (Cms\Database $db): void {
    $hasColumn = static fn (string $table, string $col): bool => $db->one('SHOW COLUMNS FROM {p}' . $table . " LIKE '" . $col . "'") !== null;

    if (!$hasColumn('content', 'path')) {
        $db->exec('ALTER TABLE {p}content ADD COLUMN path VARCHAR(500) NULL AFTER slug');
    }

    // Fill paths top-down (parents before children). Rows whose parent is missing are treated as top level.
    $rows = $db->all('SELECT id, parent_id, slug, path FROM {p}content ORDER BY id');
    $byId = array_column($rows, null, 'id');
    $children = [];
    foreach ($rows as $r) {
        $parent = $r['parent_id'] !== null && isset($byId[$r['parent_id']]) ? (int) $r['parent_id'] : 0;
        $children[$parent][] = $r;
    }
    $oldFlat = [];
    $assign = static function (int $parent, string $prefix, int $depth) use (&$assign, &$children, $db, &$oldFlat): void {
        foreach ($children[$parent] ?? [] as $r) {
            $path = $prefix === '' ? (string) $r['slug'] : $prefix . '/' . $r['slug'];
            if ($r['path'] !== $path) {
                $db->run('UPDATE {p}content SET path = ? WHERE id = ?', [$path, $r['id']]);
            }
            if ($depth > 0) {
                $oldFlat[(int) $r['id']] = (string) $r['slug'];
            }
            if ($depth < 20) {
                $assign((int) $r['id'], $path, $depth + 1);
            }
        }
    };
    $assign(0, '', 0);

    if ($db->one("SHOW COLUMNS FROM {p}content LIKE 'path'")['Null'] === 'YES') {
        $db->exec('ALTER TABLE {p}content MODIFY path VARCHAR(500) NOT NULL');
    }
    if ($db->one("SHOW INDEX FROM {p}content WHERE Key_name = 'uq_content_slug'") !== null) {
        $db->exec('ALTER TABLE {p}content DROP INDEX uq_content_slug');
    }
    if ($db->one("SHOW INDEX FROM {p}content WHERE Key_name = 'uq_content_path'") === null) {
        $db->exec('ALTER TABLE {p}content ADD UNIQUE KEY uq_content_path (path)');
    }
    if ($db->one("SHOW INDEX FROM {p}content WHERE Key_name = 'idx_content_slug'") === null) {
        $db->exec('ALTER TABLE {p}content ADD KEY idx_content_slug (slug)');
    }

    if ($hasColumn('content_redirects', 'old_slug')) {
        $db->exec('ALTER TABLE {p}content_redirects CHANGE old_slug old_path VARCHAR(500) NOT NULL');
    }
    // Old flat addresses of existing child pages keep working through a redirect (unless something live already uses that path).
    $now = gmdate('Y-m-d H:i:s');
    foreach ($oldFlat as $id => $slug) {
        if ($db->one('SELECT id FROM {p}content WHERE path = ?', [$slug]) === null) {
            $db->run('INSERT IGNORE INTO {p}content_redirects (old_path, content_id, created_at) VALUES (?, ?, ?)', [$slug, $id, $now]);
        }
    }
};
