<?php
declare(strict_types=1);

namespace Cms;

/** Read access to content metadata (used by the public site and the admin). Writes live in core/admin/ContentWriter. */
final class ContentRepository
{
    public const TYPES = ['page' => 'Page', 'article' => 'Article', 'book' => 'Book Article'];

    private const LIST_COLS = 'id, type, parent_id, position, slug, path, title, status, visibility, excerpt, meta_description, noindex, published_at, created_at, updated_at, deleted_at';
    private const PUBLIC_WHERE = "deleted_at IS NULL AND status = 'published' AND visibility = 'public'";

    public function __construct(private Database $db)
    {
    }

    public function find(int $id): ?array
    {
        return $this->db->one('SELECT * FROM {p}content WHERE id = ?', [$id]);
    }

    /** A non-trashed item by its full address (e.g. "book/part-one"), any status (callers decide what is visible). */
    public function findByPath(string $path): ?array
    {
        return $this->db->one('SELECT * FROM {p}content WHERE path = ? AND deleted_at IS NULL', [$path]);
    }

    /** Trashed items still hold their address (unique index), so they count as taken. */
    public function pathTaken(string $path, ?int $exceptId = null): bool
    {
        return (int) $this->db->value('SELECT COUNT(*) FROM {p}content WHERE path = ? AND id <> ?', [$path, $exceptId ?? 0]) > 0;
    }

    /**
     * Where an address that no longer exists should be sent: the item's current address, but only when that item is
     * published and public (so a redirect never reveals a private or draft page).
     */
    public function redirectTarget(string $oldPath): ?string
    {
        $row = $this->db->one(
            "SELECT c.path FROM {p}content_redirects r JOIN {p}content c ON c.id = r.content_id
             WHERE r.old_path = ? AND c.deleted_at IS NULL AND c.status = 'published' AND c.visibility = 'public'",
            [$oldPath]
        );
        return $row === null ? null : (string) $row['path'];
    }

    /** @return array<int,string> id => path of every descendant (any state) of an item */
    public function descendantPaths(int $id): array
    {
        $out = [];
        foreach ($this->descendantIds($id) as $d) {
            $row = $this->db->one('SELECT path FROM {p}content WHERE id = ?', [$d]);
            if ($row !== null) {
                $out[$d] = (string) $row['path'];
            }
        }
        return $out;
    }

    /** @return array{published:int,draft:int,trash:int} */
    public function counts(): array
    {
        $out = ['published' => 0, 'draft' => 0, 'trash' => 0];
        foreach ($this->db->all('SELECT status, COUNT(*) AS n FROM {p}content WHERE deleted_at IS NULL GROUP BY status') as $r) {
            if (isset($out[$r['status']])) {
                $out[$r['status']] = (int) $r['n'];
            }
        }
        $out['trash'] = (int) $this->db->value('SELECT COUNT(*) FROM {p}content WHERE deleted_at IS NOT NULL');
        return $out;
    }

    /**
     * Filtered, paginated list for the admin. Filters: q (title contains), type, status (draft|published|trash).
     * @return array{rows:array,total:int}
     */
    public function adminList(array $filters, int $page, int $perPage): array
    {
        $where = [];
        $params = [];
        $status = (string) ($filters['status'] ?? '');
        $where[] = $status === 'trash' ? 'deleted_at IS NOT NULL' : 'deleted_at IS NULL';
        if ($status === 'draft' || $status === 'published') {
            $where[] = 'status = ?';
            $params[] = $status;
        }
        $type = (string) ($filters['type'] ?? '');
        if (isset(self::TYPES[$type])) {
            $where[] = 'type = ?';
            $params[] = $type;
        }
        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $where[] = "title LIKE ? ESCAPE '!'";
            $params[] = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q) . '%';
        }
        $w = implode(' AND ', $where);
        $total = (int) $this->db->value('SELECT COUNT(*) FROM {p}content WHERE ' . $w, $params);
        $offset = max(0, ($page - 1) * $perPage);
        $rows = $this->db->all(
            'SELECT ' . self::LIST_COLS . ' FROM {p}content WHERE ' . $w
            . ' ORDER BY updated_at DESC, id DESC LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset,
            $params
        );
        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * All non-trashed Book Articles in outline order with a 'depth' key.
     * $excludeSubtreeOf omits that item and everything below it (used for the parent picker).
     */
    public function bookOutline(?int $excludeSubtreeOf = null): array
    {
        $rows = $this->db->all(
            "SELECT id, parent_id, position, slug, path, title, status, visibility, updated_at FROM {p}content
             WHERE type = 'book' AND deleted_at IS NULL ORDER BY position, id LIMIT 2000"
        );
        $byParent = [];
        $ids = array_column($rows, 'id');
        foreach ($rows as $r) {
            $parent = $r['parent_id'] !== null && in_array($r['parent_id'], $ids, false) ? (int) $r['parent_id'] : 0;
            $byParent[$parent][] = $r;
        }
        $out = [];
        $visit = function (int $parent, int $depth) use (&$visit, &$out, $byParent, $excludeSubtreeOf): void {
            foreach ($byParent[$parent] ?? [] as $r) {
                if ($excludeSubtreeOf !== null && (int) $r['id'] === $excludeSubtreeOf) {
                    continue;
                }
                $r['depth'] = $depth;
                $out[] = $r;
                if ($depth < 12) {
                    $visit((int) $r['id'], $depth + 1);
                }
            }
        };
        $visit(0, 0);
        return $out;
    }

    /** Ancestors from the root down to the direct parent. */
    public function ancestors(int $id): array
    {
        $chain = [];
        $current = $this->db->one('SELECT parent_id FROM {p}content WHERE id = ?', [$id]);
        for ($i = 0; $i < 12 && $current !== null && $current['parent_id'] !== null; $i++) {
            $parent = $this->db->one(
                'SELECT id, parent_id, slug, path, title, status, visibility, deleted_at FROM {p}content WHERE id = ?',
                [(int) $current['parent_id']]
            );
            if ($parent === null) {
                break;
            }
            array_unshift($chain, $parent);
            $current = $parent;
        }
        return $chain;
    }

    /** @return int[] */
    public function descendantIds(int $id): array
    {
        $all = [];
        $frontier = [$id];
        for ($depth = 0; $depth < 12 && $frontier !== [] && count($all) < 2000; $depth++) {
            $in = implode(',', array_fill(0, count($frontier), '?'));
            $frontier = array_map('intval', array_column(
                $this->db->all('SELECT id FROM {p}content WHERE parent_id IN (' . $in . ')', $frontier),
                'id'
            ));
            $all = array_merge($all, $frontier);
        }
        return $all;
    }

    public function childCount(int $id, bool $includeTrashed = false): int
    {
        return (int) $this->db->value(
            'SELECT COUNT(*) FROM {p}content WHERE parent_id = ?' . ($includeTrashed ? '' : ' AND deleted_at IS NULL'),
            [$id]
        );
    }

    /** @return int[] sibling ids (Book Articles under the same parent) in order */
    public function siblingIds(?int $parentId): array
    {
        return array_map('intval', array_column($this->db->all(
            "SELECT id FROM {p}content WHERE type = 'book' AND parent_id <=> ? AND deleted_at IS NULL ORDER BY position, id",
            [$parentId]
        ), 'id'));
    }

    public function revisions(int $contentId): array
    {
        return $this->db->all(
            'SELECT r.id, r.title, r.note, r.created_at, u.username
             FROM {p}content_revisions r LEFT JOIN {p}users u ON u.id = r.created_by
             WHERE r.content_id = ? ORDER BY r.id DESC LIMIT 50',
            [$contentId]
        );
    }

    // ---- public site (published + public + not trashed only) -------------------------------

    public function publicChildren(int $parentId): array
    {
        return $this->db->all(
            'SELECT id, slug, path, title, excerpt FROM {p}content WHERE parent_id = ? AND ' . self::PUBLIC_WHERE . ' ORDER BY position, id',
            [$parentId]
        );
    }

    /** @return array{prev:?array,next:?array} neighbours among public siblings of a Book Article */
    public function publicNeighbors(array $row): array
    {
        $out = ['prev' => null, 'next' => null];
        if ($row['parent_id'] === null) {
            return $out;
        }
        $siblings = $this->publicChildren((int) $row['parent_id']);
        foreach ($siblings as $i => $s) {
            if ((int) $s['id'] === (int) $row['id']) {
                $out['prev'] = $siblings[$i - 1] ?? null;
                $out['next'] = $siblings[$i + 1] ?? null;
                break;
            }
        }
        return $out;
    }

    /** @return array{rows:array,total:int} */
    public function latestPublicArticles(int $page, int $perPage): array
    {
        $total = (int) $this->db->value("SELECT COUNT(*) FROM {p}content WHERE type = 'article' AND " . self::PUBLIC_WHERE);
        $rows = $this->db->all(
            "SELECT id, slug, path, title, excerpt, published_at FROM {p}content WHERE type = 'article' AND " . self::PUBLIC_WHERE
            . ' ORDER BY published_at DESC, id DESC LIMIT ' . (int) $perPage . ' OFFSET ' . (int) max(0, ($page - 1) * $perPage)
        );
        return ['rows' => $rows, 'total' => $total];
    }

    // ---- integrity ----------------------------------------------------------------------------

    /**
     * Report-only consistency check. Never changes or deletes anything.
     * @return array{missing:array,orphans:int[]} missing = records without a body file; orphans = body files without a record
     */
    public function integrity(ContentStore $store): array
    {
        $rows = $this->db->all('SELECT id, slug, path, title, deleted_at FROM {p}content ORDER BY id');
        $known = [];
        $missing = [];
        foreach ($rows as $r) {
            $known[(int) $r['id']] = true;
            if (!$store->exists((int) $r['id'])) {
                $missing[] = $r;
            }
        }
        $orphans = array_values(array_filter($store->scanPostIds(), static fn (int $id): bool => !isset($known[$id])));
        return ['missing' => $missing, 'orphans' => $orphans];
    }
}
