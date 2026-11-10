<?php
declare(strict_types=1);

namespace Cms;

/** URL building that works at the domain root or in a subfolder. */
final class Url
{
    /** Base path without trailing slash; '' when installed at the domain root. */
    public static function base(): string
    {
        $dir = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/')));
        return ($dir === '/' || $dir === '.' || $dir === '') ? '' : rtrim($dir, '/');
    }

    /** scheme://host[/subfolder] of the current request, or '' if the host header looks unsafe. Used only to suggest a site URL. */
    public static function origin(): string
    {
        $host = (string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '');
        if (preg_match('/^[A-Za-z0-9.\-]+(?::\d{1,5})?$/', $host) !== 1) {
            return '';
        }
        return (Request::isHttps() ? 'https' : 'http') . '://' . strtolower($host) . self::base();
    }

    /** Application path of a content address: "/book/part-one", each segment percent-encoded (so Unicode slugs work). */
    public static function contentPath(string $address): string
    {
        return '/' . implode('/', array_map('rawurlencode', explode('/', $address)));
    }

    /** Full URL (including a subfolder install's base) of a content address such as "book/part-one". */
    public static function content(string $address): string
    {
        return self::to(self::contentPath($address));
    }

    public static function to(string $path = '/'): string
    {
        return self::base() . '/' . ltrim($path, '/');
    }
}
