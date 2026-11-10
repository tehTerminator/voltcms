<?php
declare(strict_types=1);

namespace Cms;

/** Per-session CSRF token, required on every POST. */
final class Csrf
{
    public static function token(): string
    {
        if (!is_string($_SESSION['_csrf'] ?? null)) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf'];
    }

    public static function field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . e(self::token()) . '">';
    }

    public static function verify(): bool
    {
        $sent = Request::post('_csrf');
        $known = $_SESSION['_csrf'] ?? null;
        return is_string($known) && $sent !== '' && hash_equals($known, $sent);
    }
}
