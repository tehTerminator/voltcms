<?php
declare(strict_types=1);

namespace Cms;

/**
 * URL slugs. Unicode letters, marks and digits are allowed so non-Latin titles (for example Hindi)
 * produce readable slugs; separators are single hyphens. Slugs are unique across all content types.
 */
final class Slug
{
    /** Names that routes, files or later phases use. Content may never take them. */
    public const RESERVED = [
        'admin', 'install', 'index', 'router', 'core', 'user', 'uploads', 'assets', 'media', 'static',
        'feed', 'feed.xml', 'sitemap', 'sitemap.xml', 'robots', 'robots.txt', 'favicon.ico',
        'search', 'tag', 'tags', 'articles', 'page', 'api', 'login', 'logout',
    ];

    private const CHARS = '\p{L}\p{M}\p{N}\x{200C}\x{200D}';
    private const MAX = 120;

    public static function fromTitle(string $title): string
    {
        $s = mb_strtolower(trim($title), 'UTF-8');
        $s = preg_replace('/[^' . self::CHARS . ']+/u', '-', $s) ?? '';
        $s = trim($s, '-');
        if (mb_strlen($s) > 100) {
            $s = rtrim(mb_substr($s, 0, 100), '-');
        }
        return $s === '' ? 'untitled' : $s;
    }

    public static function isValid(string $slug): bool
    {
        return mb_strlen($slug) >= 1 && mb_strlen($slug) <= self::MAX
            && preg_match('/^[' . self::CHARS . ']+(?:-[' . self::CHARS . ']+)*$/u', $slug) === 1;
    }

    public static function isReserved(string $slug): bool
    {
        return in_array(mb_strtolower($slug), self::RESERVED, true);
    }

    /**
     * First free slug under a parent: base, base-2, base-3 ... Uniqueness is per full address, so the same slug may be used under
     * different parents. Reserved names are only off limits at the top level; trashed items count as taken.
     * @param string $parentPath address of the parent ('' for top-level items)
     */
    public static function unique(ContentRepository $repo, string $base, ?int $exceptId = null, string $parentPath = ''): string
    {
        $base = $base === '' ? 'untitled' : $base;
        $prefix = $parentPath === '' ? '' : $parentPath . '/';
        for ($n = 1; $n <= 500; $n++) {
            $suffix = $n === 1 ? '' : '-' . $n;
            $candidate = mb_substr($base, 0, self::MAX - mb_strlen($suffix)) . $suffix;
            if (($parentPath !== '' || !self::isReserved($candidate)) && !$repo->pathTaken($prefix . $candidate, $exceptId)) {
                return $candidate;
            }
        }
        return mb_substr($base, 0, 100) . '-' . bin2hex(random_bytes(4));
    }
}
