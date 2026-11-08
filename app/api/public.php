<?php
declare(strict_types=1);

/** /api/public/* — endpoints that need no authentication. */
final class PublicApi
{
    public static function settings(): void
    {
        Http::ok(Settings::publicSettings());
    }

    public static function health(): void
    {
        $dbOk = true;
        try { DB::val('SELECT 1'); } catch (Throwable) { $dbOk = false; }
        Http::ok(['ok' => $dbOk, 'version' => FR_VERSION, 'time' => gmdate('c')]);
    }

    /** Branding files uploaded by the administrator. */
    public static function brand(): void
    {
        $kind = Http::str('f', 'logo') === 'favicon' ? 'favicon' : 'logo';
        $files = glob(fr_data_dir('branding') . '/' . $kind . '.*') ?: [];
        if (!$files) Http::fail('Not found', 404, 'not_found');
        $f = $files[0];
        $mime = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'svg' => 'image/svg+xml', 'webp' => 'image/webp', 'gif' => 'image/gif', 'ico' => 'image/x-icon'][Util::ext($f)] ?? 'application/octet-stream';
        Http::streamFile($f, basename($f), true, $mime, null, 86400);
    }

    /** PWA icon rendered from the accent colour and the first letter of the application name. */
    public static function icon(): void
    {
        $size = max(32, min(1024, Http::int('size', 192)));
        $accent = (string) Settings::get('accent_color');
        if (!preg_match('/^#([0-9a-f]{6})$/i', $accent, $m)) $m = [null, '2563eb'];
        [$r, $g, $b] = array_map('hexdec', str_split($m[1], 2));
        if (!function_exists('imagecreatetruecolor')) Http::fail('GD missing', 404, 'not_found');
        $im = imagecreatetruecolor($size, $size);
        imagesavealpha($im, true);
        $bg = imagecolorallocate($im, (int) $r, (int) $g, (int) $b);
        imagefill($im, 0, 0, $bg);
        $white = imagecolorallocate($im, 255, 255, 255);
        // Folder glyph
        $x = (int) ($size * 0.18); $y = (int) ($size * 0.3); $w = (int) ($size * 0.64); $h = (int) ($size * 0.42);
        imagefilledrectangle($im, $x, $y, $x + $w, $y + $h, $white);
        imagefilledrectangle($im, $x, $y - (int) ($size * 0.07), $x + (int) ($w * 0.42), $y, $white);
        header('Content-Type: image/png');
        header('Cache-Control: public, max-age=86400');
        imagepng($im);
        imagedestroy($im);
        exit;
    }

    public static function manifest(): void
    {
        $name = (string) Settings::get('app_name');
        $accent = (string) Settings::get('accent_color');
        header('Content-Type: application/manifest+json');
        echo Util::json([
            'name' => $name, 'short_name' => mb_substr($name, 0, 12), 'start_url' => fr_url('/'), 'scope' => fr_url('/'), 'display' => 'standalone',
            'background_color' => '#ffffff', 'theme_color' => $accent, 'description' => (string) Settings::get('tagline'),
            'icons' => [['src' => fr_url('/api/public/icon?size=192'), 'sizes' => '192x192', 'type' => 'image/png'], ['src' => fr_url('/api/public/icon?size=512'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any maskable']],
            'share_target' => ['action' => fr_url('/'), 'method' => 'GET', 'params' => ['title' => 'title', 'text' => 'text', 'url' => 'url']],
        ], true);
        exit;
    }

    /** OpenAPI 3 description of the JSON API. */
    public static function openapi(): void
    {
        $base = fr_public_url('/api');
        $p = static fn(string $desc, array $params = [], string $method = 'get', string $tag = 'files') => [$method => ['summary' => $desc, 'tags' => [$tag], 'parameters' => array_map(static fn($n) => ['name' => $n, 'in' => 'query', 'schema' => ['type' => 'string']], $params), 'responses' => ['200' => ['description' => 'JSON envelope {ok, data}']]]];
        $post = static fn(string $desc, array $fields, string $tag = 'files') => ['post' => ['summary' => $desc, 'tags' => [$tag], 'requestBody' => ['content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => array_fill_keys($fields, ['type' => 'string'])]]]], 'responses' => ['200' => ['description' => 'JSON envelope {ok, data}']]]];
        $spec = [
            'openapi' => '3.0.3',
            'info' => ['title' => Settings::get('app_name') . ' API', 'version' => FR_VERSION, 'description' => "Authenticate with `Authorization: Bearer <app password>` (create one under Account → App passwords) or HTTP Basic. Paths (`p`) are virtual: `/folder/file.txt` inside your home, `@<shareId>/sub` inside an item shared with you. Responses are `{\"ok\":true,\"data\":...}` or `{\"ok\":false,\"error\":\"...\",\"code\":\"...\"}`."],
            'servers' => [['url' => $base]],
            'components' => ['securitySchemes' => ['bearer' => ['type' => 'http', 'scheme' => 'bearer'], 'basic' => ['type' => 'http', 'scheme' => 'basic']]],
            'security' => [['bearer' => []], ['basic' => []]],
            'paths' => [
                '/auth/status' => $p('Who am I and what is configured', [], 'get', 'auth'),
                '/files/list' => $p('List a folder', ['p', 'sort', 'dir', 'offset', 'limit', 'show_hidden']),
                '/files/info' => $p('Details of one item', ['p']),
                '/files/tree' => $p('Sub folders of a folder', ['p']),
                '/files/download' => $p('Download a file, or a zip of several items (paths=JSON array)', ['p', 'paths']),
                '/files/raw' => $p('Stream a file inline (supports HTTP ranges)', ['p']),
                '/files/thumb' => $p('Thumbnail / preview rendition', ['p', 'size']),
                '/files/text' => $p('Read a text file', ['p']),
                '/files/save_text' => $post('Write a text file', ['p', 'content', 'mtime']),
                '/files/mkdir' => $post('Create a folder', ['p', 'name']),
                '/files/create_file' => $post('Create an empty file', ['p', 'name', 'content']),
                '/files/rename' => $post('Rename', ['p', 'name']),
                '/files/move' => $post('Move items into a folder', ['paths', 'to', 'conflict']),
                '/files/copy' => $post('Copy items into a folder', ['paths', 'to', 'conflict']),
                '/files/delete' => $post('Delete items (to the trash unless permanent=1)', ['paths', 'permanent']),
                '/files/search' => $p('Search names, contents and metadata', ['q', 'p', 'kind', 'ext', 'from', 'to', 'min_size', 'max_size', 'content']),
                '/files/recent' => $p('Recently modified files', ['limit']),
                '/files/photos' => $p('Images and videos', ['p', 'offset', 'limit']),
                '/files/starred' => $p('Starred items'),
                '/files/star' => $post('Star / unstar', ['paths', 'on']),
                '/files/label' => $post('Set a colour label', ['paths', 'label']),
                '/files/tags' => $post('Set tags', ['paths', 'tags']),
                '/files/metadata' => $post('Set custom metadata values', ['p', 'values']),
                '/files/comments' => $p('Comments on an item', ['p']),
                '/files/comment' => $post('Add a comment', ['p', 'body']),
                '/files/versions' => $p('Previous versions of a file', ['p']),
                '/files/version_restore' => $post('Restore a version', ['p', 'id']),
                '/files/lock' => $post('Lock a file', ['p']),
                '/files/unlock' => $post('Unlock a file', ['p']),
                '/files/follow' => $post('Follow / unfollow a folder', ['p', 'on']),
                '/files/activity' => $p('Activity of an item', ['p']),
                '/files/trash' => $p('Trash contents'),
                '/files/trash_restore' => $post('Restore from trash', ['ids']),
                '/files/trash_purge' => $post('Empty trash / purge entries', ['ids']),
                '/upload/start' => $post('Start a resumable upload', ['p', 'name', 'size', 'mtime', 'overwrite', 'relpath'], 'upload'),
                '/upload/chunk' => ['post' => ['summary' => 'Append raw bytes at ?id=&offset= (body application/octet-stream)', 'tags' => ['upload'], 'responses' => ['200' => ['description' => '{received}']]]],
                '/upload/finish' => $post('Complete an upload', ['id'], 'upload'),
                '/upload/multipart' => ['post' => ['summary' => 'Classic multipart upload (fields p, file, overwrite, relpath)', 'tags' => ['upload'], 'responses' => ['200' => ['description' => 'created items']]]],
                '/shares/item' => $p('Shares and links on an item', ['p'], 'get', 'sharing'),
                '/shares/create' => $post('Share with users / groups', ['p', 'targets', 'role', 'alias', 'notify', 'expires_at'], 'sharing'),
                '/shares/invite' => $post('Share with an e-mail address (guest account)', ['p', 'emails', 'role', 'message'], 'sharing'),
                '/shares/with_me' => $p('Items shared with me', [], 'get', 'sharing'),
                '/shares/link_create' => $post('Create a public link', ['p', 'role', 'password', 'expires_at', 'max_downloads', 'terms', 'notify', 'allow_preview_only', 'title', 'message'], 'sharing'),
                '/shares/link_update' => $post('Update a public link', ['id', 'active', 'password', 'expires_at', 'max_downloads'], 'sharing'),
                '/shares/link_delete' => $post('Delete a public link', ['id'], 'sharing'),
                '/account/me' => $p('My profile', [], 'get', 'account'),
                '/account/app_password_create' => $post('Create an app password / API token', ['name', 'scope'], 'account'),
                '/account/notifications' => $p('My notifications', ['unread'], 'get', 'account'),
                '/admin/users' => $p('List accounts (administrators)', ['q', 'role', 'active', 'limit', 'offset'], 'get', 'admin'),
                '/admin/user_save' => $post('Create or update an account', ['id', 'username', 'email', 'password', 'name', 'role', 'quota_bytes', 'perms', 'active', 'expires_at'], 'admin'),
                '/admin/user_delete' => $post('Delete an account', ['id', 'delete_files'], 'admin'),
                '/admin/activity' => $p('Audit log', ['user', 'action', 'status', 'client', 'from', 'to', 'limit', 'offset'], 'get', 'admin'),
            ],
        ];
        header('Content-Type: application/json');
        echo Util::json($spec, true);
        exit;
    }
}
