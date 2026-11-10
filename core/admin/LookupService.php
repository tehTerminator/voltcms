<?php
declare(strict_types=1);

namespace Cms;

/**
 * Search queries behind the admin autocomplete fields (parent page picker, media picker).
 * Each returns at most LIMIT items of the form {id, label, hint[, thumb]}; the browser builds the list with textContent only.
 */
final class LookupService
{
    public const LIMIT = 12;

    public function __construct(private Database $db)
    {
    }

    /**
     * Book Articles that can be a parent. With an empty query: the most recently changed ones.
     * @param int|null $excludeId an item being edited: it and everything below it are left out (a page cannot be its own parent)
     * @return array<int,array{id:int,label:string,hint:string}>
     */
    public function contentParents(string $query, ?int $excludeId = null): array
    {
        $q = trim(mb_substr($query, 0, 100));
        $where = "type = 'book' AND deleted_at IS NULL";
        $params = [];
        if ($excludeId !== null && $excludeId > 0) {
            $skip = array_slice(array_merge([$excludeId], (new ContentRepository($this->db))->descendantIds($excludeId)), 0, 2000);
            $where .= ' AND id NOT IN (' . implode(',', array_fill(0, count($skip), '?')) . ')';
            $params = $skip;
        }
        $order = 'updated_at DESC, id DESC';
        if ($q !== '') {
            $like = '%' . self::escapeLike($q) . '%';
            $where .= " AND (title LIKE ? ESCAPE '!' OR path LIKE ? ESCAPE '!')";
            array_push($params, $like, $like);
            $order = "(title LIKE ? ESCAPE '!') DESC, title, id";
            $params[] = self::escapeLike($q) . '%';
        }
        $rows = $this->db->all('SELECT id, title, path FROM {p}content WHERE ' . $where . ' ORDER BY ' . $order . ' LIMIT ' . self::LIMIT, $params);
        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'label' => (string) $r['title'], 'hint' => '/' . $r['path']], $rows);
    }

    /**
     * Images from the media library (name or alt text contains the query); newest first. Thumbnails are used for previews.
     * @return array<int,array{id:int,label:string,hint:string,thumb:string}>
     */
    public function images(string $query): array
    {
        $q = trim(mb_substr($query, 0, 100));
        $in = implode(',', array_fill(0, count(MediaRepository::IMAGE_MIMES), '?'));
        $where = 'mime IN (' . $in . ')';
        $params = MediaRepository::IMAGE_MIMES;
        if ($q !== '') {
            $like = '%' . self::escapeLike($q) . '%';
            $where .= " AND (original_name LIKE ? ESCAPE '!' OR alt_text LIKE ? ESCAPE '!')";
            array_push($params, $like, $like);
        }
        $rows = $this->db->all('SELECT * FROM {p}media WHERE ' . $where . ' ORDER BY id DESC LIMIT ' . self::LIMIT, $params);
        return array_map(static function (array $r): array {
            $alt = (string) ($r['alt_text'] ?? '');
            return [
                'id' => (int) $r['id'], 'label' => (string) $r['original_name'],
                'hint' => (int) $r['width'] . ' × ' . (int) $r['height'] . ' px' . ($alt !== '' ? ' · ' . mb_substr($alt, 0, 60) : ''),
                'thumb' => MediaRepository::thumbUrl($r),
            ];
        }, $rows);
    }

    private static function escapeLike(string $s): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $s);
    }
}
