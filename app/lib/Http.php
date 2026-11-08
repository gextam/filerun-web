<?php
declare(strict_types=1);

/**
 * Request / response helpers: JSON bodies, client IP behind proxies,
 * cookies, file streaming with HTTP ranges.
 */
final class Http
{
    private static ?array $jsonBody = null;

    public static function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    /** Request path relative to the application base, always starting with "/". */
    public static function path(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        $path = rawurldecode($path);
        if (FR_BASE_PATH !== '' && str_starts_with($path, FR_BASE_PATH)) {
            $path = substr($path, strlen(FR_BASE_PATH));
        }
        // Executed directly as /filerun-web/index.php/...
        if (preg_match('#^/index\.php(/.*)?$#', $path, $m)) {
            $path = $m[1] ?? '/';
        }
        return $path === '' ? '/' : $path;
    }

    public static function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if (isset($_SERVER[$key])) return (string) $_SERVER[$key];
        if ($name === 'Content-Type' && isset($_SERVER['CONTENT_TYPE'])) return (string) $_SERVER['CONTENT_TYPE'];
        if ($name === 'Content-Length' && isset($_SERVER['CONTENT_LENGTH'])) return (string) $_SERVER['CONTENT_LENGTH'];
        if ($name === 'Authorization') {
            foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $k) {
                if (!empty($_SERVER[$k])) return (string) $_SERVER[$k];
            }
            if (function_exists('apache_request_headers')) {
                $h = apache_request_headers();
                foreach ($h as $k => $v) {
                    if (strcasecmp($k, 'Authorization') === 0) return (string) $v;
                }
            }
        }
        return null;
    }

    /** Decoded JSON request body (cached). */
    public static function json(): array
    {
        if (self::$jsonBody !== null) return self::$jsonBody;
        $ct = (string) (self::header('Content-Type') ?? '');
        $raw = '';
        if (str_contains($ct, 'application/json')) {
            $raw = (string) file_get_contents('php://input');
        }
        $data = $raw !== '' ? json_decode($raw, true) : null;
        return self::$jsonBody = is_array($data) ? $data : [];
    }

    /** Input parameter from JSON body, POST or GET (in that order). */
    public static function input(string $key, mixed $default = null): mixed
    {
        $j = self::json();
        if (array_key_exists($key, $j)) return $j[$key];
        if (array_key_exists($key, $_POST)) return $_POST[$key];
        if (array_key_exists($key, $_GET)) return $_GET[$key];
        return $default;
    }

    public static function str(string $key, string $default = ''): string
    {
        $v = self::input($key, $default);
        return is_scalar($v) ? (string) $v : $default;
    }

    public static function int(string $key, int $default = 0): int
    {
        $v = self::input($key, $default);
        return is_numeric($v) ? (int) $v : $default;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $v = self::input($key, null);
        return $v === null ? $default : Util::bool($v);
    }

    public static function arr(string $key): array
    {
        $v = self::input($key, []);
        if (is_string($v)) {
            $d = json_decode($v, true);
            return is_array($d) ? $d : ($v === '' ? [] : [$v]);
        }
        return is_array($v) ? $v : [];
    }

    /** Client IP, honouring X-Forwarded-For / CF-Connecting-IP only from trusted proxies. */
    public static function ip(): string
    {
        static $ip = null;
        if ($ip !== null) return $ip;
        $remote = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $trusted = fr_config('trusted_proxies');
        if (!is_array($trusted)) {
            $trusted = fr_installed() && class_exists('Settings') ? (array) Settings::get('trusted_proxies', []) : [];
        }
        $isTrusted = false;
        foreach ($trusted as $rule) {
            if (is_string($rule) && Util::ipMatches($remote, $rule)) { $isTrusted = true; break; }
        }
        if ($isTrusted) {
            foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR'] as $h) {
                if (!empty($_SERVER[$h])) {
                    $first = trim(explode(',', (string) $_SERVER[$h])[0]);
                    if (filter_var($first, FILTER_VALIDATE_IP)) return $ip = $first;
                }
            }
        }
        return $ip = $remote;
    }

    public static function userAgent(): string
    {
        return substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);
    }

    public static function isHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
            || (($_SERVER['SERVER_PORT'] ?? '') == 443);
    }

    public static function setCookie(string $name, string $value, int $expires = 0, bool $httpOnly = true, string $sameSite = 'Lax'): void
    {
        setcookie($name, $value, [
            'expires' => $expires,
            'path' => (FR_BASE_PATH === '' ? '/' : FR_BASE_PATH . '/'),
            'secure' => self::isHttps(),
            'httponly' => $httpOnly,
            'samesite' => $sameSite,
        ]);
        $_COOKIE[$name] = $value;
    }

    public static function deleteCookie(string $name): void
    {
        self::setCookie($name, '', time() - 86400);
        unset($_COOKIE[$name]);
    }

    public static function securityHeaders(): void
    {
        if (headers_sent()) return;
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    }

    public static function noCache(): void
    {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
    }

    public static function sendJson(mixed $data, int $status = 200): never
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            self::noCache();
        }
        echo Util::json($data);
        exit;
    }

    public static function ok(mixed $data = null, array $extra = []): never
    {
        self::sendJson(['ok' => true, 'data' => $data] + $extra);
    }

    public static function fail(string $message, int $status = 400, string $code = 'error', array $extra = []): never
    {
        self::sendJson(['ok' => false, 'error' => $message, 'code' => $code] + $extra, $status);
    }

    public static function redirect(string $url, int $status = 302): never
    {
        http_response_code($status);
        header('Location: ' . $url);
        exit;
    }

    public static function html(string $html, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        echo $html;
        exit;
    }

    /** RFC 6266 Content-Disposition with UTF-8 file name. */
    public static function disposition(string $type, string $filename): string
    {
        $ascii = preg_replace('/[^\x20-\x7e]/', '_', $filename) ?? 'file';
        $ascii = str_replace(['"', '\\'], '_', $ascii);
        return $type . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($filename);
    }

    /**
     * Stream a file to the client with Range / If-Range / ETag support.
     * $onBytes is invoked with the number of bytes actually sent (for traffic accounting).
     */
    public static function streamFile(string $absPath, string $downloadName, bool $inline = false, ?string $mime = null, ?callable $onBytes = null, int $cacheSeconds = 0): never
    {
        if (!is_file($absPath) || !is_readable($absPath)) {
            self::fail('File not found', 404, 'not_found');
        }
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        $size = filesize($absPath) ?: 0;
        $mtime = filemtime($absPath) ?: time();
        $mime = $mime ?: Util::mime($downloadName, $absPath);
        if ($inline && (str_starts_with($mime, 'text/html') || $mime === 'image/svg+xml' || str_contains($mime, 'xml'))) {
            // Never render active content inline from user uploads.
            if ($mime !== 'image/svg+xml') {
                $mime = 'text/plain';
            }
            header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src data:");
        }
        $etag = '"' . md5($absPath . $size . $mtime) . '"';
        header('ETag: ' . $etag);
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
        header('Accept-Ranges: bytes');
        if ($cacheSeconds > 0) {
            header('Cache-Control: private, max-age=' . $cacheSeconds);
        } else {
            header('Cache-Control: private, no-cache');
        }
        if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
            http_response_code(304);
            exit;
        }
        header('Content-Type: ' . $mime);
        header('Content-Disposition: ' . self::disposition($inline ? 'inline' : 'attachment', $downloadName));
        header('X-Content-Type-Options: nosniff');

        $start = 0; $end = $size - 1;
        $range = $_SERVER['HTTP_RANGE'] ?? '';
        $ifRange = $_SERVER['HTTP_IF_RANGE'] ?? '';
        if ($range !== '' && ($ifRange === '' || $ifRange === $etag) && preg_match('/bytes=(\d*)-(\d*)/', $range, $m)) {
            if ($m[1] === '' && $m[2] !== '') {
                $start = max(0, $size - (int) $m[2]);
            } else {
                $start = (int) $m[1];
                if ($m[2] !== '') $end = min((int) $m[2], $size - 1);
            }
            if ($start > $end || $start >= $size) {
                http_response_code(416);
                header("Content-Range: bytes */$size");
                exit;
            }
            http_response_code(206);
            header("Content-Range: bytes $start-$end/$size");
        }
        $length = $end - $start + 1;
        header('Content-Length: ' . $length);
        if (self::method() === 'HEAD') {
            exit;
        }
        $fh = fopen($absPath, 'rb');
        if ($fh === false) {
            exit;
        }
        fseek($fh, $start);
        $sent = 0;
        $chunk = 1024 * 512;
        while ($sent < $length && !feof($fh) && !connection_aborted()) {
            $buf = fread($fh, (int) min($chunk, $length - $sent));
            if ($buf === false || $buf === '') break;
            echo $buf;
            flush();
            $sent += strlen($buf);
        }
        fclose($fh);
        if ($onBytes) {
            try { $onBytes($sent); } catch (Throwable) {}
        }
        exit;
    }

    /** Send an in-memory string as a download / inline resource. */
    public static function sendBlob(string $data, string $name, string $mime, bool $inline = true, int $cacheSeconds = 0): never
    {
        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . strlen($data));
        header('Content-Disposition: ' . self::disposition($inline ? 'inline' : 'attachment', $name));
        header('X-Content-Type-Options: nosniff');
        header($cacheSeconds > 0 ? 'Cache-Control: private, max-age=' . $cacheSeconds : 'Cache-Control: private, no-cache');
        echo $data;
        exit;
    }

    public static function escape(?string $s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
