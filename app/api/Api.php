<?php
declare(strict_types=1);

/**
 * JSON API dispatcher: /api/<module>/<action> → <Module>Api::<action>().
 * Every module file in app/api/ defines a class named after it. Responses
 * are {ok:true, data:...} or {ok:false, error:..., code:...}.
 */
final class Api
{
    public static ?array $link = null;
    public static array $linkState = ['password_ok' => true, 'terms_ok' => true];

    public static function dispatch(string $path): never
    {
        Http::securityHeaders();
        if (!preg_match('#^/api/([a-z_]+)/([a-z_0-9]+)/?$#', $path, $m)) {
            Http::fail('Unknown endpoint', 404, 'not_found');
        }
        [, $module, $action] = $m;
        $file = FR_APP_DIR . '/api/' . $module . '.php';
        if (!is_file($file)) Http::fail('Unknown endpoint', 404, 'not_found');
        require_once $file;
        $class = str_replace('_', '', ucwords($module, '_')) . 'Api';
        if (!class_exists($class, false) || !method_exists($class, $action) || str_starts_with($action, '_')) {
            Http::fail('Unknown endpoint', 404, 'not_found');
        }
        try {
            if (Settings::bool('maintenance_mode') && !in_array($module, ['auth', 'public'], true)) {
                $u = Auth::user();
                if (!$u || !Users::isAdmin($u)) Http::fail('The server is in maintenance mode. Please try again later.', 503, 'maintenance');
            }
            self::resolveLink();
            $u = Auth::user();
            Activity::$client = self::$link ? 'link' : (in_array(Auth::$method, ['token', 'basic'], true) ? 'api' : 'web');
            if ($u && in_array(Auth::$method, ['token', 'basic'], true) && !Users::can($u, 'api')) {
                Http::fail('API access is not enabled for this account', 403, 'forbidden');
            }
            if (Http::method() !== 'GET' && Http::method() !== 'HEAD' && Http::method() !== 'OPTIONS') {
                Auth::csrfCheck();
            }
            $class::$action();
            Http::ok(null);
        } catch (PermissionException $e) {
            Http::fail($e->getMessage(), 403, 'forbidden');
        } catch (NotFoundException $e) {
            Http::fail($e->getMessage(), 404, 'not_found');
        } catch (InvalidArgumentException | RuntimeException $e) {
            Http::fail($e->getMessage(), 400, 'error');
        } catch (PDOException $e) {
            fr_log('DB error in ' . $module . '/' . $action . ': ' . $e->getMessage());
            Http::fail(fr_config('debug') ? $e->getMessage() : 'Database error', 500, 'db');
        }
    }

    /** Public link mode: ?link=<hash> (or X-Link header). */
    private static function resolveLink(): void
    {
        $hash = (string) (Http::input('link', '') ?: (Http::header('X-Link') ?? ''));
        if ($hash === '') return;
        $link = Links::byHash($hash);
        if (!$link) Http::fail('This link does not exist', 404, 'link_missing');
        if ($reason = Links::usableReason($link)) Http::fail($reason, 410, 'link_unusable');
        self::$link = $link;
        self::$linkState = Links::visitorState($link);
    }

    /** Current signed-in user (may be null in link mode). */
    public static function user(): ?array
    {
        return Auth::user();
    }

    public static function requireUser(): array
    {
        return Auth::requireUser();
    }

    /** Actor for logging / notifications: the signed-in user, or null for a link visitor. */
    public static function actor(): ?array
    {
        return Auth::user();
    }

    /** The visitor must have satisfied the link's password and terms. */
    public static function requireLinkUnlocked(): void
    {
        if (!self::$link) return;
        if (!self::$linkState['password_ok']) Http::fail('This link is protected by a password', 403, 'link_password');
        if (!self::$linkState['terms_ok']) Http::fail('You must accept the terms first', 403, 'link_terms');
    }

    /** Resolve a virtual path for the current identity (user or link). */
    public static function ctx(?string $vpath = null, bool $mustExist = true): Ctx
    {
        $vpath = $vpath ?? Http::str('p', '/');
        if (self::$link) {
            self::requireLinkUnlocked();
            return $mustExist ? Paths::resolveExisting($vpath, Auth::user(), self::$link) : Paths::resolve($vpath, Auth::user(), self::$link);
        }
        $u = Auth::requireUser();
        return $mustExist ? Paths::resolveExisting($vpath, $u) : Paths::resolve($vpath, $u);
    }

    /** Several paths from `paths` (JSON array) or a single `p`. */
    public static function paths(): array
    {
        $paths = Http::arr('paths');
        if (!$paths) {
            $p = Http::str('p', '');
            if ($p !== '') $paths = [$p];
        }
        $paths = array_values(array_unique(array_filter(array_map('strval', $paths), 'strlen')));
        if (!$paths) throw new RuntimeException('No items given');
        if (count($paths) > 5000) throw new RuntimeException('Too many items at once');
        return $paths;
    }

    /** Stream a temporary file as a download and delete it afterwards. */
    public static function sendTempFile(string $tmp, string $name, string $mime = 'application/zip'): never
    {
        register_shutdown_function(static function () use ($tmp): void { @unlink($tmp); });
        Http::streamFile($tmp, $name, false, $mime);
    }
}
