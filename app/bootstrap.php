<?php
/**
 * FileRun Web – application bootstrap.
 *
 * Included by every entry point (index.php, cron.php, bin/install.php).
 * Sets up autoloading, configuration, error handling and URL bases.
 */
declare(strict_types=1);

define('FR_VERSION', '1.1.0');
define('FR_APP_DIR', __DIR__);
define('FR_ROOT_DIR', dirname(__DIR__));
define('FR_START', microtime(true));

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
mb_internal_encoding('UTF-8');
date_default_timezone_set('UTC');
if (function_exists('set_time_limit')) { @set_time_limit(0); }

spl_autoload_register(static function (string $class): void {
    $file = FR_APP_DIR . '/lib/' . str_replace('\\', '/', $class) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

// ---------------------------------------------------------------------------
// Configuration
// ---------------------------------------------------------------------------
$GLOBALS['FR_CONFIG_FILE'] = getenv('FR_CONFIG') ?: FR_ROOT_DIR . '/config.php';
$GLOBALS['FR_CONFIG'] = is_file($GLOBALS['FR_CONFIG_FILE']) ? (require $GLOBALS['FR_CONFIG_FILE']) : null;
if (!is_array($GLOBALS['FR_CONFIG'])) {
    $GLOBALS['FR_CONFIG'] = null;
}

/** Whether config.php exists and the application has been installed. */
function fr_installed(): bool
{
    return is_array($GLOBALS['FR_CONFIG']) && !empty($GLOBALS['FR_CONFIG']['db']);
}

/** Read a configuration value (dot notation supported: "db.host"). */
function fr_config(?string $key = null, mixed $default = null): mixed
{
    $cfg = $GLOBALS['FR_CONFIG'] ?? [];
    if ($key === null) {
        return $cfg;
    }
    $cur = $cfg;
    foreach (explode('.', $key) as $part) {
        if (!is_array($cur) || !array_key_exists($part, $cur)) {
            return $default;
        }
        $cur = $cur[$part];
    }
    return $cur;
}

/** Absolute filesystem path of the data directory (user files, versions, trash, thumbs...). */
function fr_data_dir(string $sub = ''): string
{
    $base = rtrim((string) fr_config('data_dir', FR_ROOT_DIR . '/data'), '/\\');
    return $sub === '' ? $base : $base . '/' . ltrim($sub, '/');
}

// ---------------------------------------------------------------------------
// URL bases. FR_BASE_PATH is the URL prefix under which the executing
// index.php is reached ('' when installed at the domain root, '/filerun-web'
// when reached through the sub folder). FR_ASSET_BASE is the URL of the
// application folder itself, used for static files.
// ---------------------------------------------------------------------------
if (PHP_SAPI !== 'cli') {
    if (!defined('FR_BASE_PATH')) {
        $script = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
        $base = str_replace('\\', '/', dirname($script));
        define('FR_BASE_PATH', $base === '/' || $base === '.' ? '' : rtrim($base, '/'));
    }
    if (!defined('FR_ASSET_BASE')) {
        $assetBase = fr_config('asset_url');
        if (!$assetBase) {
            $docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? realpath($_SERVER['DOCUMENT_ROOT']) : false;
            $rootDir = realpath(FR_ROOT_DIR);
            if ($docRoot && $rootDir && str_starts_with($rootDir, $docRoot)) {
                $assetBase = str_replace('\\', '/', substr($rootDir, strlen($docRoot)));
            } else {
                $assetBase = FR_BASE_PATH; // falls back to PHP-served assets
            }
        }
        define('FR_ASSET_BASE', rtrim((string) $assetBase, '/'));
    }
} else {
    if (!defined('FR_BASE_PATH')) define('FR_BASE_PATH', '');
    if (!defined('FR_ASSET_BASE')) define('FR_ASSET_BASE', '');
}

/** Build an application URL from a path such as "/api/files/list". */
function fr_url(string $path = '/'): string
{
    return FR_BASE_PATH . '/' . ltrim($path, '/');
}

/** Version string for static assets: changes whenever a script or stylesheet is deployed. */
function fr_asset_version(): string
{
    static $v = null;
    if ($v !== null) return $v;
    $latest = 0;
    foreach (array_merge(glob(FR_ROOT_DIR . '/assets/js/*.js') ?: [], glob(FR_ROOT_DIR . '/assets/css/*.css') ?: []) as $f) {
        $m = @filemtime($f);
        if ($m && $m > $latest) $latest = $m;
    }
    return $v = FR_VERSION . '.' . base_convert((string) $latest, 10, 36);
}

/** Build the URL of a static asset, cache-busted with the asset version. */
function fr_asset(string $path): string
{
    return FR_ASSET_BASE . '/assets/' . ltrim($path, '/') . '?v=' . fr_asset_version();
}

/** Absolute public URL of the installation (https://host/base). */
function fr_public_url(string $path = '/'): string
{
    $configured = fr_config('public_url');
    if ($configured) {
        return rtrim((string) $configured, '/') . '/' . ltrim($path, '/');
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (($_SERVER['SERVER_PORT'] ?? '') == 443);
    $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
    return ($https ? 'https' : 'http') . '://' . $host . fr_url($path);
}

// ---------------------------------------------------------------------------
// Error handling: never leak stack traces to the browser, always log them.
// ---------------------------------------------------------------------------
set_error_handler(static function (int $no, string $str, string $file, int $line): bool {
    if (!(error_reporting() & $no)) {
        return false;
    }
    throw new ErrorException($str, 0, $no, $file, $line);
});

function fr_log(string $message, string $level = 'error'): void
{
    $dir = fr_data_dir('logs');
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    $line = '[' . gmdate('Y-m-d H:i:s') . '] ' . strtoupper($level) . ' ' . $message . PHP_EOL;
    @file_put_contents($dir . '/app.log', $line, FILE_APPEND | LOCK_EX);
    if ($level === 'error') {
        error_log('filerun-web: ' . $message);
    }
}

set_exception_handler(static function (Throwable $e): void {
    fr_log(get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() . "\n" . $e->getTraceAsString());
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, 'Error: ' . $e->getMessage() . PHP_EOL);
        exit(1);
    }
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    $debug = (bool) fr_config('debug', false);
    echo json_encode([
        'ok' => false,
        'error' => $debug ? $e->getMessage() : 'Internal server error',
        'code' => 'internal',
    ] + ($debug ? ['trace' => $e->getTraceAsString()] : []));
    exit;
});

/** Convenience: current UTC timestamp in MySQL format. */
function fr_now(): string
{
    return gmdate('Y-m-d H:i:s');
}
