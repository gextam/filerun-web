<?php
declare(strict_types=1);

/**
 * Everything that is attached to a file but is not the file itself: labels,
 * ratings, descriptions, custom metadata fields, EXIF, tags, stars, comments
 * and locks. Rows are keyed by (owner_id, sha1(path)) and follow the file when
 * it is renamed, moved or copied.
 */
final class Meta
{
    public const LABELS = ['red', 'orange', 'yellow', 'green', 'blue', 'purple', 'gray'];

    /** Tables that carry (owner_id, path, path_hash) columns and must follow renames. */
    private const PATH_TABLES = ['file_meta', 'file_text', 'file_tags', 'stars', 'comments', 'versions', 'follows', 'shares', 'weblinks', 'dav_locks', 'file_index', 'collection_items'];

    public static function get(int $ownerId, string $path): ?array
    {
        return DB::one('SELECT * FROM {{file_meta}} WHERE owner_id = ? AND path_hash = ?', [$ownerId, sha1($path)]);
    }

    public static function set(int $ownerId, string $path, array $fields, bool $isDir = false, ?int $userId = null): array
    {
        $row = ['owner_id' => $ownerId, 'path' => $path, 'path_hash' => sha1($path), 'is_dir' => $isDir ? 1 : 0, 'updated_at' => fr_now()];
        $existing = self::get($ownerId, $path);
        if (!$existing) {
            $row['created_by'] = $userId;
        }
        foreach ($fields as $k => $v) {
            if (in_array($k, ['label', 'rating', 'description', 'custom', 'exif', 'locked_by', 'locked_at', 'lock_token', 'downloads', 'views'], true)) {
                $row[$k] = is_array($v) ? Util::json($v) : $v;
            }
        }
        DB::upsert('file_meta', $row, array_diff(array_keys($row), ['owner_id', 'path', 'path_hash']));
        return self::get($ownerId, $path) ?? $row;
    }

    public static function setLabel(int $ownerId, string $path, ?string $label, bool $isDir, ?int $userId): void
    {
        if ($label !== null && !in_array($label, self::LABELS, true)) throw new RuntimeException('Unknown label');
        self::set($ownerId, $path, ['label' => $label], $isDir, $userId);
    }

    public static function setRating(int $ownerId, string $path, ?int $rating, bool $isDir, ?int $userId): void
    {
        if ($rating !== null && ($rating < 0 || $rating > 5)) throw new RuntimeException('Rating must be 0-5');
        self::set($ownerId, $path, ['rating' => $rating === 0 ? null : $rating], $isDir, $userId);
    }

    /** Validate and store custom metadata values against the configured fields. */
    public static function setCustom(int $ownerId, string $path, array $values, bool $isDir, ?int $userId): array
    {
        $fields = self::fieldsFor(Util::baseName($path), $isDir);
        $existing = self::get($ownerId, $path);
        $custom = $existing ? (array) Util::jsonDecode($existing['custom'], []) : [];
        foreach ($fields as $f) {
            if (!array_key_exists($f['key'], $values)) continue;
            $v = $values[$f['key']];
            if ($v === '' || $v === null) {
                unset($custom[$f['key']]);
                continue;
            }
            switch ($f['type']) {
                case 'number':
                    if (!is_numeric($v)) throw new RuntimeException($f['name'] . ' must be a number');
                    $v = $v + 0;
                    break;
                case 'date':
                    if (!preg_match('/^\d{4}-\d{2}-\d{2}/', (string) $v)) throw new RuntimeException($f['name'] . ' must be a date');
                    $v = substr((string) $v, 0, 10);
                    break;
                case 'boolean':
                    $v = Util::bool($v);
                    break;
                case 'select':
                    $opts = (array) Util::jsonDecode($f['options'], []);
                    if ($opts && !in_array((string) $v, array_map('strval', $opts), true)) throw new RuntimeException($f['name'] . ': not an allowed value');
                    break;
                case 'url':
                    if (!filter_var((string) $v, FILTER_VALIDATE_URL)) throw new RuntimeException($f['name'] . ' must be a URL');
                    break;
                default:
                    $v = mb_substr((string) $v, 0, $f['type'] === 'textarea' ? 20000 : 1000);
            }
            $custom[$f['key']] = $v;
        }
        self::set($ownerId, $path, ['custom' => $custom], $isDir, $userId);
        return $custom;
    }

    // ------------------------------------------------------------------
    // Custom metadata field definitions (administrator)
    // ------------------------------------------------------------------

    public static function fields(): array
    {
        return array_map(static fn($f) => $f + ['options_list' => (array) Util::jsonDecode($f['options'], [])], DB::all('SELECT * FROM {{metadata_fields}} ORDER BY sort_order, id'));
    }

    /** Fields that apply to a file name (by extension / kind) or to folders. */
    public static function fieldsFor(string $name, bool $isDir = false): array
    {
        $ext = Util::ext($name);
        $kind = Util::kind($name, $isDir);
        $out = [];
        foreach (self::fields() as $f) {
            $types = array_filter(array_map(static fn($t) => strtolower(trim($t, " .")), preg_split('/[\s,;]+/', (string) $f['file_types']) ?: []));
            if (!$types || in_array('*', $types, true) || in_array($isDir ? 'folder' : $ext, $types, true) || in_array($kind, $types, true)) {
                $out[] = $f;
            }
        }
        return $out;
    }

    public static function saveField(?int $id, array $d): array
    {
        $name = trim((string) ($d['name'] ?? ''));
        if ($name === '') throw new RuntimeException('Field name is required');
        $key = trim((string) ($d['key'] ?? ''));
        if ($key === '') $key = preg_replace('/[^a-z0-9]+/', '_', strtolower($name)) ?? 'field';
        $key = trim(preg_replace('/[^a-z0-9_]+/', '_', strtolower($key)) ?? 'field', '_') ?: 'field';
        $type = in_array($d['type'] ?? '', ['text', 'textarea', 'number', 'date', 'select', 'boolean', 'url'], true) ? $d['type'] : 'text';
        $options = isset($d['options']) ? (is_array($d['options']) ? $d['options'] : array_values(array_filter(array_map('trim', preg_split('/\r?\n|,/', (string) $d['options']) ?: []), 'strlen'))) : [];
        $row = ['name' => mb_substr($name, 0, 100), 'key' => substr($key, 0, 60), 'type' => $type, 'options' => Util::json($options), 'file_types' => mb_substr((string) ($d['file_types'] ?? ''), 0, 500), 'required' => !empty($d['required']) ? 1 : 0, 'show_column' => !empty($d['show_column']) ? 1 : 0, 'searchable' => array_key_exists('searchable', $d) ? (Util::bool($d['searchable']) ? 1 : 0) : 1, 'sort_order' => (int) ($d['sort_order'] ?? 0)];
        if ($id) {
            DB::update('metadata_fields', $row, 'id = ?', [$id]);
        } else {
            if (DB::one('SELECT id FROM {{metadata_fields}} WHERE `key` = ?', [$row['key']])) throw new RuntimeException('A field with this key exists already');
            $row['created_at'] = fr_now();
            $id = DB::insert('metadata_fields', $row);
        }
        return DB::one('SELECT * FROM {{metadata_fields}} WHERE id = ?', [$id]);
    }

    public static function deleteField(int $id): void
    {
        DB::delete('metadata_fields', 'id = ?', [$id]);
    }

    // ------------------------------------------------------------------
    // Tags (namespace = file owner)
    // ------------------------------------------------------------------

    public static function tagsOf(int $ownerId): array
    {
        return DB::all('SELECT t.*, (SELECT COUNT(*) FROM {{file_tags}} ft WHERE ft.tag_id = t.id) AS files FROM {{tags}} t WHERE t.user_id = ? ORDER BY t.name', [$ownerId]);
    }

    public static function tagId(int $ownerId, string $name, ?string $color = null): int
    {
        $name = mb_substr(trim($name), 0, 100);
        if ($name === '') throw new RuntimeException('Empty tag');
        $row = DB::one('SELECT id FROM {{tags}} WHERE user_id = ? AND name = ?', [$ownerId, $name]);
        if ($row) return (int) $row['id'];
        return DB::insert('tags', ['user_id' => $ownerId, 'name' => $name, 'color' => $color, 'created_at' => fr_now()]);
    }

    public static function fileTags(int $ownerId, string $path): array
    {
        return DB::all('SELECT t.id, t.name, t.color FROM {{file_tags}} ft JOIN {{tags}} t ON t.id = ft.tag_id WHERE ft.owner_id = ? AND ft.path_hash = ? ORDER BY t.name', [$ownerId, sha1($path)]);
    }

    public static function setTags(int $ownerId, string $path, array $names): array
    {
        $names = array_values(array_unique(array_filter(array_map(static fn($n) => mb_substr(trim((string) $n), 0, 100), $names), 'strlen')));
        DB::delete('file_tags', 'owner_id = ? AND path_hash = ?', [$ownerId, sha1($path)]);
        foreach ($names as $n) {
            DB::insert('file_tags', ['tag_id' => self::tagId($ownerId, $n), 'owner_id' => $ownerId, 'path_hash' => sha1($path), 'path' => $path, 'created_at' => fr_now()], true);
        }
        // Drop tags nobody uses any more.
        DB::q('DELETE FROM {{tags}} WHERE user_id = ? AND id NOT IN (SELECT DISTINCT tag_id FROM {{file_tags}})', [$ownerId]);
        return self::fileTags($ownerId, $path);
    }

    public static function renameTag(int $ownerId, int $tagId, string $name, ?string $color): void
    {
        DB::update('tags', ['name' => mb_substr(trim($name), 0, 100), 'color' => $color], 'id = ? AND user_id = ?', [$tagId, $ownerId]);
    }

    public static function deleteTag(int $ownerId, int $tagId): void
    {
        DB::delete('file_tags', 'tag_id = ?', [$tagId]);
        DB::delete('tags', 'id = ? AND user_id = ?', [$tagId, $ownerId]);
    }

    public static function pathsWithTag(int $ownerId, int $tagId): array
    {
        return DB::all('SELECT path FROM {{file_tags}} WHERE owner_id = ? AND tag_id = ? ORDER BY path', [$ownerId, $tagId]);
    }

    // ------------------------------------------------------------------
    // Stars (per user)
    // ------------------------------------------------------------------

    public static function star(int $userId, int $ownerId, string $path, bool $isDir, bool $on): void
    {
        if ($on) {
            DB::upsert('stars', ['user_id' => $userId, 'owner_id' => $ownerId, 'path_hash' => sha1($path), 'path' => $path, 'is_dir' => $isDir ? 1 : 0, 'created_at' => fr_now()], ['is_dir']);
        } else {
            DB::delete('stars', 'user_id = ? AND owner_id = ? AND path_hash = ?', [$userId, $ownerId, sha1($path)]);
        }
    }

    public static function starred(int $userId): array
    {
        return DB::all('SELECT * FROM {{stars}} WHERE user_id = ? ORDER BY created_at DESC', [$userId]);
    }

    // ------------------------------------------------------------------
    // Comments
    // ------------------------------------------------------------------

    public static function comments(int $ownerId, string $path): array
    {
        return array_map(static fn($c) => [
            'id' => (int) $c['id'], 'user_id' => $c['user_id'] !== null ? (int) $c['user_id'] : null, 'user_name' => $c['user_name'],
            'body' => $c['body'], 'created_at' => $c['created_at'], 'updated_at' => $c['updated_at'],
        ], DB::all('SELECT * FROM {{comments}} WHERE owner_id = ? AND path_hash = ? ORDER BY id', [$ownerId, sha1($path)]));
    }

    public static function addComment(int $ownerId, string $path, ?array $user, string $body, ?string $guestName = null): array
    {
        $body = trim($body);
        if ($body === '') throw new RuntimeException('Empty comment');
        $id = DB::insert('comments', ['owner_id' => $ownerId, 'path_hash' => sha1($path), 'path' => $path, 'user_id' => $user['id'] ?? null, 'user_name' => $user ? ($user['name'] ?: $user['username']) : ($guestName ?: 'Visitor'), 'body' => mb_substr($body, 0, 10000), 'created_at' => fr_now()]);
        $c = DB::one('SELECT * FROM {{comments}} WHERE id = ?', [$id]);
        return ['id' => (int) $c['id'], 'user_id' => $c['user_id'] !== null ? (int) $c['user_id'] : null, 'user_name' => $c['user_name'], 'body' => $c['body'], 'created_at' => $c['created_at'], 'updated_at' => null];
    }

    public static function deleteComment(int $id, array $user, int $ownerId): void
    {
        $c = DB::one('SELECT * FROM {{comments}} WHERE id = ?', [$id]);
        if (!$c) return;
        if ((int) $c['user_id'] !== (int) $user['id'] && (int) $c['owner_id'] !== (int) $user['id'] && !Users::isSuper($user)) {
            throw new PermissionException('You cannot delete this comment');
        }
        DB::delete('comments', 'id = ?', [$id]);
    }

    public static function commentCounts(int $ownerId, array $hashes): array
    {
        if (!$hashes) return [];
        $out = [];
        foreach (array_chunk($hashes, 500) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            foreach (DB::all("SELECT path_hash, COUNT(*) AS n FROM {{comments}} WHERE owner_id = ? AND path_hash IN ($in) GROUP BY path_hash", array_merge([$ownerId], $chunk)) as $r) $out[$r['path_hash']] = (int) $r['n'];
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // Locks
    // ------------------------------------------------------------------

    public static function lockInfo(int $ownerId, string $path): ?array
    {
        $m = self::get($ownerId, $path);
        if (!$m || !$m['locked_by']) return null;
        $u = Users::find((int) $m['locked_by']);
        return ['user_id' => (int) $m['locked_by'], 'user_name' => $u ? ($u['name'] ?: $u['username']) : 'Unknown', 'locked_at' => $m['locked_at']];
    }

    public static function lock(Ctx $ctx, array $user): void
    {
        $ctx->need('lock');
        $info = self::lockInfo($ctx->ownerId, $ctx->rel);
        if ($info && $info['user_id'] !== (int) $user['id']) throw new PermissionException('Already locked by ' . $info['user_name']);
        self::set($ctx->ownerId, $ctx->rel, ['locked_by' => (int) $user['id'], 'locked_at' => fr_now(), 'lock_token' => Util::uuid()], is_dir($ctx->abs), (int) $user['id']);
        Activity::log('lock', ['owner_id' => $ctx->ownerId, 'path' => $ctx->rel]);
    }

    public static function unlock(Ctx $ctx, array $user, bool $force = false): void
    {
        $info = self::lockInfo($ctx->ownerId, $ctx->rel);
        if (!$info) return;
        if ($info['user_id'] !== (int) $user['id'] && !$force && $ctx->ownerId !== (int) $user['id'] && !Users::isSuper($user)) {
            throw new PermissionException('Locked by ' . $info['user_name']);
        }
        self::set($ctx->ownerId, $ctx->rel, ['locked_by' => null, 'locked_at' => null, 'lock_token' => null]);
        DB::delete('dav_locks', 'owner_id = ? AND path_hash = ?', [$ctx->ownerId, sha1($ctx->rel)]);
        Activity::log('unlock', ['owner_id' => $ctx->ownerId, 'path' => $ctx->rel]);
    }

    /** Throws when the item (or, for folders, anything inside) is locked by somebody else. */
    public static function assertUnlocked(int $ownerId, string $path, ?array $user, bool $recursive = false): void
    {
        $uid = (int) ($user['id'] ?? 0);
        $rows = $recursive
            ? DB::all('SELECT path, locked_by FROM {{file_meta}} WHERE owner_id = ? AND locked_by IS NOT NULL AND (path_hash = ? OR path LIKE ?)', [$ownerId, sha1($path), DB::escapeLike($path) . '/%'])
            : DB::all('SELECT path, locked_by FROM {{file_meta}} WHERE owner_id = ? AND locked_by IS NOT NULL AND path_hash = ?', [$ownerId, sha1($path)]);
        foreach ($rows as $r) {
            if ((int) $r['locked_by'] !== $uid) {
                $u = Users::find((int) $r['locked_by']);
                throw new PermissionException('"' . Util::baseName($r['path']) . '" is locked by ' . ($u ? ($u['name'] ?: $u['username']) : 'another user'));
            }
        }
        // WebDAV locks created by clients.
        $dav = DB::all('SELECT user_id, path FROM {{dav_locks}} WHERE owner_id = ? AND expires_at > ? AND (path_hash = ?' . ($recursive ? ' OR path LIKE ?' : '') . ')', $recursive ? [$ownerId, fr_now(), sha1($path), DB::escapeLike($path) . '/%'] : [$ownerId, fr_now(), sha1($path)]);
        foreach ($dav as $r) {
            if ((int) $r['user_id'] !== $uid) {
                $u = Users::find((int) $r['user_id']);
                throw new PermissionException('"' . Util::baseName($r['path']) . '" is locked by ' . ($u ? ($u['name'] ?: $u['username']) : 'a WebDAV client'));
            }
        }
    }

    // ------------------------------------------------------------------
    // Bulk data for listings and bookkeeping on rename / move / copy / delete
    // ------------------------------------------------------------------

    /**
     * Metadata for many paths at once: returns path_hash => [label, rating, description,
     * custom, tags[], starred, comments, locked_by, locked_by_name, exif(bool)].
     */
    public static function bulk(int $ownerId, array $paths, ?int $viewerId): array
    {
        $hashes = array_map('sha1', $paths);
        $out = [];
        foreach (array_chunk($hashes, 500) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            $p = array_merge([$ownerId], $chunk);
            foreach (DB::all("SELECT path_hash, label, rating, description, custom, locked_by, locked_at, exif IS NOT NULL AS has_exif, downloads, views FROM {{file_meta}} WHERE owner_id = ? AND path_hash IN ($in)", $p) as $r) {
                $lockedName = null;
                if ($r['locked_by']) { $lu = Users::find((int) $r['locked_by']); $lockedName = $lu ? ($lu['name'] ?: $lu['username']) : 'Unknown'; }
                $out[$r['path_hash']] = ['label' => $r['label'], 'rating' => $r['rating'] !== null ? (int) $r['rating'] : null, 'description' => $r['description'], 'custom' => (object) (Util::jsonDecode($r['custom'], []) ?: []), 'locked_by' => $r['locked_by'] !== null ? (int) $r['locked_by'] : null, 'locked_by_name' => $lockedName, 'locked_at' => $r['locked_at'], 'has_exif' => (bool) $r['has_exif'], 'downloads' => (int) $r['downloads'], 'views' => (int) $r['views']];
            }
            foreach (DB::all("SELECT ft.path_hash, t.id, t.name, t.color FROM {{file_tags}} ft JOIN {{tags}} t ON t.id = ft.tag_id WHERE ft.owner_id = ? AND ft.path_hash IN ($in) ORDER BY t.name", $p) as $r) {
                $out[$r['path_hash']]['tags'][] = ['id' => (int) $r['id'], 'name' => $r['name'], 'color' => $r['color']];
            }
            if ($viewerId) {
                foreach (DB::col("SELECT path_hash FROM {{stars}} WHERE user_id = ? AND owner_id = ? AND path_hash IN ($in)", array_merge([$viewerId, $ownerId], $chunk)) as $h) {
                    $out[$h]['starred'] = true;
                }
            }
            foreach (DB::all("SELECT path_hash, COUNT(*) AS n FROM {{comments}} WHERE owner_id = ? AND path_hash IN ($in) GROUP BY path_hash", $p) as $r) {
                $out[$r['path_hash']]['comments'] = (int) $r['n'];
            }
        }
        return $out;
    }

    /** Move every metadata row of $from (and children when it is a folder) to $to. */
    public static function movePrefix(int $ownerId, string $from, string $to, bool $isDir): void
    {
        foreach (self::PATH_TABLES as $table) {
            $rows = DB::all('SELECT path FROM ' . DB::table($table) . ' WHERE owner_id = ? AND (path_hash = ?' . ($isDir ? ' OR path LIKE ?' : '') . ')', $isDir ? [$ownerId, sha1($from), DB::escapeLike($from) . '/%'] : [$ownerId, sha1($from)]);
            foreach ($rows as $r) {
                $old = (string) $r['path'];
                $new = $old === $from ? $to : $to . substr($old, strlen($from));
                $set = ['path' => $new, 'path_hash' => sha1($new)];
                if ($table === 'file_index') {
                    $set['name'] = Util::baseName($new);
                    $set['parent_hash'] = sha1(Util::dirName($new));
                }
                DB::update($table, $set, 'owner_id = ? AND path_hash = ?', [$ownerId, sha1($old)]);
            }
        }
        Thumbs::forget($ownerId, $from, $isDir);
    }

    /** Copy tags, labels, custom metadata and descriptions from one item to another (same owner or across owners). */
    public static function copyMeta(int $fromOwner, string $from, int $toOwner, string $to, bool $isDir): void
    {
        $rows = DB::all('SELECT * FROM {{file_meta}} WHERE owner_id = ? AND (path_hash = ?' . ($isDir ? ' OR path LIKE ?' : '') . ')', $isDir ? [$fromOwner, sha1($from), DB::escapeLike($from) . '/%'] : [$fromOwner, sha1($from)]);
        foreach ($rows as $m) {
            $new = $m['path'] === $from ? $to : $to . substr($m['path'], strlen($from));
            self::set($toOwner, $new, ['label' => $m['label'], 'rating' => $m['rating'], 'description' => $m['description'], 'custom' => $m['custom'] ? Util::jsonDecode($m['custom'], []) : null, 'exif' => $m['exif'] ? Util::jsonDecode($m['exif'], []) : null], (bool) $m['is_dir'], $m['created_by'] !== null ? (int) $m['created_by'] : null);
        }
        $tagRows = DB::all('SELECT ft.path, t.name, t.color FROM {{file_tags}} ft JOIN {{tags}} t ON t.id = ft.tag_id WHERE ft.owner_id = ? AND (ft.path_hash = ?' . ($isDir ? ' OR ft.path LIKE ?' : '') . ')', $isDir ? [$fromOwner, sha1($from), DB::escapeLike($from) . '/%'] : [$fromOwner, sha1($from)]);
        $byPath = [];
        foreach ($tagRows as $r) {
            $new = $r['path'] === $from ? $to : $to . substr($r['path'], strlen($from));
            $byPath[$new][] = $r['name'];
        }
        foreach ($byPath as $p => $names) self::setTags($toOwner, $p, $names);
    }

    /** Remove every metadata row of an item (and children). Activity history is kept. */
    public static function deleteFor(int $ownerId, string $path, bool $isDir, bool $keepVersions = false): void
    {
        foreach (self::PATH_TABLES as $table) {
            if ($keepVersions && in_array($table, ['versions', 'file_meta', 'file_tags', 'comments'], true)) continue;
            DB::q('DELETE FROM ' . DB::table($table) . ' WHERE owner_id = ? AND (path_hash = ?' . ($isDir ? ' OR path LIKE ?' : '') . ')', $isDir ? [$ownerId, sha1($path), DB::escapeLike($path) . '/%'] : [$ownerId, sha1($path)]);
        }
        Thumbs::forget($ownerId, $path, $isDir);
    }

    public static function bumpCounter(int $ownerId, string $path, string $counter): void
    {
        if (!in_array($counter, ['downloads', 'views'], true)) return;
        DB::q("INSERT INTO {{file_meta}} (owner_id, path, path_hash, is_dir, $counter, updated_at) VALUES (?,?,?,0,1,?) ON DUPLICATE KEY UPDATE $counter = $counter + 1", [$ownerId, $path, sha1($path), fr_now()]);
    }
}
