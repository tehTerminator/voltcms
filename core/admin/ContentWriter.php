<?php
declare(strict_types=1);

namespace Cms;

/**
 * All content writes: validation, sanitising, slugs, flat-file bodies, revisions, trash and purge.
 * Used by the admin only. Order of operations is chosen so a failure never loses a body:
 * the previous body is saved as a revision before it is replaced, and purged files are archived, not deleted.
 */
final class ContentWriter
{
    private const MAX_BODY = 1000000;
    private const KEEP_REVISIONS = 20;

    private TagWriter $tags;
    private SearchIndex $index;

    public function __construct(
        private Database $db,
        private ContentStore $store,
        private Sanitizer $sanitizer,
        private ContentRepository $repo
    ) {
        $this->tags = new TagWriter($db);
        $this->index = new SearchIndex($db);
    }

    /**
     * Validate and normalise submitted form data.
     * @param array<string,mixed> $in    raw POST data
     * @param array|null          $existing the stored row when editing
     * @return array{0:string[],1:array} [errors, clean values]
     */
    public function validate(array $in, ?array $existing): array
    {
        $errors = [];
        $get = static fn (string $k): string => isset($in[$k]) && is_string($in[$k]) ? $in[$k] : '';
        $id = $existing === null ? null : (int) $existing['id'];

        $title = trim($get('title'));
        if ($title === '' || mb_strlen($title) > 255) {
            $errors[] = 'Enter a title (up to 255 characters).';
        }
        $type = $get('type') !== '' ? $get('type') : (string) ($existing['type'] ?? 'page');
        if (!isset(ContentRepository::TYPES[$type])) {
            $errors[] = 'Choose a valid content type.';
        }
        $status = $get('status') !== '' ? $get('status') : 'draft';
        if (!in_array($status, ['draft', 'published'], true)) {
            $errors[] = 'Choose Draft or Published.';
        }

        // Parent (Book Articles only), no cycles. The parent's address is the prefix of this item's address.
        $parentRaw = $get('parent_id');
        $parentId = ctype_digit($parentRaw) && (int) $parentRaw > 0 ? (int) $parentRaw : null;
        $parentPath = '';
        if ($type !== 'book') {
            $parentId = null;
            if ($id !== null && $this->repo->childCount($id, true) > 0) {
                $errors[] = 'This Book Article has child pages. Move or delete them before changing its type.';
            }
        } elseif ($parentId !== null) {
            $parent = $this->repo->find($parentId);
            if ($parent === null || $parent['type'] !== 'book' || $parent['deleted_at'] !== null) {
                $errors[] = 'Choose a valid parent Book Article.';
            } elseif ($id !== null && ($parentId === $id || in_array($parentId, $this->repo->descendantIds($id), true))) {
                $errors[] = 'A Book Article cannot be placed under itself or one of its own children.';
            } else {
                $parentPath = (string) $parent['path'];
            }
        }
        $prefix = $parentPath === '' ? '' : $parentPath . '/';

        // Slug: blank = keep the current one (edit) or generate from the title (new). The address is parent address + slug.
        $slugIn = mb_strtolower(trim($get('slug')));
        if ($slugIn === '') {
            $slug = $existing !== null ? (string) $existing['slug'] : Slug::unique($this->repo, Slug::fromTitle($title), null, $parentPath);
        } elseif (!Slug::isValid($slugIn)) {
            $errors[] = 'The URL slug may contain letters, digits and single hyphens only (max 120 characters).';
            $slug = $slugIn;
        } elseif ($parentPath === '' && Slug::isReserved($slugIn)) {
            $errors[] = 'The slug "' . $slugIn . '" is reserved. Choose another.';
            $slug = $slugIn;
        } else {
            $slug = $slugIn;
        }
        $path = $prefix . $slug;
        if (Slug::isValid($slug)) {
            if ($existing === null || $existing['path'] !== $path) {
                if ($this->repo->pathTaken($path, $id)) {
                    $errors[] = 'The address /' . $path . ' is already used by another item (possibly in the trash). Choose a different slug.';
                } elseif (strlen($path) > 500) {
                    $errors[] = 'The address is too long (max 500 characters including parent addresses).';
                } elseif ($existing !== null && $this->repo->childCount($id, true) > 0) {
                    $oldLen = strlen((string) $existing['path']);
                    $longest = max(array_map(static fn (string $p): int => strlen($p) - $oldLen, $this->repo->descendantPaths($id)) ?: [0]);
                    if (strlen($path) + $longest > 500) {
                        $errors[] = 'Changing this address would make a child page address longer than 500 characters.';
                    }
                }
            }
        }

        // Visibility and visitor password.
        $visibility = $get('visibility') !== '' ? $get('visibility') : 'public';
        $newPassword = $get('view_password');
        if (!in_array($visibility, ['public', 'private'], true)) {
            $errors[] = 'Choose Public or Private.';
        } elseif ($visibility === 'private') {
            if ($newPassword !== '' && (strlen($newPassword) < 8 || strlen($newPassword) > 72)) {
                $errors[] = 'The page password must be 8 to 72 characters.';
            }
            if ($newPassword === '' && ($existing === null || $existing['view_password_hash'] === null)) {
                $errors[] = 'A private page needs a password.';
            }
        }

        $excerpt = trim($get('excerpt'));
        $meta = trim($get('meta_description'));
        if (mb_strlen($excerpt) > 2000) {
            $errors[] = 'The excerpt is too long (max 2000 characters).';
        }
        if (mb_strlen($meta) > 320) {
            $errors[] = 'The meta description is too long (max 320 characters).';
        }
        $seoTitle = trim($get('seo_title'));
        if (mb_strlen($seoTitle) > 160) {
            $errors[] = 'The SEO title is too long (max 160 characters).';
        }
        $ogId = ctype_digit($get('og_image_media_id')) && (int) $get('og_image_media_id') > 0 ? (int) $get('og_image_media_id') : null;
        if ($ogId !== null) {
            $media = (new MediaRepository($this->db))->find($ogId);
            if ($media === null || !MediaRepository::isImage($media)) {
                $errors[] = 'Choose an image from the media library for the social preview.';
            }
        }
        [$tagNames, $tagErrors] = TagWriter::parse($get('tags'));
        $errors = array_merge($errors, $tagErrors);
        $assetIds = [];
        foreach (is_array($in['asset_ids'] ?? null) ? $in['asset_ids'] : [] as $v) {
            if (is_string($v) && ctype_digit($v) && strlen($v) < 10) {
                $assetIds[] = (int) $v;
            }
        }
        $assetIds = array_slice(array_values(array_unique($assetIds)), 0, 50);
        if ($assetIds !== []) {
            $found = $this->db->all('SELECT id FROM {p}code_assets WHERE id IN (' . implode(',', array_fill(0, count($assetIds), '?')) . ')', $assetIds);
            if (count($found) !== count($assetIds)) {
                $errors[] = 'One of the selected stylesheets or scripts no longer exists.';
            }
        }
        $rawBody = $get('body');
        if (strlen($rawBody) > self::MAX_BODY) {
            $errors[] = 'The body is too large (max 1 MB).';
        }

        return [$errors, [
            'type' => $type, 'parent_id' => $parentId, 'slug' => $slug, 'path' => $path, 'title' => $title, 'status' => $status,
            'visibility' => $visibility, 'new_password' => $newPassword, 'excerpt' => $excerpt,
            'meta_description' => $meta, 'noindex' => $get('noindex') === '1' ? 1 : 0,
            'seo_title' => $seoTitle, 'og_image_media_id' => $ogId, 'tags' => $tagNames, 'asset_ids' => $assetIds,
            'body' => $errors === [] ? $this->sanitizer->clean($rawBody) : $rawBody,
        ]];
    }

    /** Insert a new item and its body file in one transaction. Returns the new id. */
    public function create(array $c): int
    {
        $now = gmdate('Y-m-d H:i:s');
        $excerpt = $c['excerpt'] !== '' ? $c['excerpt'] : self::autoExcerpt($c['body']);
        return (int) $this->db->transaction(function (Database $db) use ($c, $now, $excerpt): int {
            $position = (int) $db->value('SELECT COALESCE(MAX(position), 0) + 1 FROM {p}content WHERE parent_id <=> ?', [$c['parent_id']]);
            $db->run(
                'INSERT INTO {p}content (type, parent_id, position, slug, path, title, status, visibility, view_password_hash, excerpt,
                    meta_description, seo_title, og_image_media_id, noindex, published_at, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $c['type'], $c['parent_id'], $position, $c['slug'], $c['path'], $c['title'], $c['status'], $c['visibility'],
                    $c['visibility'] === 'private' ? password_hash($c['new_password'], PASSWORD_DEFAULT) : null,
                    $excerpt, $c['meta_description'] !== '' ? $c['meta_description'] : null,
                    $c['seo_title'] !== '' ? $c['seo_title'] : null, $c['og_image_media_id'], $c['noindex'],
                    $c['status'] === 'published' ? $now : null, $now, $now,
                ]
            );
            $id = $db->insertId();
            $this->syncExtras($id, $c, null);
            if (!$this->store->write($id, $c['body'])) {
                throw new \RuntimeException('Could not write the content file. Check permissions on user/content/posts.');
            }
            return $id;
        });
    }

    /** Update an item. The previous title and body are saved as a revision first when either changes. */
    public function update(int $id, array $c, int $userId, string $note = 'edit'): void
    {
        $current = $this->repo->find($id);
        if ($current === null) {
            throw new \RuntimeException('Content not found.');
        }
        $oldBody = $this->store->read($id);
        if ($oldBody !== ($c['body']) || $current['title'] !== $c['title']) {
            $this->snapshot($id, (string) $current['title'], $oldBody ?? '', $userId, $oldBody === null ? 'body file was missing' : $note);
        }
        $now = gmdate('Y-m-d H:i:s');
        $excerpt = $c['excerpt'] !== '' ? $c['excerpt'] : self::autoExcerpt($c['body']);
        $hash = null;
        if ($c['visibility'] === 'private') {
            $hash = $c['new_password'] !== '' ? password_hash($c['new_password'], PASSWORD_DEFAULT) : $current['view_password_hash'];
        }
        $publishedAt = $current['published_at'] ?? ($c['status'] === 'published' ? $now : null);
        $this->db->transaction(function (Database $db) use ($id, $c, $now, $excerpt, $hash, $publishedAt, $current): void {
            $position = (int) $current['position'];
            if ((($current['parent_id'] === null) ? null : (int) $current['parent_id']) !== $c['parent_id']) {
                $position = (int) $db->value('SELECT COALESCE(MAX(position), 0) + 1 FROM {p}content WHERE parent_id <=> ?', [$c['parent_id']]);
            }
            $db->run(
                'UPDATE {p}content SET type = ?, parent_id = ?, position = ?, slug = ?, path = ?, title = ?, status = ?, visibility = ?,
                    view_password_hash = ?, excerpt = ?, meta_description = ?, seo_title = ?, og_image_media_id = ?, noindex = ?,
                    published_at = ?, updated_at = ? WHERE id = ?',
                [
                    $c['type'], $c['parent_id'], $position, $c['slug'], $c['path'], $c['title'], $c['status'], $c['visibility'], $hash,
                    $excerpt, $c['meta_description'] !== '' ? $c['meta_description'] : null,
                    $c['seo_title'] !== '' ? $c['seo_title'] : null, $c['og_image_media_id'], $c['noindex'],
                    $publishedAt, $now, $id,
                ]
            );
            if ($current['path'] !== $c['path']) {
                $this->repath($id, (string) $current['path'], $c['path']);
            }
            $this->syncExtras($id, $c, $current);
            if (!$this->store->write($id, $c['body'])) {
                throw new \RuntimeException('Could not write the content file. Your previous version is kept in the revision history.');
            }
        });
    }

    /** Bulk publish/unpublish of non-trashed items. Returns how many rows changed. */
    public function setStatus(array $ids, string $status): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $i): bool => $i > 0)));
        if ($ids === [] || !in_array($status, ['draft', 'published'], true)) {
            return 0;
        }
        $now = gmdate('Y-m-d H:i:s');
        $in = implode(',', array_fill(0, count($ids), '?'));
        return $this->db->run(
            "UPDATE {p}content SET status = ?, published_at = IF(? = 'published' AND published_at IS NULL, ?, published_at), updated_at = ?
             WHERE deleted_at IS NULL AND id IN ($in)",
            array_merge([$status, $status, $now, $now], $ids)
        )->rowCount();
    }

    /** @return string|null an error message, or null on success */
    public function trash(int $id): ?string
    {
        $row = $this->repo->find($id);
        if ($row === null || $row['deleted_at'] !== null) {
            return 'That item was not found.';
        }
        if ($this->repo->childCount($id) > 0) {
            return '"' . $row['title'] . '" has child pages. Move or delete them first.';
        }
        $now = gmdate('Y-m-d H:i:s');
        $this->db->run('UPDATE {p}content SET deleted_at = ?, updated_at = ? WHERE id = ?', [$now, $now, $id]);
        return null;
    }

    public function restore(int $id): ?string
    {
        $row = $this->repo->find($id);
        if ($row === null || $row['deleted_at'] === null) {
            return 'That item is not in the trash.';
        }
        if ($row['parent_id'] !== null) {
            $parent = $this->repo->find((int) $row['parent_id']);
            if ($parent === null || $parent['deleted_at'] !== null) {
                return 'Restore the parent page first.';
            }
        }
        $this->db->run('UPDATE {p}content SET deleted_at = NULL, updated_at = ? WHERE id = ?', [gmdate('Y-m-d H:i:s'), $id]);
        return null;
    }

    /** Permanently remove a trashed item. Its files are archived to user/backups/deleted/, never deleted. */
    public function purge(int $id): ?string
    {
        $row = $this->repo->find($id);
        if ($row === null || $row['deleted_at'] === null) {
            return 'Only items in the trash can be deleted permanently.';
        }
        if ($this->repo->childCount($id, true) > 0) {
            return 'This item still has child pages in the trash. Delete those first.';
        }
        unset($row['view_password_hash']);
        $this->db->run('DELETE FROM {p}content WHERE id = ?', [$id]);
        $this->index->delete($id);
        if ($this->store->archive($id, $row) === null) {
            Logger::error('Could not archive files of purged content', ['id' => $id]);
        }
        return null;
    }

    /** Move a Book Article up or down among its siblings. */
    public function move(int $id, string $direction): void
    {
        $row = $this->repo->find($id);
        if ($row === null || $row['type'] !== 'book' || $row['deleted_at'] !== null || !in_array($direction, ['up', 'down'], true)) {
            return;
        }
        $ids = $this->repo->siblingIds($row['parent_id'] === null ? null : (int) $row['parent_id']);
        $i = array_search($id, $ids, true);
        $j = $direction === 'up' ? $i - 1 : $i + 1;
        if ($i === false || !isset($ids[$j])) {
            return;
        }
        [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
        $this->db->transaction(function (Database $db) use ($ids): void {
            foreach ($ids as $pos => $siblingId) {
                $db->run('UPDATE {p}content SET position = ? WHERE id = ?', [$pos + 1, $siblingId]);
            }
        });
    }

    /** Put an earlier title and body back. The state being replaced becomes a new revision. */
    public function restoreRevision(int $id, int $revisionId, int $userId): ?string
    {
        $current = $this->repo->find($id);
        $rev = $this->db->one('SELECT * FROM {p}content_revisions WHERE id = ? AND content_id = ?', [$revisionId, $id]);
        if ($current === null || $rev === null) {
            return 'That revision was not found.';
        }
        $body = $this->store->readRevision($id, $revisionId);
        if ($body === null) {
            return 'The file for that revision is missing, so it cannot be restored.';
        }
        $clean = [
            'type' => $current['type'], 'parent_id' => $current['parent_id'] === null ? null : (int) $current['parent_id'],
            'slug' => $current['slug'], 'path' => $current['path'], 'title' => $rev['title'], 'status' => $current['status'],
            'visibility' => $current['visibility'], 'new_password' => '', 'excerpt' => (string) $current['excerpt'],
            'meta_description' => (string) $current['meta_description'], 'noindex' => (int) $current['noindex'],
            'seo_title' => (string) $current['seo_title'], 'og_image_media_id' => $current['og_image_media_id'] === null ? null : (int) $current['og_image_media_id'],
            'tags' => array_column((new TagRepository($this->db))->forContent($id), 'name'),
            'asset_ids' => (new CodeAssetRepository($this->db))->linkedIds($id),
            'body' => $this->sanitizer->clean($body),
        ];
        $this->update($id, $clean, $userId, 'before restore');
        return null;
    }

    /**
     * Everything stored beside the content row, inside the caller's transaction: stale redirect cleanup, linked stylesheets and
     * scripts, tags, and the search index entry.
     * @param array|null $before the row as it was before this save (null for a new item)
     */
    private function syncExtras(int $id, array $c, ?array $before): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->db->run('DELETE FROM {p}content_redirects WHERE old_path = ?', [$c['path']]); // a live address always wins
        $this->db->run('DELETE FROM {p}content_assets WHERE content_id = ?', [$id]);
        foreach ($c['asset_ids'] as $assetId) {
            $this->db->run('INSERT INTO {p}content_assets (content_id, asset_id) VALUES (?, ?)', [$id, $assetId]);
        }
        $this->tags->sync($id, $c['tags']);
        $this->index->upsert($id, $c['title'], implode(' ', $c['tags']), $c['body']);
    }

    /**
     * An item's address changed (its slug or its parent): give every descendant its new address and keep every old address
     * working through a redirect. The item's own row is already updated by the caller. Runs inside the caller's transaction.
     */
    private function repath(int $id, string $oldPath, string $newPath): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $newPaths = [$id => $newPath];
        $this->db->run('REPLACE INTO {p}content_redirects (old_path, content_id, created_at) VALUES (?, ?, ?)', [$oldPath, $id, $now]);
        $descendants = $this->repo->descendantIds($id); // parents come before their children
        foreach (array_chunk($descendants, 400) as $chunk) {
            $rows = $this->db->all('SELECT id, parent_id, slug, path FROM {p}content WHERE id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')', $chunk);
            $rows = array_column($rows, null, 'id');
            foreach ($chunk as $d) {
                $row = $rows[$d] ?? null;
                if ($row === null || !isset($newPaths[(int) $row['parent_id']])) {
                    continue;
                }
                $path = $newPaths[(int) $row['parent_id']] . '/' . $row['slug'];
                $newPaths[$d] = $path;
                if ($path !== $row['path']) {
                    $this->db->run('UPDATE {p}content SET path = ? WHERE id = ?', [$path, $d]);
                    $this->db->run('REPLACE INTO {p}content_redirects (old_path, content_id, created_at) VALUES (?, ?, ?)', [$row['path'], $d, $now]);
                }
            }
        }
        foreach ($newPaths as $path) {
            $this->db->run('DELETE FROM {p}content_redirects WHERE old_path = ?', [$path]); // a live address always wins
        }
    }

    private function snapshot(int $id, string $title, string $body, int $userId, string $note): void
    {
        $this->db->run(
            'INSERT INTO {p}content_revisions (content_id, title, note, created_by, created_at) VALUES (?, ?, ?, ?, ?)',
            [$id, $title, mb_substr($note, 0, 40), $userId, gmdate('Y-m-d H:i:s')]
        );
        $revisionId = $this->db->insertId();
        if (!$this->store->writeRevision($id, $revisionId, $body)) {
            Logger::error('Could not write revision file', ['content_id' => $id, 'revision' => $revisionId]);
        }
        // Keep the newest KEEP_REVISIONS; remove older rows and their files.
        $old = $this->db->all(
            'SELECT id FROM {p}content_revisions WHERE content_id = ? ORDER BY id DESC LIMIT 1000 OFFSET ' . self::KEEP_REVISIONS,
            [$id]
        );
        foreach ($old as $r) {
            $this->db->run('DELETE FROM {p}content_revisions WHERE id = ?', [$r['id']]);
            $this->store->deleteRevision($id, (int) $r['id']);
        }
    }

    public static function autoExcerpt(string $html): string
    {
        $text = html_entity_decode((string) preg_replace('/<[^>]*>/', ' ', $html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        return mb_strlen($text) > 200 ? rtrim(mb_substr($text, 0, 200)) . '…' : $text;
    }
}
