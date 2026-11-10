<?php
declare(strict_types=1);

/**
 * Router script for PHP's built-in development server only:
 *     php -S localhost:8000 router.php
 * On Apache/LiteSpeed, .htaccess performs this job and this file is never loaded.
 */
if (PHP_SAPI !== 'cli-server') {
    http_response_code(404);
    exit;
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$path = is_string($path) ? rawurldecode($path) : '/';

// Mirror the .htaccess protections so development behaves like production.
$blocked = preg_match('#(^|/)\.(?!well-known/)#', $path) === 1
    || preg_match('#^/core/#', $path) === 1
    || preg_match('#^/user/(config|content|backups|cache|logs)/#', $path) === 1
    || $path === '/user/db.php'
    || preg_match('#\.(md|sql|log|lock|bak|disabled)$#', $path) === 1;
if ($blocked) {
    http_response_code(403);
    exit('Forbidden');
}

if ($path === '/install.php' && is_file(__DIR__ . '/install.php')) {
    $_SERVER['SCRIPT_NAME'] = '/install.php';
    require __DIR__ . '/install.php';
    return true;
}

$file = __DIR__ . $path;
if ($path !== '/' && is_file($file) && !preg_match('/\.(php|phtml|phar)$/i', $path)) {
    return false; // let the built-in server send the static file
}

$_SERVER['SCRIPT_NAME'] = '/index.php';
require __DIR__ . '/index.php';
return true;
