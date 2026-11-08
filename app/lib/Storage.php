<?php
declare(strict_types=1);

/**
 * Every operation on files and folders goes through here, whatever the client
 * (web, WebDAV, API, public link). Permissions, locks, quotas, versions, trash,
 * the search index, metadata bookkeeping, notifications and the activity log
 * are all applied in one place.
 */
final class Storage
{
    public const SORTS = ['name', 'size', 'mtime', 'kind', 'ext'];

    // ------------------------------------------------------------------
    // Listing
    // ------------------------------------------------------------------

    public static function isHidden(string $name, bool $showHidden): bool
    {
        if ($showHidden) return false;
        if ($name !== '' && $name[0] === '.') return true;
        static $list = null;
        if ($list === null) {
            $list = array_filter(array_map('trim', explode(',', (string) Settings::get('hidden_files'))));
        }
        return in_array($name, $list, true);
    }

    /** Describe one directory entry (no metadata). */
    private static function entry(Ctx $parent, string $name, string $abs): ?array
    {
        $stat = @lstat($abs);
        if ($stat === false) return null;
        $isLink = is_link($abs);
        if ($isLink) {
            $stat = @stat($abs);
            if ($stat === false) return null;
        }
        $isDir = ($stat['mode'] & 0170000) === 0040000;
        $rel = Util::joinPath($parent->rel, $name);
        return [
            'name' => $name,
            'path' => $parent->child($name),
            'rel' => $rel,
            'is_dir' => $isDir,
            'size' => $isDir ? 0 : (int) $stat['size'],
            'mtime' => (int) $stat['mtime'],
            'ctime' => (int) $stat['ctime'],
            'mime' => $isDir ? 'inode/directory' : Util::mime($name),
            'kind' => Util::kind($name, $isDir),
            'ext' => $isDir ? '' : Util::ext($name),
        ];
    }

    /** Attach metadata, share badges and thumbnail availability to entries. */
    private static function decorate(Ctx $ctx, array $entries, bool $withShares): array
    {
        $viewerId = $ctx->user['id'] ?? null;
        $meta = Meta::bulk($ctx->ownerId, array_column($entries, 'rel'), $viewerId ? (int) $viewerId : null);
        $shared = $withShares ? Shares::sharedPaths($ctx->ownerId, array_map('sha1', array_column($entries, 'rel'))) : [];
        foreach ($entries as &$e) {
            $h = sha1($e['rel']);
            $m = $meta[$h] ?? [];
            $e['label'] = $m['label'] ?? null;
            $e['rating'] = $m['rating'] ?? null;
            $e['description'] = $m['description'] ?? null;
            $e['custom'] = $m['custom'] ?? (object) [];
            $e['tags'] = $m['tags'] ?? [];
            $e['starred'] = !empty($m['starred']);
            $e['comments'] = $m['comments'] ?? 0;
            $e['locked_by'] = $m['locked_by'] ?? null;
            $e['locked_by_name'] = $m['locked_by_name'] ?? null;
            $e['has_exif'] = !empty($m['has_exif']);
            $e['downloads'] = $m['downloads'] ?? 0;
            $e['shared'] = $shared[$h] ?? null;
            $e['thumb'] = !$e['is_dir'] && Thumbs::supports($e['name']);
            $e['owner_id'] = $ctx->ownerId;
        }
        unset($e);
        return $entries;
    }

    private static function sortEntries(array &$entries, string $sort, string $dir): void
    {
        $sort = in_array($sort, self::SORTS, true) ? $sort : 'name';
        $mul = $dir === 'desc' ? -1 : 1;
        usort($entries, static function ($a, $b) use ($sort, $mul) {
            if ($a['is_dir'] !== $b['is_dir']) return $a['is_dir'] ? -1 : 1;
            switch ($sort) {
                case 'size': $c = $a['size'] <=> $b['size']; break;
                case 'mtime': $c = $a['mtime'] <=> $b['mtime']; break;
                case 'kind': $c = strcmp($a['kind'], $b['kind']); break;
                case 'ext': $c = strcmp($a['ext'], $b['ext']); break;
                default: $c = 0;
            }
            if ($c === 0) $c = strnatcasecmp($a['name'], $b['name']);
            return $c * $mul;
        });
    }

    /**
     * List a folder.
     * @param array $o sort, dir, offset, limit, show_hidden, with_meta (default true)
     */
    public static function listDir(Ctx $ctx, array $o = []): array
    {
        if (!is_dir($ctx->abs)) {
            if (is_file($ctx->abs)) throw new RuntimeException('Not a folder');
            throw new NotFoundException('Folder not found');
        }
        $uploaderOnly = !$ctx->can('read');
        if ($uploaderOnly && !$ctx->can('create')) {
            throw new PermissionException('You are not allowed to view this folder');
        }
        $showHidden = !empty($o['show_hidden']) || Util::bool(Settings::get('show_hidden_files'));
        $entries = [];
        $forIndex = [];
        $dh = @opendir($ctx->abs);
        if ($dh === false) throw new PermissionException('Folder cannot be read');
        while (($name = readdir($dh)) !== false) {
            if ($name === '.' || $name === '..') continue;
            $e = self::entry($ctx, $name, $ctx->abs . '/' . $name);
            if ($e === null) continue;
            $forIndex[] = ['rel' => $e['rel'], 'is_dir' => $e['is_dir'], 'size' => $e['size'], 'mtime' => $e['mtime']];
            if (self::isHidden($name, $showHidden)) continue;
            if ($uploaderOnly && !$e['is_dir']) continue; // uploaders see folders only, never files
            $entries[] = $e;
        }
        closedir($dh);
        if (count($forIndex) <= 20000) {
            Index::syncDir($ctx->ownerId, $ctx->rel, $forIndex);
        }
        self::sortEntries($entries, (string) ($o['sort'] ?? 'name'), (string) ($o['dir'] ?? 'asc'));
        $total = count($entries);
        $offset = max(0, (int) ($o['offset'] ?? 0));
        $limit = (int) ($o['limit'] ?? 0);
        if ($offset > 0 || $limit > 0) {
            $entries = array_slice($entries, $offset, $limit > 0 ? $limit : null);
        }
        if ($o['with_meta'] ?? true) {
            $entries = self::decorate($ctx, $entries, $ctx->root === 'home');
        }
        return [
            'items' => $entries,
            'total' => $total,
            'folder' => self::folderInfo($ctx),
        ];
    }

    /** Build decorated entries from file_index rows (search results, recent, photos, starred...). */
    public static function fromIndexRows(Ctx $root, array $rows, bool $checkExists = true): array
    {
        $home = Users::homeDir($root->owner);
        $base = $root->root === 'home' ? '' : ($root->root === 'share' ? (string) $root->share['path'] : (string) $root->link['path']);
        $prefix = $root->root === 'home' ? '' : ($root->root === 'share' ? '@' . $root->share['id'] : '');
        $entries = [];
        foreach ($rows as $r) {
            $rel = (string) $r['path'];
            if ($base !== '' && !Util::pathWithin($rel, $base)) continue;
            $abs = $home . '/' . $rel;
            if ($checkExists && !file_exists($abs)) continue;
            $isDir = (bool) $r['is_dir'];
            $sub = $base === '' ? $rel : ($rel === $base ? '' : substr($rel, strlen($base) + 1));
            $e = [
                'name' => (string) $r['name'], 'path' => $prefix . '/' . $sub, 'rel' => $rel, 'is_dir' => $isDir,
                'size' => $isDir ? 0 : (int) $r['size'], 'mtime' => (int) $r['mtime'], 'ctime' => (int) $r['mtime'],
                'mime' => (string) ($r['mime'] ?: Util::mime((string) $r['name'])), 'kind' => (string) ($r['kind'] ?: Util::kind((string) $r['name'], $isDir)), 'ext' => $isDir ? '' : Util::ext((string) $r['name']),
            ];
            if (isset($r['snippet'])) $e['snippet'] = $r['snippet'];
            if (isset($r['score'])) $e['score'] = (float) $r['score'];
            $entries[] = $e;
        }
        return self::decorate($root, $entries, $root->root === 'home');
    }

    public static function folderInfo(Ctx $ctx): array
    {
        $following = $ctx->user ? Notify::isFollowing((int) $ctx->user['id'], $ctx->ownerId, $ctx->rel) : false;
        return [
            'path' => $ctx->vpath,
            'rel' => $ctx->rel,
            'name' => $ctx->name(),
            'root' => $ctx->root,
            'role' => $ctx->role,
            'role_label' => Paths::ROLE_LABELS[$ctx->role] ?? $ctx->role,
            'perms' => $ctx->perms,
            'owner' => Users::brief($ctx->owner),
            'share_id' => $ctx->share['id'] ?? null,
            'following' => $following,
            'breadcrumbs' => self::breadcrumbs($ctx),
            'mtime' => file_exists($ctx->abs) ? (int) filemtime($ctx->abs) : 0,
        ];
    }

    public static function breadcrumbs(Ctx $ctx): array
    {
        $crumbs = [];
        if ($ctx->root === 'home') {
            $crumbs[] = ['name' => 'My Files', 'path' => '/'];
            $acc = '';
            foreach (array_filter(explode('/', $ctx->rel), 'strlen') as $seg) {
                $acc .= '/' . $seg;
                $crumbs[] = ['name' => $seg, 'path' => $acc];
            }
        } elseif ($ctx->root === 'share') {
            $crumbs[] = ['name' => 'Shared with me', 'path' => '~shared'];
            $base = '@' . $ctx->share['id'];
            $crumbs[] = ['name' => $ctx->share['alias'] ?: Util::baseName((string) $ctx->share['path']), 'path' => $base];
            $acc = $base;
            foreach (array_filter(explode('/', $ctx->shareRel()), 'strlen') as $seg) {
                $acc .= '/' . $seg;
                $crumbs[] = ['name' => $seg, 'path' => $acc];
            }
        } else {
            $crumbs[] = ['name' => $ctx->link['title'] ?: Util::baseName((string) $ctx->link['path']), 'path' => '/'];
            $acc = '';
            foreach (array_filter(explode('/', $ctx->shareRel()), 'strlen') as $seg) {
                $acc .= '/' . $seg;
                $crumbs[] = ['name' => $seg, 'path' => $acc];
            }
        }
        return $crumbs;
    }

    /** Sub folders only, for the navigation tree. */
    public static function subfolders(Ctx $ctx): array
    {
        if (!is_dir($ctx->abs) || (!$ctx->can('read') && !$ctx->can('create'))) return [];
        $out = [];
        $showHidden = Util::bool(Settings::get('show_hidden_files'));
        foreach (@scandir($ctx->abs) ?: [] as $name) {
            if ($name === '.' || $name === '..' || self::isHidden($name, $showHidden)) continue;
            $abs = $ctx->abs . '/' . $name;
            if (is_dir($abs)) {
                $hasChildren = false;
                foreach (@scandir($abs) ?: [] as $c) {
                    if ($c !== '.' && $c !== '..' && is_dir($abs . '/' . $c) && !self::isHidden($c, $showHidden)) { $hasChildren = true; break; }
                }
                $out[] = ['name' => $name, 'path' => $ctx->child($name), 'has_children' => $hasChildren];
            }
        }
        usort($out, static fn($a, $b) => strnatcasecmp($a['name'], $b['name']));
        return $out;
    }

    /** Full description of one item. */
    public static function item(Ctx $ctx): array
    {
        if (!file_exists($ctx->abs)) throw new NotFoundException('Not found');
        if (!$ctx->can('read') && !$ctx->can('create')) throw new PermissionException('Not allowed');
        if ($ctx->rel === '' || ($ctx->root !== 'home' && $ctx->shareRel() === '')) {
            // The root of a home / share / link.
            $isDir = is_dir($ctx->abs);
            $e = [
                'name' => $ctx->name(), 'path' => $ctx->vpath, 'rel' => $ctx->rel, 'is_dir' => $isDir,
                'size' => $isDir ? 0 : (int) filesize($ctx->abs), 'mtime' => (int) filemtime($ctx->abs), 'ctime' => (int) filectime($ctx->abs),
                'mime' => $isDir ? 'inode/directory' : Util::mime($ctx->name()), 'kind' => Util::kind(Util::baseName($ctx->rel), $isDir), 'ext' => $isDir ? '' : Util::ext($ctx->name()),
            ];
        } else {
            $parent = Paths::resolve($ctx->parentVpath() ?? '/', $ctx->user, $ctx->link);
            $e = self::entry($parent, Util::baseName($ctx->rel), $ctx->abs);
            if ($e === null) throw new NotFoundException('Not found');
        }
        $e = self::decorate($ctx, [$e], $ctx->root === 'home')[0];
        $e['perms'] = $ctx->perms;
        $e['role'] = $ctx->role;
        $e['following'] = $ctx->user ? Notify::isFollowing((int) $ctx->user['id'], $ctx->ownerId, $ctx->rel) : false;
        $e['versions'] = (int) DB::val('SELECT COUNT(*) FROM {{versions}} WHERE owner_id = ? AND path_hash = ?', [$ctx->ownerId, sha1($ctx->rel)]);
        $meta = Meta::get($ctx->ownerId, $ctx->rel);
        $e['exif'] = $meta && $meta['exif'] ? Util::jsonDecode($meta['exif'], null) : null;
        $e['created_by'] = $meta && $meta['created_by'] ? Users::brief(Users::find((int) $meta['created_by'])) : null;
        $e['views'] = $meta ? (int) $meta['views'] : 0;
        if ($e['is_dir']) {
            $e['children'] = count(array_filter(@scandir($ctx->abs) ?: [], static fn($n) => $n !== '.' && $n !== '..'));
        }
        $e['metadata_fields'] = Meta::fieldsFor($e['name'], $e['is_dir']);
        return $e;
    }

    public static function sizeOf(Ctx $ctx): array
    {
        $ctx->need('read');
        return Util::treeSize($ctx->abs);
    }

    // ------------------------------------------------------------------
    // Helpers for writes
    // ------------------------------------------------------------------

    private static function assertName(string $name): string
    {
        $clean = Util::safeName($name);
        if ($clean === '' || $clean !== str_replace(["\r", "\n"], '', $name) && Util::safeName($name) === '') {
            throw new RuntimeException('Invalid name');
        }
        return $clean;
    }

    private static function actorName(?array $actor, ?Ctx $ctx = null): string
    {
        if ($actor) return $actor['name'] ?: $actor['username'];
        if ($ctx && $ctx->link) return 'Link visitor';
        return 'System';
    }

    private static function logFile(string $action, Ctx $ctx, array $extra = []): void
    {
        Activity::log($action, ['owner_id' => $ctx->ownerId, 'path' => $ctx->rel, 'is_dir' => is_dir($ctx->abs) ? 1 : 0, 'client' => $ctx->root === 'link' ? 'link' : Activity::$client, 'details' => $ctx->link ? ['link_id' => (int) $ctx->link['id']] + ($extra['details'] ?? []) : ($extra['details'] ?? null)] + $extra);
    }

    // ------------------------------------------------------------------
    // Create
    // ------------------------------------------------------------------

    public static function mkdir(Ctx $parent, string $name, ?array $actor): array
    {
        $parent->need('create');
        if (!is_dir($parent->abs)) throw new NotFoundException('Parent folder not found');
        $name = self::assertName($name);
        $abs = $parent->abs . '/' . $name;
        if (file_exists($abs)) throw new RuntimeException('An item with this name exists already');
        if (!@mkdir($abs, 0750)) throw new RuntimeException('Folder could not be created');
        $ctx = $parent->childCtx($name);
        Index::upsert($ctx->ownerId, $ctx->rel, true, 0, time());
        Meta::set($ctx->ownerId, $ctx->rel, [], true, $actor['id'] ?? null);
        self::logFile('create_folder', $ctx);
        Notify::fileEvent('create_folder', $ctx->ownerId, $ctx->rel, true, $actor);
        return self::item($ctx);
    }

    /** Create folders along a relative path (used by folder uploads). Returns the deepest Ctx. */
    public static function mkdirs(Ctx $parent, string $relPath, ?array $actor): Ctx
    {
        $ctx = $parent;
        foreach (array_filter(explode('/', Util::normPath($relPath)), 'strlen') as $seg) {
            $seg = self::assertName($seg);
            $next = $ctx->abs . '/' . $seg;
            if (!is_dir($next)) {
                if (file_exists($next)) throw new RuntimeException('A file blocks the folder path: ' . $seg);
                $ctx->need('create');
                if (!@mkdir($next, 0750)) throw new RuntimeException('Folder could not be created: ' . $seg);
                $c = $ctx->childCtx($seg);
                Index::upsert($c->ownerId, $c->rel, true, 0, time());
            }
            $ctx = $ctx->childCtx($seg);
        }
        return $ctx;
    }

    public static function createFile(Ctx $parent, string $name, string $content, ?array $actor): array
    {
        $parent->need('create');
        $name = self::assertName($name);
        if (file_exists($parent->abs . '/' . $name)) throw new RuntimeException('An item with this name exists already');
        $ctx = $parent->childCtx($name);
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, $content);
        rewind($stream);
        return self::putStream($ctx, $stream, strlen($content), ['actor' => $actor, 'source' => 'create']);
    }

    /**
     * Write a file from a stream (upload, WebDAV PUT, editor). Handles the
     * version history, quota, extension rules, locks, index, EXIF and notifications.
     * @param array $o actor, source (upload|edit|webdav|copy|create|restore), mtime, overwrite (bool, default true), notify (bool)
     */
    public static function putStream(Ctx $ctx, $stream, int $size, array $o = []): array
    {
        $exists = is_file($ctx->abs);
        if (is_dir($ctx->abs)) throw new RuntimeException('A folder with this name exists');
        $ctx->need($exists ? 'edit' : 'create');
        if ($exists && array_key_exists('overwrite', $o) && !$o['overwrite']) throw new RuntimeException('File exists');
        $actor = $o['actor'] ?? null;
        $name = Util::baseName($ctx->rel);
        Users::assertFileAllowed($ctx->owner, $name);
        $max = Users::maxUpload($ctx->owner);
        if ($max > 0 && $size > $max) throw new RuntimeException('File is larger than the allowed maximum of ' . Util::humanSize($max));
        $oldSize = $exists ? (int) filesize($ctx->abs) : 0;
        Users::assertQuota($ctx->owner, $size - $oldSize);
        if ($exists) Meta::assertUnlocked($ctx->ownerId, $ctx->rel, $actor);
        $dir = dirname($ctx->abs);
        if (!is_dir($dir)) throw new NotFoundException('Folder not found');
        if ($exists && empty($o['no_version'])) {
            self::keepVersion($ctx, $actor, $o['source'] ?? 'overwrite');
        }
        $tmp = $dir . '/.' . $name . '.' . Util::hex(4) . '.part';
        $out = @fopen($tmp, 'wb');
        if ($out === false) throw new RuntimeException('Cannot write to this folder');
        $written = 0;
        while (!feof($stream)) {
            $buf = fread($stream, 1024 * 1024);
            if ($buf === false || $buf === '') break;
            $written += fwrite($out, $buf);
        }
        fclose($out);
        if ($size > 0 && $written !== $size) {
            @unlink($tmp);
            throw new RuntimeException('Incomplete transfer (' . $written . ' of ' . $size . ' bytes)');
        }
        if (!@rename($tmp, $ctx->abs)) {
            @unlink($tmp);
            throw new RuntimeException('File could not be saved');
        }
        return self::afterWrite($ctx, $exists, $oldSize, $written, $o);
    }

    /** Move an already uploaded temporary file into place (no copying). */
    public static function putFromPath(Ctx $ctx, string $tmpPath, array $o = []): array
    {
        $exists = is_file($ctx->abs);
        if (is_dir($ctx->abs)) throw new RuntimeException('A folder with this name exists');
        $ctx->need($exists ? 'edit' : 'create');
        if ($exists && array_key_exists('overwrite', $o) && !$o['overwrite']) throw new RuntimeException('File exists');
        $actor = $o['actor'] ?? null;
        $name = Util::baseName($ctx->rel);
        Users::assertFileAllowed($ctx->owner, $name);
        $size = (int) filesize($tmpPath);
        $max = Users::maxUpload($ctx->owner);
        if ($max > 0 && $size > $max) { @unlink($tmpPath); throw new RuntimeException('File is larger than the allowed maximum of ' . Util::humanSize($max)); }
        $oldSize = $exists ? (int) filesize($ctx->abs) : 0;
        Users::assertQuota($ctx->owner, $size - $oldSize);
        if ($exists) Meta::assertUnlocked($ctx->ownerId, $ctx->rel, $actor);
        if (!is_dir(dirname($ctx->abs))) throw new NotFoundException('Folder not found');
        if ($exists && empty($o['no_version'])) self::keepVersion($ctx, $actor, $o['source'] ?? 'overwrite');
        if (!@rename($tmpPath, $ctx->abs)) {
            if (!@copy($tmpPath, $ctx->abs)) throw new RuntimeException('File could not be saved');
            @unlink($tmpPath);
        }
        @chmod($ctx->abs, 0640);
        return self::afterWrite($ctx, $exists, $oldSize, $size, $o);
    }

    private static function afterWrite(Ctx $ctx, bool $existed, int $oldSize, int $newSize, array $o): array
    {
        $actor = $o['actor'] ?? null;
        if (!empty($o['mtime'])) @touch($ctx->abs, (int) $o['mtime']);
        Users::addUsage($ctx->ownerId, $newSize - $oldSize);
        if ($actor) Users::addTraffic((int) $actor['id'], 0, $newSize);
        Index::upsert($ctx->ownerId, $ctx->rel, false, $newSize, (int) filemtime($ctx->abs));
        Thumbs::forget($ctx->ownerId, $ctx->rel, false);
        if (!$existed) Meta::set($ctx->ownerId, $ctx->rel, [], false, $actor['id'] ?? null);
        if (Settings::bool('exif_enabled') && in_array(Util::kind($ctx->rel), ['image', 'raw'], true)) {
            try {
                $exif = Exif::read($ctx->abs);
                Meta::set($ctx->ownerId, $ctx->rel, ['exif' => $exif ?: null], false, $actor['id'] ?? null);
            } catch (Throwable $e) {
                fr_log('exif failed: ' . $e->getMessage(), 'warn');
            }
        }
        Indexer::schedule($ctx->ownerId, $ctx->rel, $ctx->abs);
        $source = $o['source'] ?? 'upload';
        $action = $source === 'edit' ? 'edit' : ($source === 'create' ? 'create_file' : 'upload');
        if ($ctx->root === 'link') $action = $source === 'edit' ? 'edit' : 'link_upload';
        self::logFile($action, $ctx, ['user' => $actor, 'details' => ['size' => $newSize, 'overwrite' => $existed, 'source' => $source]]);
        if ($o['notify'] ?? true) {
            Notify::fileEvent($action === 'edit' ? 'edit' : ($ctx->root === 'link' ? 'link_upload' : 'upload'), $ctx->ownerId, $ctx->rel, false, $actor, ['coalesce_key' => ($actor['id'] ?? 'link') . ':' . Util::dirName($ctx->rel), 'actor_name' => self::actorName($actor, $ctx)]);
        }
        return self::item($ctx);
    }

    /** Save text from the built-in editor. */
    public static function saveText(Ctx $ctx, string $content, ?array $actor): array
    {
        if (!is_file($ctx->abs)) throw new NotFoundException('File not found');
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, $content);
        rewind($stream);
        return self::putStream($ctx, $stream, strlen($content), ['actor' => $actor, 'source' => 'edit']);
    }

    // ------------------------------------------------------------------
    // Read
    // ------------------------------------------------------------------

    public static function readText(Ctx $ctx, int $max = 5 * 1024 * 1024): string
    {
        $ctx->need('read');
        if (!is_file($ctx->abs)) throw new NotFoundException('File not found');
        if (filesize($ctx->abs) > $max) throw new RuntimeException('File is too large to open in the editor (' . Util::humanSize($max) . ' limit)');
        $data = (string) file_get_contents($ctx->abs);
        if (!mb_check_encoding($data, 'UTF-8')) {
            $conv = @mb_convert_encoding($data, 'UTF-8', 'Windows-1252, ISO-8859-1, UTF-8');
            if (is_string($conv)) $data = $conv;
        }
        return $data;
    }

    /** Stream a file to the client, counting traffic and recording the download. */
    public static function download(Ctx $ctx, bool $inline, ?array $actor): never
    {
        if (!is_file($ctx->abs)) throw new NotFoundException('File not found');
        if (!$ctx->can('download') && !($inline && $ctx->can('read'))) {
            throw new PermissionException('Downloading is not allowed here');
        }
        if ($ctx->root === 'link' && !$inline) {
            $l = $ctx->link;
            if ((int) $l['max_downloads'] > 0 && (int) $l['downloads'] >= (int) $l['max_downloads']) throw new PermissionException('The download limit of this link has been reached');
        }
        Users::assertTraffic($ctx->owner, (int) filesize($ctx->abs));
        $name = Util::baseName($ctx->rel);
        $ownerId = $ctx->ownerId;
        $rel = $ctx->rel;
        $isLink = $ctx->root === 'link';
        $link = $ctx->link;
        $actorId = $actor['id'] ?? null;
        $onBytes = static function (int $sent) use ($ownerId, $rel, $inline, $isLink, $link, $actorId, $actor): void {
            Users::addTraffic($ownerId, $sent);
            if ($actorId && $actorId !== $ownerId) Users::addTraffic((int) $actorId, $sent);
            if (!$inline) {
                Meta::bumpCounter($ownerId, $rel, 'downloads');
                if ($isLink) Links::countDownload($link);
                Activity::log($isLink ? 'link_download' : 'download', ['owner_id' => $ownerId, 'path' => $rel, 'client' => $isLink ? 'link' : Activity::$client, 'details' => ['bytes' => $sent] + ($isLink ? ['link_id' => (int) $link['id'], 'link_creator' => $link['created_by']] : [])]);
                if (!$isLink || $link['notify']) {
                    Notify::fileEvent($isLink ? 'link_download' : 'download', $ownerId, $rel, false, $actor, ['actor_name' => $actor ? ($actor['name'] ?: $actor['username']) : 'A link visitor']);
                }
            } else {
                Meta::bumpCounter($ownerId, $rel, 'views');
            }
        };
        if ($inline) {
            Activity::log('preview', ['owner_id' => $ownerId, 'path' => $rel, 'client' => $isLink ? 'link' : Activity::$client]);
        }
        Http::streamFile($ctx->abs, $name, $inline, null, $onBytes, $inline ? 3600 : 0);
    }

    // ------------------------------------------------------------------
    // Rename / move / copy
    // ------------------------------------------------------------------

    public static function rename(Ctx $ctx, string $newName, ?array $actor): array
    {
        $ctx->need('rename');
        if (!file_exists($ctx->abs)) throw new NotFoundException('Not found');
        if ($ctx->rel === '' || ($ctx->root !== 'home' && $ctx->shareRel() === '')) throw new RuntimeException('This item cannot be renamed here');
        $newName = self::assertName($newName);
        $isDir = is_dir($ctx->abs);
        if (!$isDir) Users::assertFileAllowed($ctx->owner, $newName);
        Meta::assertUnlocked($ctx->ownerId, $ctx->rel, $actor, $isDir);
        $newAbs = dirname($ctx->abs) . '/' . $newName;
        if ($newName === Util::baseName($ctx->rel)) return self::item($ctx);
        if (file_exists($newAbs) && strcasecmp($newName, Util::baseName($ctx->rel)) !== 0) throw new RuntimeException('An item with this name exists already');
        if (!@rename($ctx->abs, $newAbs)) throw new RuntimeException('Rename failed');
        $newRel = Util::joinPath(Util::dirName($ctx->rel), $newName);
        Meta::movePrefix($ctx->ownerId, $ctx->rel, $newRel, $isDir);
        Index::upsert($ctx->ownerId, $newRel, $isDir, $isDir ? 0 : (int) filesize($newAbs), (int) filemtime($newAbs));
        Activity::log('rename', ['owner_id' => $ctx->ownerId, 'path' => $ctx->rel, 'path2' => $newRel, 'is_dir' => $isDir ? 1 : 0]);
        Notify::fileEvent('rename', $ctx->ownerId, $newRel, $isDir, $actor, ['body' => 'Renamed from "' . Util::baseName($ctx->rel) . '" to "' . $newName . '"']);
        $parent = $ctx->parentVpath() ?? '/';
        return self::item(Paths::resolve(Paths::join($parent, $newName), $ctx->user, $ctx->link));
    }

    /**
     * Move an item into a folder. $conflict: rename | overwrite | skip.
     * Returns the new item or null when skipped.
     */
    public static function move(Ctx $src, Ctx $dstDir, ?array $actor, string $conflict = 'rename'): ?array
    {
        if (!file_exists($src->abs)) throw new NotFoundException('Source not found');
        if (!is_dir($dstDir->abs)) throw new NotFoundException('Destination folder not found');
        $isDir = is_dir($src->abs);
        if ($src->rel === '' || ($src->root !== 'home' && $src->shareRel() === '')) throw new RuntimeException('This item cannot be moved');
        $sameOwner = $src->ownerId === $dstDir->ownerId;
        // Moving = delete from source + create at destination.
        $src->need($sameOwner && $src->root === $dstDir->root ? 'rename' : 'delete');
        $dstDir->need('create');
        Meta::assertUnlocked($src->ownerId, $src->rel, $actor, $isDir);
        $name = Util::baseName($src->rel);
        if (!$isDir) Users::assertFileAllowed($dstDir->owner, $name);
        if ($isDir && Util::pathWithin($dstDir->abs, $src->abs)) throw new RuntimeException('A folder cannot be moved into itself');
        $target = $dstDir->abs . '/' . $name;
        if (realpath(dirname($target)) === realpath(dirname($src->abs)) && $sameOwner) return self::item($src); // same place
        if (file_exists($target)) {
            if ($conflict === 'skip') return null;
            if ($conflict === 'overwrite') {
                self::delete($dstDir->childCtx($name), $actor, false);
            } else {
                $name = Util::uniqueName($dstDir->abs, $name);
                $target = $dstDir->abs . '/' . $name;
            }
        }
        $size = $isDir ? Util::treeSize($src->abs)['size'] : (int) filesize($src->abs);
        if (!$sameOwner) Users::assertQuota($dstDir->owner, $size);
        if (!@rename($src->abs, $target)) {
            // Cross-device: copy then delete.
            if (!Util::copyTree($src->abs, $target)) throw new RuntimeException('Move failed');
            Util::rmTree($src->abs);
        }
        $newRel = Util::joinPath($dstDir->rel, $name);
        if ($sameOwner) {
            Meta::movePrefix($src->ownerId, $src->rel, $newRel, $isDir);
        } else {
            Meta::copyMeta($src->ownerId, $src->rel, $dstDir->ownerId, $newRel, $isDir);
            Meta::deleteFor($src->ownerId, $src->rel, $isDir);
            Users::addUsage($src->ownerId, -$size);
            Users::addUsage($dstDir->ownerId, $size);
            Index::remove($src->ownerId, $src->rel, $isDir);
        }
        if ($isDir) {
            Indexer::scheduleTree($dstDir->ownerId, $newRel, $target);
        } else {
            Index::upsert($dstDir->ownerId, $newRel, false, (int) filesize($target), (int) filemtime($target));
            if (!$sameOwner) Indexer::schedule($dstDir->ownerId, $newRel, $target);
        }
        Activity::log('move', ['owner_id' => $src->ownerId, 'path' => $src->rel, 'path2' => ($sameOwner ? '' : $dstDir->ownerId . ':') . $newRel, 'is_dir' => $isDir ? 1 : 0]);
        Notify::fileEvent('move', $dstDir->ownerId, $newRel, $isDir, $actor, ['body' => 'Moved from /' . $src->rel]);
        return self::item($dstDir->childCtx($name));
    }

    public static function copy(Ctx $src, Ctx $dstDir, ?array $actor, string $conflict = 'rename'): ?array
    {
        if (!file_exists($src->abs)) throw new NotFoundException('Source not found');
        if (!is_dir($dstDir->abs)) throw new NotFoundException('Destination folder not found');
        $src->need('download');
        $dstDir->need('create');
        $isDir = is_dir($src->abs);
        $name = $src->rel === '' || ($src->root !== 'home' && $src->shareRel() === '') ? $src->name() : Util::baseName($src->rel);
        $name = self::assertName($name);
        if (!$isDir) Users::assertFileAllowed($dstDir->owner, $name);
        if ($isDir && Util::pathWithin($dstDir->abs, $src->abs)) throw new RuntimeException('A folder cannot be copied into itself');
        $target = $dstDir->abs . '/' . $name;
        if (file_exists($target)) {
            if ($conflict === 'skip') return null;
            if ($conflict === 'overwrite' && !is_dir($target)) {
                self::delete($dstDir->childCtx($name), $actor, false);
            } elseif ($conflict !== 'overwrite') {
                $name = Util::uniqueName($dstDir->abs, $name);
                $target = $dstDir->abs . '/' . $name;
            }
        }
        $size = $isDir ? Util::treeSize($src->abs)['size'] : (int) filesize($src->abs);
        Users::assertQuota($dstDir->owner, $size);
        if (!Util::copyTree($src->abs, $target)) throw new RuntimeException('Copy failed');
        $newRel = Util::joinPath($dstDir->rel, $name);
        Meta::copyMeta($src->ownerId, $src->rel, $dstDir->ownerId, $newRel, $isDir);
        Users::addUsage($dstDir->ownerId, $size);
        if ($isDir) {
            Indexer::scheduleTree($dstDir->ownerId, $newRel, $target);
        } else {
            Index::upsert($dstDir->ownerId, $newRel, false, (int) filesize($target), (int) filemtime($target));
            Indexer::schedule($dstDir->ownerId, $newRel, $target);
        }
        Activity::log('copy', ['owner_id' => $src->ownerId, 'path' => $src->rel, 'path2' => ($src->ownerId === $dstDir->ownerId ? '' : $dstDir->ownerId . ':') . $newRel, 'is_dir' => $isDir ? 1 : 0]);
        return self::item($dstDir->childCtx($name));
    }

    // ------------------------------------------------------------------
    // Delete / trash
    // ------------------------------------------------------------------

    public static function delete(Ctx $ctx, ?array $actor, bool $permanent = false): void
    {
        $ctx->need('delete');
        if (!file_exists($ctx->abs)) throw new NotFoundException('Not found');
        if ($ctx->rel === '' || ($ctx->root !== 'home' && $ctx->shareRel() === '')) throw new RuntimeException('This item cannot be deleted here');
        $isDir = is_dir($ctx->abs);
        Meta::assertUnlocked($ctx->ownerId, $ctx->rel, $actor, $isDir);
        $size = $isDir ? Util::treeSize($ctx->abs)['size'] : (int) filesize($ctx->abs);
        $useTrash = !$permanent && Settings::bool('trash_enabled') && Users::can($ctx->owner, 'trash');
        if ($useTrash) {
            $trashDir = fr_data_dir('trash/' . $ctx->ownerId);
            if (!is_dir($trashDir)) @mkdir($trashDir, 0750, true);
            $stored = time() . '_' . Util::hex(6) . '_' . Util::baseName($ctx->rel);
            if (!@rename($ctx->abs, $trashDir . '/' . $stored)) {
                if (!Util::copyTree($ctx->abs, $trashDir . '/' . $stored)) throw new RuntimeException('Delete failed');
                Util::rmTree($ctx->abs);
            }
            DB::insert('trash', ['owner_id' => $ctx->ownerId, 'original_path' => $ctx->rel, 'name' => Util::baseName($ctx->rel), 'stored' => $stored, 'is_dir' => $isDir ? 1 : 0, 'size' => $size, 'deleted_by' => $actor['id'] ?? null, 'deleted_by_name' => self::actorName($actor, $ctx), 'deleted_at' => fr_now()]);
            Meta::deleteFor($ctx->ownerId, $ctx->rel, $isDir, true); // keep versions, meta, tags, comments for a restore
            DB::q('DELETE FROM {{shares}} WHERE owner_id = ? AND (path_hash = ?' . ($isDir ? ' OR path LIKE ?' : '') . ')', $isDir ? [$ctx->ownerId, sha1($ctx->rel), DB::escapeLike($ctx->rel) . '/%'] : [$ctx->ownerId, sha1($ctx->rel)]);
            DB::q('UPDATE {{weblinks}} SET active = 0 WHERE owner_id = ? AND (path_hash = ?' . ($isDir ? ' OR path LIKE ?' : '') . ')', $isDir ? [$ctx->ownerId, sha1($ctx->rel), DB::escapeLike($ctx->rel) . '/%'] : [$ctx->ownerId, sha1($ctx->rel)]);
        } else {
            if (!Util::rmTree($ctx->abs)) throw new RuntimeException('Delete failed');
            self::dropVersions($ctx->ownerId, $ctx->rel, $isDir);
            Meta::deleteFor($ctx->ownerId, $ctx->rel, $isDir);
        }
        Index::remove($ctx->ownerId, $ctx->rel, $isDir);
        Users::addUsage($ctx->ownerId, -$size);
        Activity::log('delete', ['owner_id' => $ctx->ownerId, 'path' => $ctx->rel, 'is_dir' => $isDir ? 1 : 0, 'client' => $ctx->root === 'link' ? 'link' : Activity::$client, 'details' => ['permanent' => !$useTrash, 'size' => $size]]);
        Notify::fileEvent('delete', $ctx->ownerId, $ctx->rel, $isDir, $actor, ['actor_name' => self::actorName($actor, $ctx)]);
    }

    public static function trashList(array $owner): array
    {
        $rows = DB::all('SELECT * FROM {{trash}} WHERE owner_id = ? ORDER BY deleted_at DESC', [(int) $owner['id']]);
        return array_map(static fn($t) => [
            'id' => (int) $t['id'], 'name' => $t['name'], 'original_path' => $t['original_path'], 'is_dir' => (bool) $t['is_dir'], 'size' => (int) $t['size'],
            'deleted_by' => $t['deleted_by_name'], 'deleted_at' => $t['deleted_at'], 'kind' => Util::kind($t['name'], (bool) $t['is_dir']), 'ext' => Util::ext($t['name']),
            'mime' => $t['is_dir'] ? 'inode/directory' : Util::mime($t['name']), 'path' => '~trash/' . $t['id'],
        ], $rows);
    }

    public static function trashRestore(array $owner, int $trashId, ?array $actor, ?string $toFolder = null): array
    {
        $t = DB::one('SELECT * FROM {{trash}} WHERE id = ? AND owner_id = ?', [$trashId, (int) $owner['id']]);
        if (!$t) throw new NotFoundException('Trash entry not found');
        $stored = fr_data_dir('trash/' . $owner['id'] . '/' . $t['stored']);
        if (!file_exists($stored)) { DB::delete('trash', 'id = ?', [$trashId]); throw new NotFoundException('The deleted item no longer exists on disk'); }
        $home = Users::homeDir($owner);
        $destRel = $toFolder !== null ? Util::joinPath(Util::normPath($toFolder), $t['name']) : (string) $t['original_path'];
        $destAbs = $home . '/' . $destRel;
        $parentAbs = dirname($destAbs);
        if (!is_dir($parentAbs)) @mkdir($parentAbs, 0750, true);
        if (file_exists($destAbs)) {
            $newName = Util::uniqueName($parentAbs, $t['name']);
            $destRel = Util::joinPath(Util::dirName($destRel), $newName);
            $destAbs = $home . '/' . $destRel;
        }
        Users::assertQuota($owner, (int) $t['size']);
        if (!@rename($stored, $destAbs)) throw new RuntimeException('Restore failed');
        if ($destRel !== $t['original_path']) {
            Meta::movePrefix((int) $owner['id'], (string) $t['original_path'], $destRel, (bool) $t['is_dir']);
        }
        DB::delete('trash', 'id = ?', [$trashId]);
        Users::addUsage((int) $owner['id'], (int) $t['size']);
        if ($t['is_dir']) Indexer::scheduleTree((int) $owner['id'], $destRel, $destAbs);
        else Index::upsert((int) $owner['id'], $destRel, false, (int) filesize($destAbs), (int) filemtime($destAbs));
        Activity::log('restore', ['owner_id' => (int) $owner['id'], 'path' => $destRel, 'is_dir' => (int) $t['is_dir']]);
        Notify::fileEvent('restore', (int) $owner['id'], $destRel, (bool) $t['is_dir'], $actor);
        return ['path' => '/' . $destRel, 'name' => Util::baseName($destRel)];
    }

    public static function trashPurge(array $owner, ?int $trashId, ?array $actor): int
    {
        $rows = $trashId ? DB::all('SELECT * FROM {{trash}} WHERE id = ? AND owner_id = ?', [$trashId, (int) $owner['id']]) : DB::all('SELECT * FROM {{trash}} WHERE owner_id = ?', [(int) $owner['id']]);
        $n = 0;
        foreach ($rows as $t) {
            Util::rmTree(fr_data_dir('trash/' . $owner['id'] . '/' . $t['stored']));
            self::dropVersions((int) $owner['id'], (string) $t['original_path'], (bool) $t['is_dir']);
            Meta::deleteFor((int) $owner['id'], (string) $t['original_path'], (bool) $t['is_dir']);
            DB::delete('trash', 'id = ?', [(int) $t['id']]);
            Activity::log('purge', ['owner_id' => (int) $owner['id'], 'path' => $t['original_path'], 'is_dir' => (int) $t['is_dir']]);
            $n++;
        }
        return $n;
    }

    /** Cron: empty trash entries older than the retention period. */
    public static function trashAutoPurge(): int
    {
        $days = Settings::int('trash_retention_days');
        if ($days <= 0) return 0;
        $n = 0;
        foreach (DB::all('SELECT * FROM {{trash}} WHERE deleted_at < ?', [gmdate('Y-m-d H:i:s', time() - $days * 86400)]) as $t) {
            Util::rmTree(fr_data_dir('trash/' . $t['owner_id'] . '/' . $t['stored']));
            self::dropVersions((int) $t['owner_id'], (string) $t['original_path'], (bool) $t['is_dir']);
            Meta::deleteFor((int) $t['owner_id'], (string) $t['original_path'], (bool) $t['is_dir']);
            DB::delete('trash', 'id = ?', [(int) $t['id']]);
            Activity::log('purge', ['owner_id' => (int) $t['owner_id'], 'path' => $t['original_path'], 'is_dir' => (int) $t['is_dir'], 'system' => true, 'details' => ['reason' => 'retention']]);
            $n++;
        }
        return $n;
    }

    // ------------------------------------------------------------------
    // Versions
    // ------------------------------------------------------------------

    public static function versionPath(int $ownerId, string $stored): string
    {
        return fr_data_dir('versions/' . $ownerId . '/' . $stored);
    }

    /** Snapshot the current content of a file before it is replaced. */
    public static function keepVersion(Ctx $ctx, ?array $actor, string $reason = 'overwrite'): ?int
    {
        if (!is_file($ctx->abs)) return null;
        if (!Settings::bool('file_history_enabled') || !Users::can($ctx->owner, 'file_history')) return null;
        $dir = fr_data_dir('versions/' . $ctx->ownerId);
        if (!is_dir($dir)) @mkdir($dir, 0750, true);
        $stored = sha1($ctx->rel) . '_' . time() . '_' . Util::hex(4);
        if (!@copy($ctx->abs, $dir . '/' . $stored)) return null;
        $id = DB::insert('versions', ['owner_id' => $ctx->ownerId, 'path_hash' => sha1($ctx->rel), 'path' => $ctx->rel, 'stored' => $stored, 'size' => (int) filesize($ctx->abs), 'mtime' => (int) filemtime($ctx->abs), 'created_by' => $actor['id'] ?? null, 'created_by_name' => self::actorName($actor, $ctx), 'reason' => $reason, 'created_at' => fr_now()]);
        // Prune beyond the limit.
        $keep = max(1, Settings::int('versions_keep'));
        $old = DB::all('SELECT id, stored FROM {{versions}} WHERE owner_id = ? AND path_hash = ? ORDER BY id DESC LIMIT 100 OFFSET ' . $keep, [$ctx->ownerId, sha1($ctx->rel)]);
        foreach ($old as $v) {
            @unlink(self::versionPath($ctx->ownerId, $v['stored']));
            DB::delete('versions', 'id = ?', [(int) $v['id']]);
        }
        return $id;
    }

    public static function versions(Ctx $ctx): array
    {
        $ctx->need('read');
        return array_map(static fn($v) => [
            'id' => (int) $v['id'], 'size' => (int) $v['size'], 'mtime' => (int) $v['mtime'], 'created_by' => $v['created_by_name'], 'reason' => $v['reason'], 'created_at' => $v['created_at'],
            'exists' => is_file(self::versionPath((int) $v['owner_id'], $v['stored'])),
        ], DB::all('SELECT * FROM {{versions}} WHERE owner_id = ? AND path_hash = ? ORDER BY id DESC', [$ctx->ownerId, sha1($ctx->rel)]));
    }

    public static function versionFile(Ctx $ctx, int $versionId): array
    {
        $ctx->need('read');
        $v = DB::one('SELECT * FROM {{versions}} WHERE id = ? AND owner_id = ? AND path_hash = ?', [$versionId, $ctx->ownerId, sha1($ctx->rel)]);
        if (!$v) throw new NotFoundException('Version not found');
        $abs = self::versionPath($ctx->ownerId, $v['stored']);
        if (!is_file($abs)) throw new NotFoundException('Version file missing');
        return ['abs' => $abs, 'row' => $v];
    }

    public static function restoreVersion(Ctx $ctx, int $versionId, ?array $actor): array
    {
        $ctx->need('edit');
        $v = self::versionFile($ctx, $versionId);
        $stream = fopen($v['abs'], 'rb');
        $item = self::putStream($ctx, $stream, (int) filesize($v['abs']), ['actor' => $actor, 'source' => 'restore', 'mtime' => (int) $v['row']['mtime']]);
        fclose($stream);
        Activity::log('version_restore', ['owner_id' => $ctx->ownerId, 'path' => $ctx->rel, 'details' => ['version_id' => $versionId]]);
        return $item;
    }

    public static function deleteVersion(Ctx $ctx, int $versionId): void
    {
        $ctx->need('delete');
        $v = DB::one('SELECT * FROM {{versions}} WHERE id = ? AND owner_id = ? AND path_hash = ?', [$versionId, $ctx->ownerId, sha1($ctx->rel)]);
        if (!$v) return;
        @unlink(self::versionPath($ctx->ownerId, $v['stored']));
        DB::delete('versions', 'id = ?', [$versionId]);
    }

    private static function dropVersions(int $ownerId, string $rel, bool $isDir): void
    {
        $rows = DB::all('SELECT id, stored FROM {{versions}} WHERE owner_id = ? AND (path_hash = ?' . ($isDir ? ' OR path LIKE ?' : '') . ')', $isDir ? [$ownerId, sha1($rel), DB::escapeLike($rel) . '/%'] : [$ownerId, sha1($rel)]);
        foreach ($rows as $v) {
            @unlink(self::versionPath($ownerId, $v['stored']));
            DB::delete('versions', 'id = ?', [(int) $v['id']]);
        }
    }

    // ------------------------------------------------------------------
    // Folder sync helper for uploads: which of these files are new or changed?
    // ------------------------------------------------------------------

    public static function checkSync(Ctx $dir, array $files): array
    {
        $dir->need('create');
        $out = [];
        foreach ($files as $f) {
            $rel = Util::normPath((string) ($f['path'] ?? ''));
            if ($rel === '') continue;
            $abs = $dir->abs . '/' . $rel;
            $needed = true;
            $reason = 'new';
            if (is_file($abs)) {
                $size = (int) filesize($abs);
                $mtime = (int) filemtime($abs);
                $same = $size === (int) ($f['size'] ?? -1) && abs($mtime - (int) ($f['mtime'] ?? 0)) <= 2;
                $needed = !$same;
                $reason = $same ? 'same' : 'changed';
            }
            $out[] = ['path' => $rel, 'upload' => $needed, 'reason' => $reason];
        }
        return $out;
    }
}
