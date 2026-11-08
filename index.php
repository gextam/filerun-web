<?php
/**
 * FileRun Web – front controller.
 * Works when this folder is the document root or a sub folder of it.
 */
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

$path = Http::path();

if (!fr_installed()) {
    if (str_starts_with($path, '/api/')) {
        Http::fail('Not installed. Open /setup in a browser.', 503, 'not_installed');
    }
    if ($path !== '/setup') {
        Http::redirect(fr_url('/setup'));
    }
    Installer::handleWeb($path);
}

try {
    DB::connect();
} catch (Throwable $e) {
    fr_log('database connection failed: ' . $e->getMessage());
    if (str_starts_with($path, '/api/')) {
        Http::fail('Database connection failed', 503, 'db_down');
    }
    Shell::errorPage('Database unavailable', 'The application cannot reach its database. Check <code>config.php</code> and the database server.' . (fr_config('debug') ? '<br><br><code>' . Http::escape($e->getMessage()) . '</code>' : ''), 503);
}

// Keep the schema current after updates (one cheap query).
if ((int) Settings::get('schema_version', 0) < Schema::VERSION) {
    try { Schema::migrate(); } catch (Throwable $e) { fr_log('migration failed: ' . $e->getMessage()); }
}

if ($path === '/setup') {
    Http::redirect(fr_url('/'));
}

if (str_starts_with($path, '/api/')) {
    if (Http::method() !== 'GET') {
        register_shutdown_function(static function (): void { Jobs::opportunistic(); });
    }
    require FR_APP_DIR . '/api/Api.php';
    Api::dispatch($path);
}

if ($path === '/dav' || str_starts_with($path, '/dav/')) {
    Activity::$client = 'webdav';
    // Hand over the still-encoded path so that names containing % are not decoded twice.
    $rawPath = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
    if (FR_BASE_PATH !== '' && str_starts_with($rawPath, FR_BASE_PATH)) $rawPath = substr($rawPath, strlen(FR_BASE_PATH));
    $rawPath = preg_replace('#^/index\.php#', '', $rawPath) ?? $rawPath;
    WebDav::handle(substr($rawPath, 4) ?: '/');
}

if (preg_match('#^/s/([A-Za-z0-9_\-]{6,64})/?$#', $path, $m)) {
    Shell::render(['link' => $m[1]]);
}

if (preg_match('#^/open/([A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+)$#', $path, $m)) {
    $ctx = Tokens::fileCtx($m[1]);
    if (!$ctx || !is_file($ctx->abs)) {
        Http::fail('This link is invalid or has expired', 404, 'not_found');
    }
    Activity::$client = 'api';
    Http::streamFile($ctx->abs, Util::baseName($ctx->rel), !isset($_GET['dl']), null, static function (int $sent) use ($ctx): void {
        Users::addTraffic($ctx->ownerId, $sent);
    }, 0);
}

if ($path === '/manifest.webmanifest') {
    require FR_APP_DIR . '/api/public.php';
    PublicApi::manifest();
}

if ($path === '/robots.txt') {
    header('Content-Type: text/plain');
    echo "User-agent: *\nDisallow: /\n";
    exit;
}

// Static assets served by PHP only when the application folder is outside the web root.
if (str_starts_with($path, '/assets/') && FR_ASSET_BASE === FR_BASE_PATH) {
    $rel = Util::normPath(substr($path, 8));
    $file = FR_ROOT_DIR . '/assets/' . $rel;
    if ($rel === '' || !is_file($file)) {
        Http::fail('Not found', 404, 'not_found');
    }
    $mime = ['css' => 'text/css', 'js' => 'text/javascript', 'mjs' => 'text/javascript', 'svg' => 'image/svg+xml', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'json' => 'application/json', 'woff2' => 'font/woff2', 'woff' => 'font/woff', 'ico' => 'image/x-icon', 'map' => 'application/json'][Util::ext($rel)] ?? 'application/octet-stream';
    Http::streamFile($file, basename($file), true, $mime, null, 604800);
}

if (str_starts_with($path, '/dav')) {
    Http::fail('Not found', 404, 'not_found');
}

Shell::render();
