<?php
declare(strict_types=1);

namespace Cms;

/** Production-safe runtime setup: errors are logged privately, never displayed. */
final class Env
{
    public static function init(): void
    {
        error_reporting(E_ALL);
        ini_set('display_errors', '0');
        ini_set('log_errors', '1');
        $logDir = CMS_ROOT . '/user/logs';
        if (is_dir($logDir) && is_writable($logDir)) {
            ini_set('error_log', $logDir . '/php-error.log');
        }
        date_default_timezone_set('UTC');
        mb_internal_encoding('UTF-8');
        set_exception_handler([self::class, 'handleException']);
    }

    public static function handleException(\Throwable $e): void
    {
        Logger::error(get_class($e) . ': ' . $e->getMessage() . ' at ' . basename($e->getFile()) . ':' . $e->getLine());
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: no-store');
        }
        $detail = Config::get('debug', false) === true ? '<pre>' . e($e->getMessage()) . '</pre>' : '';
        echo '<!doctype html><meta charset="utf-8"><title>Error</title>'
            . '<h1>Something went wrong</h1><p>The error has been logged.</p>' . $detail;
    }
}
