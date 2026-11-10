<?php
declare(strict_types=1);

namespace Cms;

/**
 * Search over title, tags and body text kept in the search_index table, so no content file is read at search time.
 *
 * Two query paths:
 *  - FULLTEXT (InnoDB MATCH ... AGAINST in boolean mode, prefix match) when the table has the index and every word is plain
 *    ASCII letters/digits of at least the server's minimum token length and not a stopword. Fast, ranked by relevance.
 *  - LIKE '%word%' for everything else (Hindi and other non-Latin text, short words, stopwords, servers without FULLTEXT).
 *    All words must match somewhere in title, tags or body; title matches rank first. Slower on very large sites.
 * MySQL's FULLTEXT tokenizer does not split Devanagari and other non-Latin scripts reliably, which is why those use LIKE.
 * Only published, public, non-trashed items are ever returned, whatever the index contains.
 */
final class SearchIndex
{
    private const MAX_TEXT = 200000;
    private const STOPWORDS = ['about', 'are', 'com', 'for', 'from', 'how', 'that', 'the', 'this', 'was', 'what', 'when', 'where', 'who', 'will', 'with', 'und', 'www'];
    private static ?int $minToken = null;

    public function __construct(private Database $db)
    {
    }

    public function mode(): string
    {
        return (new Settings($this->db))->get('search_mode', 'like') === 'fulltext' ? 'fulltext' : 'like';
    }

    public static function plainText(string $html): string
    {
        $text = html_entity_decode((string) preg_replace('/<[^>]*>/', ' ', $html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return mb_substr(trim((string) preg_replace('/\s+/u', ' ', $text)), 0, self::MAX_TEXT);
    }

    /** @return string[] lower-case words of a query (letters, marks, digits), at most 8 */
    public static function terms(string $query): array
    {
        $words = preg_split('/[^\p{L}\p{M}\p{N}]+/u', mb_strtolower(mb_substr($query, 0, 200)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $words = array_values(array_unique(array_filter($words, static fn (string $w): bool => mb_strlen($w) <= 50)));
        return array_slice($words, 0, 8);
    }

    public function upsert(int $id, string $title, string $tagsText, string $bodyHtml): void
    {
        $this->db->run(
            'INSERT INTO {p}search_index (content_id, title, tags_text, body_text, updated_at) VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE title = VALUES(title), tags_text = VALUES(tags_text), body_text = VALUES(body_text), updated_at = VALUES(updated_at)',
            [$id, mb_substr($title, 0, 255), mb_substr($tagsText, 0, 1000), self::plainText($bodyHtml), gmdate('Y-m-d H:i:s')]
        );
    }

    public function delete(int $id): void
    {
        $this->db->run('DELETE FROM {p}search_index WHERE content_id = ?', [$id]);
    }

    /** Recompute the tags column of the given items (after a tag was renamed or deleted). */
    public function refreshTags(array $contentIds): void
    {
        $tags = new TagRepository($this->db);
        foreach (array_slice(array_map('intval', $contentIds), 0, 5000) as $id) {
            $text = implode(' ', array_column($tags->forContent($id), 'name'));
            $this->db->run('UPDATE {p}search_index SET tags_text = ? WHERE content_id = ?', [mb_substr($text, 0, 1000), $id]);
        }
    }

    /** @return array{rows:array,total:int} */
    public function search(string $query, int $page, int $perPage): array
    {
        $terms = self::terms($query);
        if ($terms === []) {
            return ['rows' => [], 'total' => 0];
        }
        $from = "FROM {p}search_index s JOIN {p}content c ON c.id = s.content_id
                 WHERE c.deleted_at IS NULL AND c.status = 'published' AND c.visibility = 'public'";
        $limit = ' LIMIT ' . (int) $perPage . ' OFFSET ' . (int) max(0, ($page - 1) * $perPage);
        $cols = 'c.id, c.type, c.slug, c.path, c.title, c.excerpt, c.published_at';

        if ($this->canUseFulltext($terms)) {
            $boolean = implode(' ', array_map(static fn (string $t): string => '+' . $t . '*', $terms));
            $match = 'MATCH(s.title, s.tags_text, s.body_text) AGAINST (? IN BOOLEAN MODE)';
            $total = (int) $this->db->value('SELECT COUNT(*) ' . $from . ' AND ' . $match, [$boolean]);
            $rows = $this->db->all(
                'SELECT ' . $cols . ' ' . $from . ' AND ' . $match . ' ORDER BY ' . $match . ' DESC, c.published_at DESC, c.id DESC' . $limit,
                [$boolean, $boolean]
            );
            return ['rows' => $rows, 'total' => $total];
        }

        $conds = [];
        $params = [];
        foreach ($terms as $t) {
            $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $t) . '%';
            $conds[] = "(s.title LIKE ? ESCAPE '!' OR s.tags_text LIKE ? ESCAPE '!' OR s.body_text LIKE ? ESCAPE '!')";
            array_push($params, $like, $like, $like);
        }
        $where = ' AND ' . implode(' AND ', $conds);
        $titleLike = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $terms[0]) . '%';
        $total = (int) $this->db->value('SELECT COUNT(*) ' . $from . $where, $params);
        $rows = $this->db->all(
            'SELECT ' . $cols . ' ' . $from . $where . " ORDER BY (s.title LIKE ? ESCAPE '!') DESC, c.published_at DESC, c.id DESC" . $limit,
            array_merge($params, [$titleLike])
        );
        return ['rows' => $rows, 'total' => $total];
    }

    private function canUseFulltext(array $terms): bool
    {
        if ($this->mode() !== 'fulltext') {
            return false;
        }
        self::$minToken ??= $this->minTokenSize();
        foreach ($terms as $t) {
            if (preg_match('/^[a-z0-9]+$/', $t) !== 1 || strlen($t) < self::$minToken || in_array($t, self::STOPWORDS, true)) {
                return false;
            }
        }
        return true;
    }

    private function minTokenSize(): int
    {
        try {
            $row = $this->db->one("SHOW VARIABLES LIKE 'innodb_ft_min_token_size'");
            return max(1, (int) ($row['Value'] ?? 3));
        } catch (\Throwable) {
            return 3;
        }
    }

    /**
     * Rebuild entries from the body files, resuming after $afterId and stopping at $deadline (microtime).
     * @return array{next:?int,done:int} next = last id processed when time ran out, null when finished
     */
    public function rebuild(ContentStore $store, int $afterId, int $limit, float $deadline): array
    {
        if ($afterId === 0) {
            $this->db->exec('DELETE FROM {p}search_index WHERE content_id NOT IN (SELECT id FROM {p}content)');
        }
        $tags = new TagRepository($this->db);
        $done = 0;
        while (true) {
            $rows = $this->db->all('SELECT id, title FROM {p}content WHERE id > ? AND deleted_at IS NULL ORDER BY id LIMIT ' . (int) $limit, [$afterId]);
            if ($rows === []) {
                return ['next' => null, 'done' => $done];
            }
            foreach ($rows as $r) {
                $this->upsert((int) $r['id'], (string) $r['title'], implode(' ', array_column($tags->forContent((int) $r['id']), 'name')), (string) ($store->read((int) $r['id']) ?? ''));
                $afterId = (int) $r['id'];
                $done++;
                if (microtime(true) > $deadline) {
                    return ['next' => $afterId, 'done' => $done];
                }
            }
        }
    }
}
