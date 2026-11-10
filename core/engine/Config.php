<?php
declare(strict_types=1);

namespace Cms;

/**
 * Reads user/db.php (database credentials) and user/config/app.php (app key, flags).
 * Both files are PHP arrays guarded by `defined('CMS_ROOT') || exit;` so a direct web
 * request prints nothing even if server-level deny rules are missing.
 */
final class Config
{
    private static array $app = [];
    private static array $db = [];
    private static bool $loaded = false;

    public static function lockFile(): string
    {
        return CMS_ROOT . '/user/config/installed.lock';
    }

    public static function isInstalled(): bool
    {
        return is_file(self::lockFile());
    }

    public static function load(): void
    {
        if (self::$loaded) {
            return;
        }
        self::$db = self::read(CMS_ROOT . '/user/db.php');
        self::$app = self::read(CMS_ROOT . '/user/config/app.php');
        self::$loaded = true;
    }

    /** Test hook: supply configuration without files. */
    public static function inject(array $app, array $db = []): void
    {
        self::$app = $app;
        self::$db = $db;
        self::$loaded = true;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        if (!self::$loaded && self::isInstalled()) {
            try {
                self::load();
            } catch (\Throwable) {
                return $default;
            }
        }
        return self::$app[$key] ?? $default;
    }

    public static function db(): array
    {
        self::load();
        return self::$db;
    }

    /** Raw 32-byte application key. */
    public static function appKey(): string
    {
        self::load();
        $key = base64_decode((string) (self::$app['app_key'] ?? ''), true);
        if ($key === false || strlen($key) !== 32) {
            throw new \RuntimeException('Application key is missing or invalid in user/config/app.php.');
        }
        return $key;
    }

    /** Write both config files atomically (used by the installer). */
    public static function write(array $db, array $app): void
    {
        $ok = Files::writeAtomic(CMS_ROOT . '/user/db.php', self::export($db))
            && Files::writeAtomic(CMS_ROOT . '/user/config/app.php', self::export($app));
        if (!$ok) {
            throw new \RuntimeException('Could not write user/db.php or user/config/app.php. Check directory permissions.');
        }
    }

    /** Merge changes into user/config/app.php (atomic write). Used by the update step. */
    public static function saveApp(array $changes): void
    {
        self::load();
        $merged = array_merge(self::$app, $changes);
        if (!Files::writeAtomic(CMS_ROOT . '/user/config/app.php', self::export($merged))) {
            throw new \RuntimeException('Could not update user/config/app.php. Check file permissions.');
        }
        self::$app = $merged;
    }

    private static function export(array $data): string
    {
        return "<?php\ndefined('CMS_ROOT') || exit;\nreturn " . var_export($data, true) . ";\n";
    }

    private static function read(string $file): array
    {
        if (!is_file($file)) {
            throw new \RuntimeException('Missing configuration file: ' . basename($file));
        }
        $value = require $file;
        if (!is_array($value)) {
            throw new \RuntimeException('Invalid configuration file: ' . basename($file));
        }
        return $value;
    }
}
