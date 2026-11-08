<?php
declare(strict_types=1);

/**
 * /api/files/* — browsing, downloading, editing and organising files.
 * Works for the signed-in user and, where the role allows, for public links.
 */
final class FilesApi
{
    // ------------------------------------------------------------------ browsing

    public static function list(): void
    {
        $ctx = Api::ctx();
        $res = Storage::listDir($ctx, [
            'sort' => Http::str('sort', 'name'), 'dir' => Http::str('dir', 'asc'),
            'offset' => Http::int('offset', 0), 'limit' => Http::int('limit', 0), 'show_hidden' => Http::bool('show_hidden', false),
        ]);
        Http::ok($res);
    }

    public static function tree(): void
    {
        $ctx = Api::ctx();
        Http::ok(Storage::subfolders($ctx));
    }

    public static function info(): void
    {
        Http::ok(Storage::item(Api::ctx()));
    }

    public static function size(): void
    {
        Http::ok(Storage::sizeOf(Api::ctx()));
    }

    /** Several roots at once for the sidebar (home tree + shared + counts). */
    public static function sidebar(): void
    {
        $u = Api::requireUser();
        $home = Paths::resolve('/', $u);
        Users::ensureHome($u);
        Http::ok([
            'home' => Storage::subfolders($home),
            'shared' => Shares::sharedWithMe($u),
            'trash' => (int) DB::val('SELECT COUNT(*) FROM {{trash}} WHERE owner_id = ?', [(int) $u['id']]),
            'starred' => (int) DB::val('SELECT COUNT(*) FROM {{stars}} WHERE user_id = ?', [(int) $u['id']]),
            'links' => (int) DB::val('SELECT COUNT(*) FROM {{weblinks}} WHERE owner_id = ? OR created_by = ?', [(int) $u['id'], (int) $u['id']]),
            'tags' => Meta::tagsOf((int) $u['id']),
            'media' => Media::tree((int) $u['id']),
            'collections' => (static function () use ($u) { require_once FR_APP_DIR . '/api/collections.php'; return CollectionsApi::all((int) $u['id']); })(),
            'sharing' => [
                'people' => (int) DB::val('SELECT COUNT(*) FROM {{shares}} WHERE owner_id = ? OR created_by = ?', [(int) $u['id'], (int) $u['id']]),
                'links' => (int) DB::val("SELECT COUNT(*) FROM {{weblinks}} WHERE (owner_id = ? OR created_by = ?) AND role <> 'uploader'", [(int) $u['id'], (int) $u['id']]),
                'requests' => (int) DB::val("SELECT COUNT(*) FROM {{weblinks}} WHERE (owner_id = ? OR created_by = ?) AND role = 'uploader'", [(int) $u['id'], (int) $u['id']]),
            ],
            'notifications' => Notify::unreadCount((int) $u['id']),
            'usage' => ['used' => (int) $u['used_bytes'], 'quota' => (int) $u['quota_bytes']],
        ]);
    }

    // ------------------------------------------------------------------ content

    public static function download(): void
    {
        $paths = Api::paths();
        if (count($paths) === 1) {
            $ctx = Api::ctx($paths[0]);
            if (is_file($ctx->abs)) {
                Storage::download($ctx, false, Api::actor());
            }
        }
        $ctxs = array_map(static fn($p) => Api::ctx($p), $paths);
        $tmp = Archive::zip($ctxs, Api::actor());
        $name = count($ctxs) === 1 ? Util::safeName($ctxs[0]->name()) . '.zip' : (Settings::get('app_name') . ' download ' . date('Y-m-d His') . '.zip');
        foreach ($ctxs as $c) Users::addTraffic($c->ownerId, (int) filesize($tmp));
        Api::sendTempFile($tmp, $name);
    }

    public static function raw(): void
    {
        $ctx = Api::ctx();
        Storage::download($ctx, true, Api::actor());
    }

    public static function thumb(): void
    {
        $ctx = Api::ctx();
        if (!$ctx->can('read')) throw new PermissionException('Not allowed');
        if (!is_file($ctx->abs)) throw new NotFoundException('Not found');
        $size = Http::str('size', 'small');
        if (!isset(Thumbs::SIZES[$size])) $size = 'small';
        if (Thumbs::recentlyFailed($ctx->ownerId, $ctx->rel, (int) filemtime($ctx->abs), $size)) Http::fail('No preview', 404, 'no_thumb');
        $t = Thumbs::get($ctx->abs, $ctx->ownerId, $ctx->rel, $size);
        if (!$t) Http::fail('No preview', 404, 'no_thumb');
        Http::streamFile($t['path'], Util::baseName($ctx->rel) . ($t['mime'] === 'image/jpeg' ? '.jpg' : ''), true, $t['mime'], null, 86400);
    }

    public static function text(): void
    {
        $ctx = Api::ctx();
        $max = min(10 * 1024 * 1024, max(64 * 1024, Http::int('max', 5 * 1024 * 1024)));
        Http::ok(['content' => Storage::readText($ctx, $max), 'mtime' => (int) filemtime($ctx->abs), 'size' => (int) filesize($ctx->abs), 'name' => Util::baseName($ctx->rel), 'locked_by' => Meta::lockInfo($ctx->ownerId, $ctx->rel)]);
    }

    public static function save_text(): void
    {
        $ctx = Api::ctx();
        $content = (string) Http::input('content', '');
        $expected = Http::int('mtime', 0);
        if ($expected > 0 && (int) filemtime($ctx->abs) !== $expected && !Http::bool('force')) {
            Http::fail('The file was changed by somebody else while you were editing it', 409, 'conflict', ['mtime' => (int) filemtime($ctx->abs)]);
        }
        Http::ok(Storage::saveText($ctx, $content, Api::actor()));
    }

    public static function mkdir(): void
    {
        $parent = Api::ctx();
        Http::ok(Storage::mkdir($parent, Http::str('name'), Api::actor()));
    }

    public static function create_file(): void
    {
        $parent = Api::ctx();
        $name = Http::str('name');
        $content = (string) Http::input('content', '');
        $template = Http::str('template', '') ?: Util::ext($name);
        if ($content === '' && Templates::supports($template)) {
            $content = Templates::blank($template);
        }
        Http::ok(Storage::createFile($parent, $name, $content, Api::actor()));
    }

    public static function rename(): void
    {
        $ctx = Api::ctx();
        Http::ok(Storage::rename($ctx, Http::str('name'), Api::actor()));
    }

    private static function batch(string $op): void
    {
        $paths = Api::paths();
        $dest = Api::ctx(Http::str('to', '/'));
        $conflict = in_array(Http::str('conflict', 'rename'), ['rename', 'overwrite', 'skip'], true) ? Http::str('conflict') : 'rename';
        $done = []; $errors = [];
        foreach ($paths as $p) {
            try {
                $src = Api::ctx($p);
                $r = $op === 'move' ? Storage::move($src, $dest, Api::actor(), $conflict) : Storage::copy($src, $dest, Api::actor(), $conflict);
                $done[] = ['path' => $p, 'result' => $r];
            } catch (Throwable $e) {
                $errors[] = ['path' => $p, 'error' => $e->getMessage()];
            }
        }
        Http::ok(['done' => $done, 'errors' => $errors]);
    }

    public static function move(): void { self::batch('move'); }

    public static function copy(): void { self::batch('copy'); }

    public static function delete(): void
    {
        $paths = Api::paths();
        $permanent = Http::bool('permanent', false);
        $done = []; $errors = [];
        foreach ($paths as $p) {
            try {
                Storage::delete(Api::ctx($p), Api::actor(), $permanent);
                $done[] = $p;
            } catch (Throwable $e) {
                $errors[] = ['path' => $p, 'error' => $e->getMessage()];
            }
        }
        Http::ok(['done' => $done, 'errors' => $errors]);
    }

    // ------------------------------------------------------------------ metadata

    public static function star(): void
    {
        $u = Api::requireUser();
        $on = Http::bool('on', true);
        foreach (Api::paths() as $p) {
            $ctx = Api::ctx($p);
            $ctx->need('read');
            Meta::star((int) $u['id'], $ctx->ownerId, $ctx->rel, is_dir($ctx->abs), $on);
            Activity::log('star', ['owner_id' => $ctx->ownerId, 'path' => $ctx->rel, 'is_dir' => is_dir($ctx->abs) ? 1 : 0, 'details' => ['on' => $on]]);
        }
        Http::ok(['starred' => $on]);
    }

    public static function label(): void
    {
        $label = Http::str('label', '');
        foreach (Api::paths() as $p) {
            $ctx = Api::ctx($p);
            $ctx->need('meta');
            Meta::setLabel($ctx->ownerId, $ctx->rel, $label === '' ? null : $label, is_dir($ctx->abs), Api::actor()['id'] ?? null);
            Activity::log('metadata', ['owner_id' => $ctx->ownerId, 'path' => $ctx->rel, 'details' => ['label' => $label]]);
        }
        Http::ok(['label' => $label ?: null]);
    }

    public static function rating(): void
    {
        $ctx = Api::ctx();
        $ctx->need('meta');
        $r = Http::int('rating', 0);
        Meta::setRating($ctx->ownerId, $ctx->rel, $r, is_dir($ctx->abs), Api::actor()['id'] ?? null);
        Activity::log('metadata', ['owner_id' => $ctx->ownerId, 'path' => $ctx->rel, 'details' => ['rating' => $r]]);
        Http::ok(['rating' => $r ?: null]);
    }

    public static function describe(): void
    {
        $ctx = Api::ctx();
        $ctx->need('meta');
        $d = mb_substr(trim((string) Http::input('description', '')), 0, 5000);
        Meta::set($ctx->ownerId, $ctx->rel, ['description' => $d === '' ? null : $d], is_dir($ctx->abs), Api::actor()['id'] ?? null);
        Activity::log('metadata', ['owner_id' => $ctx->ownerId, 'path' => $ctx->rel, 'details' => ['description' => true]]);
        Http::ok(['description' => $d ?: null]);
    }

    public static function tags(): void
    {
        $tags = Http::arr('tags');
        $out = [];
        foreach (Api::paths() as $p) {
            $ctx = Api::ctx($p);
            $ctx->need('meta');
            $out = Meta::setTags($ctx->ownerId, $ctx->rel, $tags);
            Activity::log('tag', ['owner_id' => $ctx->ownerId, 'path' => $ctx->rel, 'details' => ['tags' => array_column($out, 'name')]]);
        }
        Http::ok(['tags' => $out]);
    }

    public static function metadata(): void
    {
        $ctx = Api::ctx();
        $ctx->need('meta');
        $values = Http::arr('values');
        $custom = Meta::setCustom($ctx->ownerId, $ctx->rel, $values, is_dir($ctx->abs), Api::actor()['id'] ?? null);
        Activity::log('metadata', ['owner_id' => $ctx->ownerId, 'path' => $ctx->rel, 'details' => ['fields' => array_keys($values)]]);
        Http::ok(['custom' => (object) $custom]);
    }

    public static function tags_list(): void
    {
        $u = Api::requireUser();
        Http::ok(Meta::tagsOf((int) $u['id']));
    }

    public static function tag_save(): void
    {
        $u = Api::requireUser();
        Meta::renameTag((int) $u['id'], Http::int('id'), Http::str('name'), Http::str('color') ?: null);
        Http::ok(Meta::tagsOf((int) $u['id']));
    }

    public static function tag_delete(): void
    {
        $u = Api::requireUser();
        Meta::deleteTag((int) $u['id'], Http::int('id'));
        Http::ok(Meta::tagsOf((int) $u['id']));
    }

    // ------------------------------------------------------------------ comments

    public static function comments(): void
    {
        $ctx = Api::ctx();
        if (!$ctx->can('read') && !$ctx->can('comment')) throw new PermissionException('Not allowed');
        Http::ok(Meta::comments($ctx->ownerId, $ctx->rel));
    }

    public static function comment(): void
    {
        $ctx = Api::ctx();
        $ctx->need('comment');
        $c = Meta::addComment($ctx->ownerId, $ctx->rel, Api::actor(), (string) Http::input('body', ''), Http::str('name') ?: null);
        Activity::log('comment', ['owner_id' => $ctx->ownerId, 'path' => $ctx->rel, 'details' => ['comment_id' => $c['id']]]);
        Notify::fileEvent('comment', $ctx->ownerId, $ctx->rel, is_dir($ctx->abs), Api::actor(), ['body' => Util::truncate($c['body'], 200), 'actor_name' => $c['user_name']]);
        Http::ok($c);
    }

    public static function delete_comment(): void
    {
        $u = Api::requireUser();
        $ctx = Api::ctx();
        Meta::deleteComment(Http::int('id'), $u, $ctx->ownerId);
        Http::ok(Meta::comments($ctx->ownerId, $ctx->rel));
    }

    // ------------------------------------------------------------------ versions

    public static function versions(): void
    {
        Http::ok(Storage::versions(Api::ctx()));
    }

    public static function version_download(): void
    {
        $ctx = Api::ctx();
        $v = Storage::versionFile($ctx, Http::int('id'));
        [$base, $ext] = Util::splitExt(Util::baseName($ctx->rel));
        Http::streamFile($v['abs'], $base . ' (version ' . date('Y-m-d H.i', strtotime((string) $v['row']['created_at'])) . ')' . $ext, Http::bool('inline'));
    }

    public static function version_restore(): void
    {
        Http::ok(Storage::restoreVersion(Api::ctx(), Http::int('id'), Api::actor()));
    }

    public static function version_delete(): void
    {
        $ctx = Api::ctx();
        Storage::deleteVersion($ctx, Http::int('id'));
        Http::ok(Storage::versions($ctx));
    }

    // ------------------------------------------------------------------ locks, follows, activity

    public static function lock(): void
    {
        $u = Api::requireUser();
        $ctx = Api::ctx();
        Meta::lock($ctx, $u);
        Http::ok(Meta::lockInfo($ctx->ownerId, $ctx->rel));
    }

    public static function unlock(): void
    {
        $u = Api::requireUser();
        $ctx = Api::ctx();
        Meta::unlock($ctx, $u, Http::bool('force'));
        Http::ok(null);
    }

    public static function follow(): void
    {
        $u = Api::requireUser();
        $ctx = Api::ctx();
        $ctx->need('read');
        $on = Http::bool('on', true);
        if ($on) {
            Notify::follow((int) $u['id'], $ctx->ownerId, $ctx->rel, Http::str('events', 'all') ?: 'all');
        } else {
            Notify::unfollow((int) $u['id'], $ctx->ownerId, $ctx->rel);
        }
        Activity::log($on ? 'follow' : 'unfollow', ['owner_id' => $ctx->ownerId, 'path' => $ctx->rel, 'is_dir' => is_dir($ctx->abs) ? 1 : 0]);
        Http::ok(['following' => $on]);
    }

    public static function activity(): void
    {
        $ctx = Api::ctx();
        $ctx->need('read');
        Http::ok(Activity::forFile($ctx->ownerId, $ctx->rel, Http::int('limit', 100)));
    }

    // ------------------------------------------------------------------ archives, images, office

    public static function archive_list(): void
    {
        $ctx = Api::ctx();
        $ctx->need('read');
        Http::ok(Archive::listEntries($ctx->abs, Util::baseName($ctx->rel)));
    }

    public static function extract(): void
    {
        $archive = Api::ctx();
        $dest = Api::ctx(Http::str('to', $archive->parentVpath() ?? '/'));
        Http::ok(Archive::extract($archive, $dest, Api::actor()));
    }

    public static function image_edit(): void
    {
        $ctx = Api::ctx();
        $ops = Http::arr('ops');
        if (!$ops) throw new RuntimeException('No operations');
        $saveAs = Util::safeName(Http::str('save_as', ''));
        $target = $ctx;
        if ($saveAs !== '' && $saveAs !== Util::baseName($ctx->rel)) {
            $parent = Paths::resolve($ctx->parentVpath() ?? '/', $ctx->user, $ctx->link);
            $target = $parent->childCtx($saveAs);
            $target->need('create');
        } else {
            $ctx->need('edit');
        }
        $tmp = fr_data_dir('temp') . '/img_' . Util::hex(6) . '.' . Util::ext($target->rel);
        if (!is_dir(dirname($tmp))) @mkdir(dirname($tmp), 0750, true);
        Thumbs::edit($ctx->abs, $tmp, $ops);
        $item = Storage::putFromPath($target, $tmp, ['actor' => Api::actor(), 'source' => 'edit', 'overwrite' => true]);
        Http::ok($item);
    }

    public static function office_config(): void
    {
        if (!class_exists('OnlyOffice') || !OnlyOffice::enabled()) throw new RuntimeException('ONLYOFFICE is not configured');
        $ctx = Api::ctx();
        $ctx->need('read');
        Http::ok(OnlyOffice::config($ctx, Api::user(), Http::str('mode', 'auto')));
    }

    /** Called by the Document Server, not by browsers. */
    public static function office_callback(): void
    {
        if (!class_exists('OnlyOffice')) Http::sendJson(['error' => 1]);
        Http::sendJson(OnlyOffice::callback(Http::str('token')));
    }

    /** Temporary public URL for an external read-only viewer (Google Docs viewer). */
    public static function viewer_url(): void
    {
        if (!Settings::bool('google_viewer_enabled')) throw new RuntimeException('External viewer is disabled');
        $ctx = Api::ctx();
        $ctx->need('read');
        $url = fr_public_url('/open/' . Tokens::fileAccess($ctx, Api::user(), 1800, false));
        Http::ok(['url' => $url, 'viewer' => 'https://docs.google.com/viewer?embedded=true&url=' . rawurlencode($url)]);
    }

    // ------------------------------------------------------------------ search & smart views

    public static function search(): void
    {
        $q = trim(Http::str('q', ''));
        $root = Api::ctx(Http::str('p', '/'));
        if (!$root->can('read')) throw new PermissionException('Not allowed');
        $within = $root->rel;
        $filters = ['kind' => Http::str('kind', ''), 'ext' => Http::str('ext', ''), 'is_dir' => Http::str('is_dir', ''), 'min_size' => Http::int('min_size', 0), 'max_size' => Http::int('max_size', 0), 'from' => Http::str('from', ''), 'to' => Http::str('to', ''), 'limit' => Http::int('limit', 0) ?: Settings::int('search_max_results')];
        $hasFilter = array_filter($filters, static fn($v, $k) => $k !== 'limit' && $v !== '' && $v !== 0, ARRAY_FILTER_USE_BOTH);
        if ($q === '' && !$hasFilter) Http::ok(['items' => [], 'content' => []]);
        $names = ($q !== '' || $hasFilter) ? Index::searchNames($root->ownerId, $q, $within, $filters) : [];
        $items = Storage::fromIndexRows($root, $names);
        $content = [];
        if ($q !== '' && Http::bool('content', true)) {
            $rows = Index::searchContent($root->ownerId, $q, $within, 100);
            $seen = array_flip(array_column($items, 'rel'));
            $rows = array_values(array_filter($rows, static fn($r) => !isset($seen[$r['path']])));
            foreach ($rows as &$r) { $r['name'] = $r['name'] ?? Util::baseName($r['path']); $r['is_dir'] = 0; }
            unset($r);
            $content = Storage::fromIndexRows($root, $rows);
        }
        // Custom metadata and tags are searched too.
        $metaHits = [];
        if ($q !== '') {
            $like = '%' . DB::escapeLike($q) . '%';
            $rows = DB::all('SELECT i.* FROM {{file_meta}} m JOIN {{file_index}} i ON i.owner_id = m.owner_id AND i.path_hash = m.path_hash WHERE m.owner_id = ? AND (m.description LIKE ? OR m.custom LIKE ?) LIMIT 100', [$root->ownerId, $like, $like]);
            $rows2 = DB::all('SELECT i.* FROM {{file_tags}} ft JOIN {{tags}} t ON t.id = ft.tag_id JOIN {{file_index}} i ON i.owner_id = ft.owner_id AND i.path_hash = ft.path_hash WHERE ft.owner_id = ? AND t.name LIKE ? LIMIT 100', [$root->ownerId, $like]);
            $seen = array_flip(array_merge(array_column($items, 'rel'), array_column($content, 'rel')));
            $extra = array_values(array_filter(array_merge($rows, $rows2), static fn($r) => !isset($seen[$r['path']])));
            $metaHits = Storage::fromIndexRows($root, $extra);
        }
        Activity::log('search', ['owner_id' => $root->ownerId, 'path' => $root->rel, 'details' => ['q' => $q, 'results' => count($items) + count($content) + count($metaHits)]]);
        Http::ok(['items' => $items, 'content' => $content, 'metadata' => $metaHits]);
    }

    public static function recent(): void
    {
        $u = Api::requireUser();
        $root = Paths::resolve('/', $u);
        Http::ok(['items' => Storage::fromIndexRows($root, Index::recent((int) $u['id'], Http::int('limit', 100)))]);
    }

    /** Media categories: documents / photos / videos / audio, optionally one sub group (subtype, year, artist). */
    public static function media(): void
    {
        $root = Api::ctx(Http::str('p', '/'));
        if (!$root->can('read')) throw new PermissionException('Not allowed');
        $cat = Http::str('cat', 'documents');
        $sub = Http::str('sub', '');
        $rows = Media::listRows($root->ownerId, $cat, $sub, Http::int('limit', 2000), Http::int('offset', 0), $root->rel);
        Http::ok(['items' => Storage::fromIndexRows($root, $rows), 'category' => $cat, 'sub' => $sub]);
    }

    public static function media_tree(): void
    {
        $u = Api::requireUser();
        Http::ok(Media::tree((int) $u['id']));
    }

    /** First characters of text files, for content previews in the grid view. */
    public static function snippets(): void
    {
        $paths = Api::paths();
        $out = [];
        foreach (array_slice($paths, 0, 120) as $p) {
            try {
                $ctx = Api::ctx($p);
                if (!$ctx->can('read') || !is_file($ctx->abs)) continue;
                $kind = Util::kind($ctx->rel);
                if (!in_array($kind, ['text', 'code', 'markdown'], true) || filesize($ctx->abs) > 2 * 1024 * 1024) continue;
                $fh = fopen($ctx->abs, 'rb');
                $data = $fh ? (string) fread($fh, 1200) : '';
                if ($fh) fclose($fh);
                if (!mb_check_encoding($data, 'UTF-8')) { $conv = @mb_convert_encoding($data, 'UTF-8', 'Windows-1252, ISO-8859-1, UTF-8'); $data = is_string($conv) ? $conv : ''; }
                if (preg_match('/[\x00-\x08\x0E-\x1F]/', $data)) continue; // binary
                $out[$p] = mb_substr($data, 0, 600);
            } catch (Throwable) {
            }
        }
        Http::ok($out);
    }

    public static function photos(): void
    {
        $root = Api::ctx(Http::str('p', '/'));
        $rows = Index::byKind($root->ownerId, ['image', 'raw', 'video'], Http::int('limit', 500), Http::int('offset', 0), $root->rel);
        Http::ok(['items' => Storage::fromIndexRows($root, $rows)]);
    }

    public static function starred(): void
    {
        $u = Api::requireUser();
        $out = [];
        foreach (Meta::starred((int) $u['id']) as $s) {
            try {
                $vpath = (int) $s['owner_id'] === (int) $u['id'] ? '/' . $s['path'] : null;
                if ($vpath === null) {
                    // Starred inside a share: find a share that still grants access.
                    $share = DB::one("SELECT id, path FROM {{shares}} WHERE owner_id = ? AND target_type = 'user' AND target_id = ? AND (? = path OR ? LIKE CONCAT(path, '/%')) ORDER BY LENGTH(path) DESC LIMIT 1", [(int) $s['owner_id'], (int) $u['id'], $s['path'], $s['path']]);
                    if (!$share) continue;
                    $sub = $s['path'] === $share['path'] ? '' : substr($s['path'], strlen($share['path']) + 1);
                    $vpath = '@' . $share['id'] . ($sub !== '' ? '/' . $sub : '');
                }
                $ctx = Paths::resolveExisting($vpath, $u);
                $out[] = Storage::item($ctx);
            } catch (Throwable) {
                Meta::star((int) $u['id'], (int) $s['owner_id'], (string) $s['path'], false, false);
            }
        }
        Http::ok(['items' => $out]);
    }

    public static function by_tag(): void
    {
        $u = Api::requireUser();
        $root = Paths::resolve('/', $u);
        $paths = array_column(Meta::pathsWithTag((int) $u['id'], Http::int('id')), 'path');
        $rows = [];
        foreach (array_chunk($paths, 500) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            $rows = array_merge($rows, DB::all("SELECT * FROM {{file_index}} WHERE owner_id = ? AND path_hash IN ($in)", array_merge([(int) $u['id']], array_map('sha1', $chunk))));
        }
        Http::ok(['items' => Storage::fromIndexRows($root, $rows)]);
    }

    public static function by_label(): void
    {
        $u = Api::requireUser();
        $root = Paths::resolve('/', $u);
        $rows = DB::all('SELECT i.* FROM {{file_meta}} m JOIN {{file_index}} i ON i.owner_id = m.owner_id AND i.path_hash = m.path_hash WHERE m.owner_id = ? AND m.label = ? ORDER BY i.name LIMIT 2000', [(int) $u['id'], Http::str('label')]);
        Http::ok(['items' => Storage::fromIndexRows($root, $rows)]);
    }

    public static function check_sync(): void
    {
        Http::ok(Storage::checkSync(Api::ctx(), Http::arr('files')));
    }

    // ------------------------------------------------------------------ trash

    public static function trash(): void
    {
        $u = Api::requireUser();
        Http::ok(['items' => Storage::trashList($u)]);
    }

    public static function trash_restore(): void
    {
        $u = Api::requireUser();
        $ids = Http::arr('ids') ?: [Http::int('id')];
        $out = []; $errors = [];
        foreach ($ids as $id) {
            try { $out[] = Storage::trashRestore($u, (int) $id, $u, Http::str('to', '') ?: null); } catch (Throwable $e) { $errors[] = ['id' => (int) $id, 'error' => $e->getMessage()]; }
        }
        Http::ok(['done' => $out, 'errors' => $errors]);
    }

    public static function trash_purge(): void
    {
        $u = Api::requireUser();
        $ids = Http::arr('ids');
        if ($ids) {
            $n = 0;
            foreach ($ids as $id) $n += Storage::trashPurge($u, (int) $id, $u);
        } else {
            $n = Storage::trashPurge($u, Http::int('id', 0) ?: null, $u);
        }
        Http::ok(['purged' => $n]);
    }
}
