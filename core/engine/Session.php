<?php
declare(strict_types=1);

namespace Cms;

/** Hardened session handling: private save path, strict mode, idle and absolute timeouts. */
final class Session
{
    private const IDLE = 1800;      // 30 minutes without activity
    private const ABSOLUTE = 43200; // 12 hours since login

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $dir = CMS_ROOT . '/user/cache/sessions';
        if (Files::ensureDir($dir, 0700) && is_writable($dir)) {
            session_save_path($dir);
        }
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.gc_probability', '1');
        ini_set('session.gc_divisor', '100');
        ini_set('session.gc_maxlifetime', (string) self::ABSOLUTE);
        session_name('cmssid');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => Url::base() === '' ? '/' : Url::base(),
            'secure' => Request::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();

        $now = time();
        $created = $_SESSION['_created'] ?? null;
        if (!is_int($created)) {
            $_SESSION['_created'] = $now;
        } else {
            $last = is_int($_SESSION['_last'] ?? null) ? $_SESSION['_last'] : $created;
            if ($now - $created > self::ABSOLUTE || $now - $last > self::IDLE) {
                self::reset();
            }
        }
        $_SESSION['_last'] = $now;
    }

    /** Drop all session data and issue a fresh id; the old session is deleted server-side. */
    public static function reset(): void
    {
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['_created'] = time();
        $_SESSION['_last'] = time();
    }

    public static function flash(string $type, string $message): void
    {
        $_SESSION['_flash'][] = [$type, $message];
    }

    /** @return array<int, array{0:string,1:string}> */
    public static function pullFlash(): array
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return [];
        }
        $flashes = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);
        return is_array($flashes) ? $flashes : [];
    }
}
