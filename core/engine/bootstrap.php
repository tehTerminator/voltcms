<?php
declare(strict_types=1);

/**
 * Engine bootstrap: version guard, constants, PSR-4-style autoloader, global helpers.
 * Classes live in the Cms namespace; Cms\Foo is loaded from core/engine/Foo.php,
 * then core/admin/Foo.php. Nothing here touches the database or the session.
 */
if (!defined('CMS_ROOT')) {
    exit;
}
if (PHP_VERSION_ID < 80000) {
    http_response_code(500);
    exit('This CMS requires PHP 8.0 or newer.');
}

define('CMS_VERSION', trim((string) @file_get_contents(CMS_ROOT . '/core/VERSION')) ?: '0.0.0');

spl_autoload_register(static function (string $class): void {
    if (strncmp($class, 'Cms\\', 4) !== 0) {
        return;
    }
    $name = substr($class, 4);
    if (preg_match('/^[A-Za-z0-9]+$/', $name) !== 1) {
        return;
    }
    foreach (['/core/engine/', '/core/admin/'] as $dir) {
        $file = CMS_ROOT . $dir . $name . '.php';
        if (is_file($file)) {
            require $file;
            return;
        }
    }
});

require CMS_ROOT . '/core/engine/helpers.php';
