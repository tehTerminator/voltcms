<?php
declare(strict_types=1);

namespace Cms;

/** Read access to code assets (CSS/JS files linked to content). Writes live in core/admin/CodeAssetWriter. */
final class CodeAssetRepository
{
    public function __construct(private Database $db)
    {
    }

    public function find(int $id): ?array
    {
        return $this->db->one('SELECT * FROM {p}code_assets WHERE id = ?', [$id]);
    }

    /** @return array[] all assets, optionally of one kind, in load order */
    public function all(?string $kind = null): array
    {
        return $kind === null
            ? $this->db->all('SELECT * FROM {p}code_assets ORDER BY kind, load_order, name LIMIT 1000')
            : $this->db->all('SELECT * FROM {p}code_assets WHERE kind = ? ORDER BY load_order, name LIMIT 1000', [$kind]);
    }

    /** Public URL of the asset file, versioned so browsers re-download it after every change. */
    public static function url(array $row): string
    {
        return Url::to('/user/assets/' . $row['kind'] . '/' . rawurlencode((string) $row['file'])) . '?v=' . $row['version_hash'];
    }

    /** @return int[] ids of assets linked to a content item */
    public function linkedIds(int $contentId): array
    {
        return array_map('intval', array_column($this->db->all('SELECT asset_id FROM {p}content_assets WHERE content_id = ?', [$contentId]), 'asset_id'));
    }

    /** @return array{css:string[],js:string[]} URLs to load for a content item, in load order */
    public function urlsForContent(int $contentId): array
    {
        $out = ['css' => [], 'js' => []];
        $rows = $this->db->all(
            'SELECT a.kind, a.file, a.version_hash FROM {p}content_assets ca JOIN {p}code_assets a ON a.id = ca.asset_id
             WHERE ca.content_id = ? ORDER BY a.load_order, a.name',
            [$contentId]
        );
        foreach ($rows as $r) {
            if (isset($out[$r['kind']])) {
                $out[$r['kind']][] = self::url($r);
            }
        }
        return $out;
    }

    /** @return array<int,int> asset id => number of content items using it */
    public function usageCounts(): array
    {
        $out = [];
        foreach ($this->db->all('SELECT asset_id, COUNT(*) AS n FROM {p}content_assets GROUP BY asset_id') as $r) {
            $out[(int) $r['asset_id']] = (int) $r['n'];
        }
        return $out;
    }

    /** @return array[] content rows (id, title, slug, status) using an asset */
    public function usedBy(int $assetId): array
    {
        return $this->db->all(
            'SELECT c.id, c.title, c.slug, c.status, c.deleted_at FROM {p}content_assets ca JOIN {p}content c ON c.id = ca.content_id
             WHERE ca.asset_id = ? ORDER BY c.title LIMIT 200',
            [$assetId]
        );
    }

    /** @return array{missing:array,orphans:string[]} report only: rows without a file, files without a row */
    public function integrity(CodeAssetStore $store): array
    {
        $known = [];
        $missing = [];
        foreach ($this->all() as $r) {
            $known[$r['kind']][$r['file']] = true;
            if (!$store->exists($r['kind'], $r['file'])) {
                $missing[] = $r;
            }
        }
        $orphans = [];
        foreach ($store->scan() as $kind => $files) {
            foreach ($files as $f) {
                if (!isset($known[$kind][$f])) {
                    $orphans[] = $kind . '/' . $f;
                }
            }
        }
        return ['missing' => $missing, 'orphans' => $orphans];
    }
}
