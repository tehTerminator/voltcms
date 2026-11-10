<?php
declare(strict_types=1);

namespace Cms;

final class Response
{
    public static function secureHeaders(): void
    {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: same-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
        header("Content-Security-Policy: default-src 'none'; style-src 'self' https://cdn.jsdelivr.net; "
            . "script-src 'self'; connect-src 'self'; img-src 'self' data:; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
        if (Request::isHttps()) {
            header('Strict-Transport-Security: max-age=15552000');
        }
    }

    /**
     * Headers for public pages. A per-request nonce allows the page's own custom CSS/JS blocks
     * (administrator-authored) while everything else inline stays blocked. Inline style="" attributes
     * are allowed because the sanitizer restricts them to a safe property allowlist.
     */
    public static function publicHeaders(string $nonce): void
    {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
        header("Content-Security-Policy: default-src 'none'; style-src 'self' https://cdn.jsdelivr.net 'nonce-" . $nonce . "'; "
            . "style-src-attr 'unsafe-inline'; script-src 'self' https://cdn.jsdelivr.net 'nonce-" . $nonce . "'; "
            . "img-src 'self' data: https:; font-src 'self' https://cdn.jsdelivr.net; connect-src 'self'; "
            . "form-action 'self'; base-uri 'self'; frame-ancestors 'none'");
        if (Request::isHttps()) {
            header('Strict-Transport-Security: max-age=15552000');
        }
    }

    public static function noCache(): void
    {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
    }

    public static function html(string $body, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        echo $body;
    }

    /** Redirect to an application path (303 so a POST is never replayed). Ends the request. */
    public static function redirect(string $path, int $status = 303): void
    {
        header('Location: ' . Url::to($path), true, $status);
        exit;
    }
}
