<?php
declare(strict_types=1);

namespace Cms;

/** Read access to the media library. Files live in user/uploads/; writes live in core/admin/MediaWriter. */
final class MediaRepository
{
    public const IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    public function __construct(private Database $db)
    {
    }

    public function find(int $id): ?array
    {
        return $this->db->one('SELECT * FROM {p}media WHERE id = ?', [$id]);
    }

    /** @return array<int,array> id => row */
    public function findMany(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $i): bool => $i > 0)));
        if ($ids === []) {
            return [];
        }
        $rows = $this->db->all('SELECT * FROM {p}media WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids);
        return array_column($rows, null, 'id');
    }

    /** @return array{rows:array,total:int} */
    public function list(int $page, int $perPage, string $q = ''): array
    {
        $where = '1 = 1';
        $params = [];
        if ($q !== '') {
            $where = "(original_name LIKE ? ESCAPE '!' OR alt_text LIKE ? ESCAPE '!')";
            $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q) . '%';
            $params = [$like, $like];
        }
        $total = (int) $this->db->value('SELECT COUNT(*) FROM {p}media WHERE ' . $where, $params);
        $rows = $this->db->all(
            'SELECT * FROM {p}media WHERE ' . $where . ' ORDER BY created_at DESC, id DESC LIMIT ' . (int) $perPage . ' OFFSET ' . (int) max(0, ($page - 1) * $perPage),
            $params
        );
        return ['rows' => $rows, 'total' => $total];
    }

    /** Newest images, for pickers. */
    public function images(int $limit = 200): array
    {
        $in = implode(',', array_fill(0, count(self::IMAGE_MIMES), '?'));
        return $this->db->all('SELECT id, original_name, alt_text, path FROM {p}media WHERE mime IN (' . $in . ') ORDER BY id DESC LIMIT ' . (int) $limit, self::IMAGE_MIMES);
    }

    public static function isImage(array $row): bool
    {
        return in_array($row['mime'], self::IMAGE_MIMES, true);
    }

    /** URL for grids and previews: the thumbnail when there is one, otherwise the original file. */
    public static function thumbUrl(array $row): string
    {
        return Url::to('/user/uploads/' . (($row['thumb_path'] ?? null) !== null ? $row['thumb_path'] : $row['path']));
    }

    /** Images that no thumbnail was ever attempted for (uploaded before thumbnails existed, or while GD was missing). */
    public function missingThumbnailCount(): int
    {
        $in = implode(',', array_fill(0, count(self::IMAGE_MIMES), '?'));
        return (int) $this->db->value('SELECT COUNT(*) FROM {p}media WHERE thumb_status IS NULL AND mime IN (' . $in . ')', self::IMAGE_MIMES);
    }

    /** Root-relative URL (respects a subfolder install). Path segments are fixed-format, so no encoding is needed. */
    public static function url(array $row): string
    {
        return Url::to('/user/uploads/' . $row['path']);
    }

    /**
     * @return array{missing:array,orphans:string[],missing_thumbs:array} report only: rows without a file, files (thumbnails
     *         included) without a row, and thumbnails that are recorded but absent from disk
     */
    public function integrity(string $uploadDir): array
    {
        $known = [];
        $missing = [];
        $missingThumbs = [];
        foreach ($this->db->all('SELECT id, path, thumb_path, original_name FROM {p}media ORDER BY id') as $r) {
            $known[$r['path']] = true;
            if (!is_file($uploadDir . '/' . $r['path'])) {
                $missing[] = $r;
            }
            if ($r['thumb_path'] !== null) {
                $known[$r['thumb_path']] = true;
                if (!is_file($uploadDir . '/' . $r['thumb_path'])) {
                    $missingThumbs[] = $r;
                }
            }
        }
        $orphans = [];
        foreach (array_merge(
            glob($uploadDir . '/[0-9][0-9][0-9][0-9]/[0-9][0-9]/*') ?: [],
            glob($uploadDir . '/[0-9][0-9][0-9][0-9]/[0-9][0-9]/thumbs/*') ?: []
        ) as $f) {
            $rel = substr($f, strlen($uploadDir) + 1);
            if (is_file($f) && !isset($known[$rel]) && !str_ends_with($f, '.tmp')) {
                $orphans[] = $rel;
            }
        }
        return ['missing' => $missing, 'orphans' => $orphans, 'missing_thumbs' => $missingThumbs];
    }
}
