<?php
/**
 * Router for PHP's built-in web server (development only):
 *   php -S 127.0.0.1:8090 -t . bin/dev-router.php
 * Emulates the .htaccess rewrites and uses config.local.php when it exists.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$path = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
if ($path !== '/' && is_file($root . $path) && !preg_match('#^/(config\.php|config\.local\.php|\.user\.ini|app/|bin/)#', $path)) {
    return false; // let the built-in server serve static files
}
if (is_file($root . '/config.local.php')) {
    putenv('FR_CONFIG=' . $root . '/config.local.php');
}
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $root . '/index.php';
$_SERVER['DOCUMENT_ROOT'] = $root;
require $root . '/index.php';
