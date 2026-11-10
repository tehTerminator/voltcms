<?php
declare(strict_types=1);

namespace Cms;

/**
 * Append-only private log with simple size-based rotation (no cron needed).
 * Callers must never pass passwords, tokens, session ids or secrets in $context.
 */
final class Logger
{
    private const MAX_BYTES = 1048576; // 1 MB, then rotate to app.log.1 (one generation kept)

    public static function error(string $message, array $context = []): void
    {
        self::write('ERROR', $message, $context);
    }

    public static function security(string $event, array $context = []): void
    {
        self::write('SECURITY', $event, $context);
    }

    public static function info(string $message, array $context = []): void
    {
        self::write('INFO', $message, $context);
    }

    private static function write(string $level, string $message, array $context): void
    {
        $dir = CMS_ROOT . '/user/logs';
        $line = '[' . gmdate('Y-m-d H:i:s') . ' UTC] ' . $level . ' ' . str_replace(["\r", "\n"], ' ', $message);
        if ($context !== []) {
            $line .= ' ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        $file = $dir . '/app.log';
        if (!is_dir($dir) || !is_writable($dir)) {
            error_log($line);
            return;
        }
        if (is_file($file) && filesize($file) > self::MAX_BYTES) {
            @rename($file, $file . '.1');
        }
        @file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX);
    }
}
