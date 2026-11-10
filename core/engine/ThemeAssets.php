<?php
declare(strict_types=1);

namespace Cms;

/**
 * Serves static files of the base theme (core/themes/base/assets) at /assets/base/{path}.
 * core/ is not web-accessible, so these go through PHP. Responses are cacheable for a year when the URL carries
 * ?v=, and support If-None-Match / If-Modified-Since, so PHP runs only on the first request per release.
 */
final class ThemeAssets
{
    private const TYPES = [
        'css' => 'text/css; charset=utf-8', 'js' => 'application/javascript; charset=utf-8',
        'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp',
        'svg' => 'image/svg+xml', 'ico' => 'image/x-icon', 'woff' => 'font/woff', 'woff2' => 'font/woff2', 'txt' => 'text/plain; charset=utf-8',
    ];

    public static function serve(string $relative, string $method, ?string $coreDir = null): void
    {
        $root = ($coreDir ?? CMS_ROOT . '/core') . '/themes/base/assets';
        if ($method !== 'GET' && $method !== 'HEAD') {
            header('Allow: GET, HEAD');
            http_response_code(405);
            return;
        }
        $ext = strtolower((string) pathinfo($relative, PATHINFO_EXTENSION));
        $realRoot = realpath($root);
        $file = Theme::isSafeRelative($relative) && isset(self::TYPES[$ext]) && $realRoot !== false ? realpath($root . '/' . $relative) : false;
        if ($file === false || strncmp($file, $realRoot . DIRECTORY_SEPARATOR, strlen($realRoot) + 1) !== 0 || !is_file($file)) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Not found';
            return;
        }
        $mtime = (int) filemtime($file);
        $etag = '"' . md5($mtime . '-' . filesize($file)) . '"';
        header('Content-Type: ' . self::TYPES[$ext]);
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox");
        header('ETag: ' . $etag);
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
        header('Cache-Control: public, max-age=' . (isset($_GET['v']) ? '31536000, immutable' : '3600'));
        $inm = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
        $ims = $_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '';
        if ($inm === $etag || ($inm === '' && $ims !== '' && strtotime($ims) !== false && strtotime($ims) >= $mtime)) {
            http_response_code(304);
            return;
        }
        header('Content-Length: ' . filesize($file));
        if ($method === 'GET') {
            readfile($file);
        }
    }
}
