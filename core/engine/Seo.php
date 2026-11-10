<?php
declare(strict_types=1);

namespace Cms;

/**
 * Builds the `$seo` theme variable: canonical URL, robots directive, Open Graph and Twitter card tags, feed link.
 *
 *  - Absolute URLs need the site URL from Site settings. Without it, canonical, og:url, images and the feed link are omitted.
 *  - Pages that must not be indexed (noindex flag, private pages) get a robots directive and no canonical or social tags,
 *    so nothing about a private page is offered to link previews.
 *  - Description fallback: the page's meta description, then its excerpt, then the site description.
 *  - Social image fallback: the page's own image, then the site's default social image.
 * The theme prints these values (and escapes them); nothing here produces HTML.
 */
final class Seo
{
    private const DESCRIPTION_MAX = 300;

    /** Plain text, whitespace collapsed, cut at a word boundary where possible. */
    public static function clip(string $text, int $max = self::DESCRIPTION_MAX): string
    {
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        if (mb_strlen($text) <= $max) {
            return $text;
        }
        $cut = mb_substr($text, 0, $max - 1);
        $space = mb_strrpos($cut, ' ');
        return rtrim($space !== false && $space > $max * 0.6 ? mb_substr($cut, 0, $space) : $cut, " ,;:.-") . '…';
    }

    /** Absolute URL for an application path such as "/tag/law" or "/?page=2"; '' when no site URL is configured. */
    public static function absolute(array $site, string $appPath): string
    {
        return ($site['url'] ?? '') === '' ? '' : $site['url'] . $appPath;
    }

    /** @return array{title:string,description:string,canonical:string,robots:string,og:array<string,string>,twitter:array<string,string>,feed:string} */
    public static function forContent(array $row, array $site, ?array $imageRow): array
    {
        $private = ($row['visibility'] ?? 'public') === 'private';
        $noindex = (int) ($row['noindex'] ?? 0) === 1 || $private;
        $seo = self::empty($site);
        $seo['title'] = trim((string) ($row['seo_title'] ?? ''));
        if ($private) {
            $seo['robots'] = 'noindex, nofollow';
            return $seo; // no description, canonical or social tags for private pages
        }
        $seo['description'] = self::clip((string) ($row['meta_description'] ?? '')) ?: self::clip((string) ($row['excerpt'] ?? '')) ?: self::clip((string) ($site['description'] ?? ''));
        if ($noindex) {
            $seo['robots'] = 'noindex, nofollow';
            return $seo;
        }
        $url = self::absolute($site, Url::contentPath((string) $row['path']));
        $seo['canonical'] = $url;
        $title = $seo['title'] !== '' ? $seo['title'] : (string) $row['title'];
        $image = '';
        $imageAlt = '';
        if (($site['url'] ?? '') !== '') {
            if ($imageRow !== null) {
                $image = $site['url'] . '/user/uploads/' . $imageRow['path'];
                $imageAlt = (string) ($imageRow['alt_text'] ?? '');
            } elseif (($site['social_image_path'] ?? '') !== '') {
                $image = $site['url'] . '/user/uploads/' . $site['social_image_path'];
            }
        }
        $og = ['og:site_name' => (string) $site['name'], 'og:title' => $title, 'og:description' => $seo['description'],
            'og:type' => ($row['type'] ?? '') === 'article' ? 'article' : 'website', 'og:url' => $url, 'og:image' => $image, 'og:image:alt' => $image === '' ? '' : $imageAlt];
        if (($row['type'] ?? '') === 'article' && ($row['published_at'] ?? null) !== null) {
            $og['article:published_time'] = self::iso((string) $row['published_at']);
            $og['article:modified_time'] = self::iso((string) ($row['updated_at'] ?? $row['published_at']));
        }
        $seo['og'] = self::filled($og);
        $seo['twitter'] = self::filled(['twitter:card' => $image !== '' ? 'summary_large_image' : 'summary', 'twitter:title' => $title,
            'twitter:description' => $seo['description'], 'twitter:image' => $image]);
        return $seo;
    }

    /**
     * For lists and utility pages (home, tag, search).
     * @param string $canonicalPath application path (with query) of the canonical URL, or '' for none
     */
    public static function forList(array $site, string $title, string $canonicalPath, string $description = '', string $robots = ''): array
    {
        $seo = self::empty($site);
        $seo['robots'] = $robots;
        $seo['description'] = self::clip($description) ?: self::clip((string) ($site['description'] ?? ''));
        if ($canonicalPath !== '' && $robots === '') {
            $url = self::absolute($site, $canonicalPath);
            $seo['canonical'] = $url;
            $full = $title === '' ? (string) $site['name'] : $title . ' | ' . $site['name'];
            $seo['og'] = self::filled(['og:site_name' => (string) $site['name'], 'og:title' => $full, 'og:description' => $seo['description'], 'og:type' => 'website', 'og:url' => $url,
                'og:image' => ($site['url'] ?? '') !== '' && ($site['social_image_path'] ?? '') !== '' ? $site['url'] . '/user/uploads/' . $site['social_image_path'] : '']);
            $seo['twitter'] = self::filled(['twitter:card' => isset($seo['og']['og:image']) ? 'summary_large_image' : 'summary', 'twitter:title' => $full,
                'twitter:description' => $seo['description'], 'twitter:image' => $seo['og']['og:image'] ?? '']);
        }
        return $seo;
    }

    private static function empty(array $site): array
    {
        return ['title' => '', 'description' => '', 'canonical' => '', 'robots' => '', 'og' => [], 'twitter' => [], 'feed' => self::absolute($site, '/feed.xml')];
    }

    /** @param array<string,string> $tags @return array<string,string> without empty values */
    private static function filled(array $tags): array
    {
        return array_filter($tags, static fn (string $v): bool => $v !== '');
    }

    private static function iso(string $utc): string
    {
        $ts = strtotime($utc . ' UTC');
        return $ts === false ? '' : gmdate('c', $ts);
    }
}
