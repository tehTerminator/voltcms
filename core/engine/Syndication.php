<?php
declare(strict_types=1);

namespace Cms;

/**
 * robots.txt, sitemap.xml and the RSS feed.
 *
 *  - Only published, public, non-trashed items appear, and never items marked "do not index".
 *    Addresses are the items' full hierarchical addresses, so there are no duplicates.
 *  - Sitemap and feed need the site URL (Site settings); without it they answer 503 with an explanation.
 *  - Responses carry an ETag and answer a matching If-None-Match with 304, so crawlers and feed readers re-download nothing
 *    when nothing changed. The sitemap is streamed in batches and holds at most 50,000 URLs (the protocol limit).
 */
final class Syndication
{
    private const SITEMAP_MAX = 50000;
    private const FEED_ITEMS = 20;
    private const PUBLIC = "deleted_at IS NULL AND status = 'published' AND visibility = 'public'";

    public function __construct(private Database $db, private array $site)
    {
    }

    public function robotsTxt(): string
    {
        $out = "User-agent: *\nDisallow: /admin/\nDisallow: /search\nAllow: /\n";
        if ($this->site['url'] !== '') {
            $out .= "\nSitemap: " . $this->site['url'] . "/sitemap.xml\n";
        }
        return $out;
    }

    /** @return string|null the whole sitemap (tests, small sites); null when the site URL is missing */
    public function sitemapXml(): ?string
    {
        return $this->site['url'] === '' ? null : implode('', iterator_to_array($this->sitemapChunks(), false));
    }

    /** @return \Generator<int,string> the sitemap in pieces, so large sites never hold it all in memory */
    public function sitemapChunks(): \Generator
    {
        $stats = $this->sitemapStats();
        yield '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        $home = $this->site['url'] . '/';
        yield $this->urlEntry($home, $stats['modified']);
        $after = 0;
        $count = 1;
        while ($count < self::SITEMAP_MAX) {
            $rows = $this->db->all(
                'SELECT id, path, updated_at FROM {p}content WHERE id > ? AND noindex = 0 AND ' . self::PUBLIC . ' ORDER BY id LIMIT 2000',
                [$after]
            );
            if ($rows === []) {
                break;
            }
            $chunk = '';
            foreach ($rows as $r) {
                $after = (int) $r['id'];
                if ($count >= self::SITEMAP_MAX) {
                    Logger::error('Sitemap truncated at the 50,000 URL limit');
                    break;
                }
                $chunk .= $this->urlEntry($this->site['url'] . Url::contentPath((string) $r['path']), (string) $r['updated_at']);
                $count++;
            }
            yield $chunk;
        }
        yield "</urlset>\n";
    }

    /** @return array{etag:string,modified:string} cheap summary used for the ETag and the home page's lastmod */
    public function sitemapStats(): array
    {
        // SUM(CRC32(path)) makes a rename change the ETag even within the same second as the previous change
        $r = $this->db->one('SELECT COUNT(*) AS n, MAX(updated_at) AS m, MAX(id) AS i, SUM(CRC32(path)) AS c FROM {p}content WHERE noindex = 0 AND ' . self::PUBLIC) ?? [];
        $modified = (string) ($r['m'] ?? '') ?: gmdate('Y-m-d H:i:s');
        return ['etag' => 'W/"' . md5($r['n'] . '|' . $modified . '|' . $r['i'] . '|' . $r['c'] . '|' . $this->site['url']) . '"', 'modified' => $modified];
    }

    private function urlEntry(string $loc, string $updatedUtc): string
    {
        $ts = strtotime($updatedUtc . ' UTC');
        return '  <url><loc>' . self::xml($loc) . '</loc>' . ($ts === false ? '' : '<lastmod>' . gmdate('Y-m-d\TH:i:s\Z', $ts) . '</lastmod>') . "</url>\n";
    }

    /** @return string|null RSS 2.0 of the latest public articles; null when the site URL is missing */
    public function feedXml(): ?string
    {
        if ($this->site['url'] === '') {
            return null;
        }
        $rows = $this->db->all(
            "SELECT id, path, title, excerpt, published_at FROM {p}content WHERE type = 'article' AND noindex = 0 AND " . self::PUBLIC
            . ' ORDER BY published_at DESC, id DESC LIMIT ' . self::FEED_ITEMS
        );
        $tags = [];
        if ($rows !== []) {
            $ids = array_map('intval', array_column($rows, 'id'));
            foreach ($this->db->all(
                'SELECT ct.content_id, t.name FROM {p}content_tags ct JOIN {p}tags t ON t.id = ct.tag_id WHERE ct.content_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY t.name',
                $ids
            ) as $t) {
                $tags[(int) $t['content_id']][] = (string) $t['name'];
            }
        }
        $newest = $rows === [] ? time() : (int) strtotime((string) $rows[0]['published_at'] . ' UTC');
        $name = (string) $this->site['name'];
        $out = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom"><channel>' . "\n"
            . '  <title>' . self::xml($name) . "</title>\n"
            . '  <link>' . self::xml($this->site['url'] . '/') . "</link>\n"
            . '  <description>' . self::xml(Seo::clip((string) $this->site['description']) ?: $name) . "</description>\n"
            . '  <language>' . self::xml((string) $this->site['lang']) . "</language>\n"
            . '  <lastBuildDate>' . self::rfc822($newest) . "</lastBuildDate>\n"
            . '  <atom:link href="' . self::xml($this->site['url'] . '/feed.xml') . '" rel="self" type="application/rss+xml"/>' . "\n";
        foreach ($rows as $r) {
            $link = $this->site['url'] . Url::contentPath((string) $r['path']);
            $desc = Seo::clip((string) $r['excerpt'], 500);
            $out .= "  <item>\n    <title>" . self::xml((string) $r['title']) . "</title>\n    <link>" . self::xml($link) . "</link>\n"
                . '    <guid isPermaLink="true">' . self::xml($link) . "</guid>\n"
                . '    <pubDate>' . self::rfc822((int) strtotime((string) $r['published_at'] . ' UTC')) . "</pubDate>\n"
                . ($desc === '' ? '' : '    <description>' . self::xml($desc) . "</description>\n");
            foreach ($tags[(int) $r['id']] ?? [] as $tagName) {
                $out .= '    <category>' . self::xml($tagName) . "</category>\n";
            }
            $out .= "  </item>\n";
        }
        return $out . "</channel></rss>\n";
    }

    /** Escape for XML text/attributes and drop characters XML 1.0 forbids (control characters would make the document invalid). */
    public static function xml(string $text): string
    {
        $text = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $text) ?? '';
        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function rfc822(int $ts): string
    {
        return gmdate('D, d M Y H:i:s', $ts) . ' +0000';
    }

    /** Send one of the three documents with correct headers, conditional-GET support and HEAD handling. */
    public function serve(string $route, string $method): void
    {
        header('X-Content-Type-Options: nosniff');
        if ($method !== 'GET' && $method !== 'HEAD') {
            header('Allow: GET, HEAD');
            http_response_code(405);
            return;
        }
        if ($route === 'robots') {
            header('Content-Type: text/plain; charset=utf-8');
            header('Cache-Control: public, max-age=3600');
            echo $method === 'HEAD' ? '' : $this->robotsTxt();
            return;
        }
        if ($this->site['url'] === '') {
            http_response_code(503);
            header('Content-Type: text/plain; charset=utf-8');
            echo "Set the site URL in Admin > Site settings before using the sitemap and the feed.\n";
            return;
        }
        header('Cache-Control: public, max-age=600');
        if ($route === 'sitemap') {
            $etag = $this->sitemapStats()['etag'];
            header('Content-Type: application/xml; charset=utf-8');
            header('ETag: ' . $etag);
            if (self::notModified($etag)) {
                http_response_code(304);
                return;
            }
            if ($method === 'GET') {
                foreach ($this->sitemapChunks() as $chunk) {
                    echo $chunk;
                }
            }
            return;
        }
        $xml = (string) $this->feedXml();
        $etag = 'W/"' . md5($xml) . '"';
        header('Content-Type: application/rss+xml; charset=utf-8');
        header('ETag: ' . $etag);
        if (self::notModified($etag)) {
            http_response_code(304);
            return;
        }
        echo $method === 'HEAD' ? '' : $xml;
    }

    private static function notModified(string $etag): bool
    {
        $sent = (string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '');
        return $sent !== '' && in_array($etag, array_map('trim', explode(',', $sent)), true);
    }
}
