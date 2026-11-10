<?php
declare(strict_types=1);

namespace Cms;

/** Read access to tags. Writes live in core/admin/TagWriter. */
final class TagRepository
{
    private const PUBLIC_JOIN = "c.deleted_at IS NULL AND c.status = 'published' AND c.visibility = 'public'";

    public function __construct(private Database $db)
    {
    }

    public function find(int $id): ?array
    {
        return $this->db->one('SELECT * FROM {p}tags WHERE id = ?', [$id]);
    }

    public function findBySlug(string $slug): ?array
    {
        return $this->db->one('SELECT * FROM {p}tags WHERE slug = ?', [$slug]);
    }

    /** @return array[] tags of one content item, by name */
    public function forContent(int $contentId): array
    {
        return $this->db->all(
            'SELECT t.id, t.name, t.slug FROM {p}content_tags ct JOIN {p}tags t ON t.id = ct.tag_id WHERE ct.content_id = ? ORDER BY t.name',
            [$contentId]
        );
    }

    /**
     * Tags with usage counts for the admin: total items and items visible to the public.
     * @return array{rows:array,total:int}
     */
    public function adminList(int $page, int $perPage): array
    {
        $total = (int) $this->db->value('SELECT COUNT(*) FROM {p}tags');
        $rows = $this->db->all(
            'SELECT t.id, t.name, t.slug, COUNT(c.id) AS items,
                    COALESCE(SUM(' . self::PUBLIC_JOIN . '), 0) AS public_items
             FROM {p}tags t LEFT JOIN {p}content_tags ct ON ct.tag_id = t.id LEFT JOIN {p}content c ON c.id = ct.content_id
             GROUP BY t.id, t.name, t.slug ORDER BY t.name LIMIT ' . (int) $perPage . ' OFFSET ' . (int) max(0, ($page - 1) * $perPage)
        );
        return ['rows' => $rows, 'total' => $total];
    }

    /** Published, public items with a tag, newest first. @return array{rows:array,total:int} */
    public function publicItems(int $tagId, int $page, int $perPage): array
    {
        $total = (int) $this->db->value(
            'SELECT COUNT(*) FROM {p}content_tags ct JOIN {p}content c ON c.id = ct.content_id WHERE ct.tag_id = ? AND ' . self::PUBLIC_JOIN,
            [$tagId]
        );
        $rows = $this->db->all(
            'SELECT c.id, c.type, c.slug, c.path, c.title, c.excerpt, c.published_at FROM {p}content_tags ct JOIN {p}content c ON c.id = ct.content_id
             WHERE ct.tag_id = ? AND ' . self::PUBLIC_JOIN . ' ORDER BY c.published_at DESC, c.id DESC LIMIT ' . (int) $perPage . ' OFFSET ' . (int) max(0, ($page - 1) * $perPage),
            [$tagId]
        );
        return ['rows' => $rows, 'total' => $total];
    }
}
