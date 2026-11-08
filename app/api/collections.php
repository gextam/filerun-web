<?php
declare(strict_types=1);

/**
 * /api/collections/* — virtual folders that gather files from anywhere
 * (your own folders or items shared with you) without moving them.
 */
final class CollectionsApi
{
    private static function own(int $id, array $u): array
    {
        $c = DB::one('SELECT * FROM {{collections}} WHERE id = ? AND user_id = ?', [$id, (int) $u['id']]);
        if (!$c) throw new NotFoundException('Collection not found');
        return $c;
    }

    public static function format(array $c): array
    {
        return ['id' => (int) $c['id'], 'name' => $c['name'], 'description' => $c['description'], 'color' => $c['color'], 'count' => (int) ($c['items'] ?? 0), 'created_at' => $c['created_at'], 'updated_at' => $c['updated_at']];
    }

    public static function all(int $userId): array
    {
        return array_map([self::class, 'format'], DB::all('SELECT c.*, (SELECT COUNT(*) FROM {{collection_items}} i WHERE i.collection_id = c.id) AS items FROM {{collections}} c WHERE c.user_id = ? ORDER BY c.sort_order, c.name', [$userId]));
    }

    public static function list(): void
    {
        Http::ok(self::all((int) Api::requireUser()['id']));
    }

    public static function create(): void
    {
        $u = Api::requireUser();
        $name = mb_substr(trim(Http::str('name')), 0, 150);
        if ($name === '') throw new RuntimeException('Name is required');
        $id = DB::insert('collections', ['user_id' => (int) $u['id'], 'name' => $name, 'description' => mb_substr(Http::str('description'), 0, 500), 'color' => Http::str('color') ?: null, 'created_at' => fr_now()]);
        $paths = Http::arr('paths');
        if ($paths) self::addPaths($id, $paths, $u);
        Activity::log('collection_create', ['details' => ['collection_id' => $id, 'name' => $name]]);
        Http::ok(self::format(DB::one('SELECT c.*, (SELECT COUNT(*) FROM {{collection_items}} i WHERE i.collection_id = c.id) AS items FROM {{collections}} c WHERE c.id = ?', [$id])));
    }

    public static function update(): void
    {
        $u = Api::requireUser();
        $c = self::own(Http::int('id'), $u);
        $row = [];
        if (Http::input('name') !== null) { $n = mb_substr(trim(Http::str('name')), 0, 150); if ($n === '') throw new RuntimeException('Name is required'); $row['name'] = $n; }
        if (Http::input('description') !== null) $row['description'] = mb_substr(Http::str('description'), 0, 500);
        if (Http::input('color') !== null) $row['color'] = Http::str('color') ?: null;
        if ($row) { $row['updated_at'] = fr_now(); DB::update('collections', $row, 'id = ?', [(int) $c['id']]); }
        Http::ok(self::all((int) $u['id']));
    }

    public static function delete(): void
    {
        $u = Api::requireUser();
        $c = self::own(Http::int('id'), $u);
        DB::delete('collection_items', 'collection_id = ?', [(int) $c['id']]);
        DB::delete('collections', 'id = ?', [(int) $c['id']]);
        Activity::log('collection_delete', ['details' => ['collection_id' => (int) $c['id'], 'name' => $c['name']]]);
        Http::ok(self::all((int) $u['id']));
    }

    private static function addPaths(int $collectionId, array $paths, array $u): int
    {
        $n = 0;
        foreach ($paths as $p) {
            try {
                $ctx = Paths::resolveExisting((string) $p, $u);
                if (!$ctx->can('read')) continue;
                if ($ctx->rel === '' && $ctx->root === 'home') continue;
                DB::upsert('collection_items', ['collection_id' => $collectionId, 'owner_id' => $ctx->ownerId, 'path_hash' => sha1($ctx->rel), 'path' => $ctx->rel, 'is_dir' => is_dir($ctx->abs) ? 1 : 0, 'added_at' => fr_now()], ['added_at']);
                $n++;
            } catch (Throwable) {
                // skip items that cannot be resolved
            }
        }
        DB::update('collections', ['updated_at' => fr_now()], 'id = ?', [$collectionId]);
        return $n;
    }

    public static function add(): void
    {
        $u = Api::requireUser();
        $c = self::own(Http::int('id'), $u);
        $n = self::addPaths((int) $c['id'], Api::paths(), $u);
        Activity::log('collection_add', ['details' => ['collection_id' => (int) $c['id'], 'items' => $n]]);
        Http::ok(['added' => $n]);
    }

    public static function remove(): void
    {
        $u = Api::requireUser();
        $c = self::own(Http::int('id'), $u);
        $n = 0;
        foreach (Api::paths() as $p) {
            try {
                $ctx = Paths::resolve((string) $p, $u);
                $n += DB::delete('collection_items', 'collection_id = ? AND owner_id = ? AND path_hash = ?', [(int) $c['id'], $ctx->ownerId, sha1($ctx->rel)]);
            } catch (Throwable) {
            }
        }
        Http::ok(['removed' => $n]);
    }

    /** Items of a collection, resolved back to virtual paths the user can open. */
    public static function items(): void
    {
        $u = Api::requireUser();
        $c = self::own(Http::int('id'), $u);
        $rows = DB::all('SELECT * FROM {{collection_items}} WHERE collection_id = ? ORDER BY added_at DESC', [(int) $c['id']]);
        $out = [];
        foreach ($rows as $r) {
            try {
                if ((int) $r['owner_id'] === (int) $u['id']) {
                    $vpath = '/' . $r['path'];
                } else {
                    $share = DB::one("SELECT id, path FROM {{shares}} WHERE owner_id = ? AND ((target_type = 'user' AND target_id = ?) OR (target_type = 'group' AND target_id IN (SELECT group_id FROM {{group_members}} WHERE user_id = ?))) AND (? = path OR ? LIKE CONCAT(path, '/%')) ORDER BY LENGTH(path) DESC LIMIT 1", [(int) $r['owner_id'], (int) $u['id'], (int) $u['id'], $r['path'], $r['path']]);
                    if (!$share) continue;
                    $sub = $r['path'] === $share['path'] ? '' : substr((string) $r['path'], strlen((string) $share['path']) + 1);
                    $vpath = '@' . $share['id'] . ($sub !== '' ? '/' . $sub : '');
                }
                $ctx = Paths::resolveExisting($vpath, $u);
                $item = Storage::item($ctx);
                $item['added_at'] = $r['added_at'];
                $out[] = $item;
            } catch (Throwable) {
                DB::delete('collection_items', 'collection_id = ? AND owner_id = ? AND path_hash = ?', [(int) $c['id'], (int) $r['owner_id'], $r['path_hash']]);
            }
        }
        Http::ok(['collection' => self::format($c + ['items' => count($out)]), 'items' => $out]);
    }

    /** Which collections contain an item (for the context menu check marks). */
    public static function of(): void
    {
        $u = Api::requireUser();
        $ctx = Api::ctx();
        Http::ok(array_map('intval', DB::col('SELECT ci.collection_id FROM {{collection_items}} ci JOIN {{collections}} c ON c.id = ci.collection_id WHERE c.user_id = ? AND ci.owner_id = ? AND ci.path_hash = ?', [(int) $u['id'], $ctx->ownerId, sha1($ctx->rel)])));
    }
}
