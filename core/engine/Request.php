<?php
declare(strict_types=1);

namespace Cms;

/** Read-only access to the current request with defensive normalisation. */
final class Request
{
    public static function method(): string
    {
        return strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    }

    public static function isHttps(): bool
    {
        $https = $_SERVER['HTTPS'] ?? '';
        return ($https !== '' && strtolower((string) $https) !== 'off') || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
    }

    /** Client IP from REMOTE_ADDR only. Proxy headers are never trusted (they are spoofable). */
    public static function ip(): string
    {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : '0.0.0.0';
    }

    /** A POST field as a string; arrays and missing fields become ''. */
    public static function post(string $key): string
    {
        $v = $_POST[$key] ?? '';
        return is_string($v) ? $v : '';
    }

    /**
     * Normalised request path relative to the install folder, e.g. "/admin/login".
     * Returns null for malformed or traversal-looking paths (callers answer 404).
     */
    public static function path(): ?string
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        if (strncmp($uri, '//', 2) === 0) {
            $uri = '/' . ltrim($uri, '/');
        }
        $path = parse_url($uri, PHP_URL_PATH);
        if (!is_string($path)) {
            return null;
        }
        $path = rawurldecode($path);
        if (str_contains($path, "\0") || !mb_check_encoding($path, 'UTF-8')) {
            return null;
        }
        $base = Url::base();
        if ($base !== '') {
            if ($path !== $base && strncmp($path, $base . '/', strlen($base) + 1) !== 0) {
                return null;
            }
            $path = substr($path, strlen($base));
        }
        $path = '/' . trim((string) preg_replace('#/+#', '/', $path), '/');
        foreach (explode('/', $path) as $segment) {
            if ($segment === '.' || $segment === '..') {
                return null;
            }
        }
        return $path;
    }
}
