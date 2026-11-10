<?php
declare(strict_types=1);

namespace Cms;

/** Central route resolution. Phase 2 adds content slugs, tags, search, feed and sitemap here. */
final class Router
{
    public static function resolve(?string $path): string
    {
        if ($path === null) {
            return 'notfound';
        }
        if ($path === '/admin' || str_starts_with($path, '/admin/')) {
            return 'admin';
        }
        if ($path === '/') {
            return 'home';
        }
        if (str_starts_with($path, '/assets/base/')) {
            return 'asset';
        }
        $fixed = ['/search' => 'search', '/feed.xml' => 'feed', '/sitemap.xml' => 'sitemap', '/robots.txt' => 'robots'];
        if (isset($fixed[$path])) {
            return $fixed[$path];
        }
        if (preg_match('#^/tag/[^/]+$#', $path) === 1) {
            return 'tag';
        }
        if (preg_match('#^/[^/]+(?:/[^/]+){0,11}$#', $path) === 1) {
            return 'content'; // a full address such as /book/part-one (fixed routes above are matched first)
        }
        return 'notfound';
    }
}
