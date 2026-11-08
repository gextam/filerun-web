<?php
declare(strict_types=1);

/** Renders the single-page application shell. */
final class Shell
{
    public static function render(array $opts = []): never
    {
        Http::securityHeaders();
        Http::noCache();
        require_once FR_APP_DIR . '/api/auth.php';
        $boot = AuthApi::bootPayload();
        $boot['link'] = $opts['link'] ?? null;
        $boot['reset_token'] = isset($_GET['reset']) ? (string) $_GET['reset'] : null;
        $boot['error'] = isset($_GET['error']) ? (string) $_GET['error'] : null;
        $boot['route'] = $opts['route'] ?? null;
        $s = $boot['settings'];
        $title = Http::escape((string) $s['app_name']);
        $accent = preg_match('/^#[0-9a-fA-F]{3,8}$/', (string) $s['accent_color']) ? (string) $s['accent_color'] : '#2563eb';
        $favicon = $s['favicon_url'] ?: fr_asset('img/favicon.svg');
        $theme = in_array($s['default_theme'], ['light', 'dark'], true) ? $s['default_theme'] : 'auto';
        $customCss = (string) $s['custom_css'];
        $lang = Http::escape((string) ($boot['user']['language'] ?? $s['default_language'] ?? 'en'));
        $json = Util::json($boot);
        $json = str_replace(['<', ' ', ' '], ['<', ' ', ' '], $json);
        $html = '<!doctype html>
<html lang="' . $lang . '" data-theme="' . $theme . '">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>' . $title . '</title>
<meta name="theme-color" content="' . Http::escape($accent) . '">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="robots" content="noindex, nofollow">
<link rel="icon" href="' . Http::escape($favicon) . '">
<link rel="apple-touch-icon" href="' . Http::escape(fr_url('/api/public/icon?size=180')) . '">
<link rel="manifest" href="' . Http::escape(fr_url('/manifest.webmanifest')) . '">
<link rel="stylesheet" href="' . Http::escape(fr_asset('css/app.css')) . '">
<style>:root{--accent:' . Http::escape($accent) . '}</style>
' . ($customCss !== '' ? '<style id="custom-css">' . str_replace('</style', '<\/style', $customCss) . '</style>' : '') . '
<script>window.FR_BOOT=' . $json . ';</script>
</head>
<body>
<div id="app"><div class="boot"><div class="boot-spinner"></div></div></div>
<noscript><p style="padding:40px;font-family:sans-serif">' . $title . ' needs JavaScript.</p></noscript>
<script type="module" src="' . Http::escape(fr_asset('js/app.js')) . '"></script>
</body>
</html>';
        Http::html($html);
    }

    /** Plain error page used before the application can boot (no DB, not installed...). */
    public static function errorPage(string $title, string $message, int $status = 500): never
    {
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html><head><meta charset="utf-8"><title>' . Http::escape($title) . '</title><style>body{font-family:-apple-system,Segoe UI,Roboto,sans-serif;background:#f3f4f6;color:#111827;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}div{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:32px 40px;max-width:560px}h1{font-size:20px;margin:0 0 12px}p{line-height:1.5;color:#374151}code{background:#f3f4f6;padding:2px 6px;border-radius:4px}</style></head><body><div><h1>' . Http::escape($title) . '</h1><p>' . $message . '</p></div></body></html>';
        exit;
    }
}
