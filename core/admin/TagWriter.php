<?php
declare(strict_types=1);

namespace Cms;

/** Tag creation, assignment to content, renaming and deletion. Admin only. */
final class TagWriter
{
    public const MAX_PER_ITEM = 15;
    public const MAX_LENGTH = 60;

    public function __construct(private Database $db)
    {
    }

    /**
     * Turn "law, Constitution ,law" into ['law', 'Constitution'] (separators: comma, Hindi/Arabic comma, new line).
     * @return array{0:string[],1:string[]} [names, errors]
     */
    public static function parse(string $input): array
    {
        $names = [];
        $seen = [];
        $errors = [];
        foreach (preg_split('/[,\x{FF0C}\x{060C}\x{3001}\r\n]+/u', $input) ?: [] as $part) {
            $name = trim((string) preg_replace('/\s+/u', ' ', $part));
            if ($name === '') {
                continue;
            }
            if (mb_strlen($name) > self::MAX_LENGTH) {
                $errors[] = 'A tag may have at most ' . self::MAX_LENGTH . ' characters: "' . mb_substr($name, 0, 20) . '...".';
                continue;
            }
            $key = mb_strtolower($name);
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $names[] = $name;
            }
        }
        if (count($names) > self::MAX_PER_ITEM) {
            $errors[] = 'Use at most ' . self::MAX_PER_ITEM . ' tags per item.';
        }
        return [$names, $errors];
    }

    /** Make the item's tags exactly $names, creating tags that do not exist yet. Call inside a transaction. */
    public function sync(int $contentId, array $names): void
    {
        $ids = [];
        foreach ($names as $name) {
            $ids[] = $this->ensure((string) $name);
        }
        $this->db->run('DELETE FROM {p}content_tags WHERE content_id = ?', [$contentId]);
        foreach (array_unique($ids) as $tagId) {
            $this->db->run('INSERT INTO {p}content_tags (content_id, tag_id) VALUES (?, ?)', [$contentId, $tagId]);
        }
    }

    private function ensure(string $name): int
    {
        $row = $this->db->one('SELECT id FROM {p}tags WHERE name = ?', [$name]);
        if ($row !== null) {
            return (int) $row['id'];
        }
        $base = Slug::fromTitle($name);
        for ($n = 1; $n <= 200; $n++) {
            $slug = mb_substr($base, 0, 110) . ($n === 1 ? '' : '-' . $n);
            if ($this->db->one('SELECT id FROM {p}tags WHERE slug = ?', [$slug]) === null) {
                $this->db->run('INSERT INTO {p}tags (name, slug, created_at) VALUES (?, ?, ?)', [$name, $slug, gmdate('Y-m-d H:i:s')]);
                return $this->db->insertId();
            }
        }
        throw new \RuntimeException('Could not create a unique tag address for "' . $name . '".');
    }

    /** Rename a tag (its URL stays the same). @return string|null an error */
    public function rename(int $id, string $name): ?string
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));
        if ($name === '' || mb_strlen($name) > self::MAX_LENGTH || preg_match('/[,\x{FF0C}\x{060C}\x{3001}]/u', $name) === 1) {
            return 'Enter a tag name up to ' . self::MAX_LENGTH . ' characters, without commas.';
        }
        if ($this->db->one('SELECT id FROM {p}tags WHERE name = ? AND id <> ?', [$name, $id]) !== null) {
            return 'Another tag already has that name. To merge tags, give the items the other tag and delete this one.';
        }
        $this->db->run('UPDATE {p}tags SET name = ? WHERE id = ?', [$name, $id]);
        (new SearchIndex($this->db))->refreshTags($this->contentIds($id));
        return null;
    }

    /** Delete a tag from every item. The items themselves are untouched. */
    public function delete(int $id): void
    {
        $ids = $this->contentIds($id);
        $this->db->run('DELETE FROM {p}tags WHERE id = ?', [$id]);
        (new SearchIndex($this->db))->refreshTags($ids);
    }

    /** @return int[] */
    private function contentIds(int $tagId): array
    {
        return array_map('intval', array_column($this->db->all('SELECT content_id FROM {p}content_tags WHERE tag_id = ? LIMIT 5000', [$tagId]), 'content_id'));
    }
}
