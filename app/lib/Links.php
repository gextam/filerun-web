<?php
declare(strict_types=1);

/**
 * Public web links: password, expiry date, download limit, terms to accept,
 * upload-only "file request" links, and the global switch that turns every
 * link off at once.
 */
final class Links
{
    public const LINK_ROLES = ['viewer', 'commenter', 'editor', 'contributor', 'content_manager', 'uploader'];

    public static function find(int $id): ?array
    {
        return DB::one('SELECT * FROM {{weblinks}} WHERE id = ?', [$id]);
    }

    public static function byHash(string $hash): ?array
    {
        if (!preg_match('/^[A-Za-z0-9_\-]{6,64}$/', $hash)) return null;
        return DB::one('SELECT * FROM {{weblinks}} WHERE hash = ?', [$hash]);
    }

    public static function format(array $l, bool $withStats = true): array
    {
        return [
            'id' => (int) $l['id'],
            'hash' => $l['hash'],
            'url' => self::url($l),
            'owner_id' => (int) $l['owner_id'],
            'path' => $l['path'],
            'name' => Util::baseName($l['path']) ?: '/',
            'is_dir' => (bool) $l['is_dir'],
            'title' => $l['title'],
            'message' => $l['message'],
            'role' => $l['role'],
            'role_label' => Paths::ROLE_LABELS[$l['role']] ?? $l['role'],
            'has_password' => !empty($l['password_hash']),
            'expires_at' => $l['expires_at'],
            'expired' => $l['expires_at'] ? strtotime((string) $l['expires_at']) < time() : false,
            'max_downloads' => (int) $l['max_downloads'],
            'downloads' => (int) $l['downloads'],
            'views' => (int) $l['views'],
            'terms' => $l['terms'],
            'notify' => (bool) $l['notify'],
            'allow_preview_only' => (bool) $l['allow_preview_only'],
            'active' => (bool) $l['active'],
            'created_by' => $l['created_by'] !== null ? (int) $l['created_by'] : null,
            'created_at' => $l['created_at'],
            'last_access_at' => $l['last_access_at'],
            'usable' => self::usableReason($l) === null,
            'usable_reason' => self::usableReason($l),
        ];
    }

    public static function url(array $l): string
    {
        return fr_public_url('/s/' . $l['hash']);
    }

    /** Why a link cannot be used right now (null = fine). */
    public static function usableReason(array $l): ?string
    {
        if (!Settings::bool('links_enabled')) return 'Public links are currently disabled on this server';
        if (!$l['active']) return 'This link has been disabled';
        if ($l['expires_at'] && strtotime((string) $l['expires_at']) < time()) return 'This link has expired';
        if ((int) $l['max_downloads'] > 0 && (int) $l['downloads'] >= (int) $l['max_downloads']) return 'The download limit of this link has been reached';
        $owner = Users::find((int) $l['owner_id']);
        if (!$owner || !$owner['active']) return 'This link is no longer available';
        if (!Users::can($owner, 'share_links')) return 'This link is no longer available';
        $home = Users::homeDir($owner);
        if (!file_exists($l['path'] === '' ? $home : $home . '/' . $l['path'])) return 'The shared item no longer exists';
        return null;
    }

    public static function create(Ctx $ctx, array $o, array $actor): array
    {
        $ctx->need('share');
        if (!Users::can($actor, 'share_links')) throw new PermissionException('Link sharing is not enabled for your account');
        if (!Settings::bool('links_enabled')) throw new PermissionException('Public links are disabled on this server');
        if (!file_exists($ctx->abs)) throw new NotFoundException('Item not found');
        if ($ctx->rel === '' && $ctx->root === 'home') throw new RuntimeException('The home folder itself cannot be shared');
        $isDir = is_dir($ctx->abs);
        $role = (string) ($o['role'] ?? Settings::get('link_default_role'));
        if (!in_array($role, self::LINK_ROLES, true)) $role = 'viewer';
        if (!$isDir && in_array($role, ['uploader', 'contributor', 'content_manager'], true)) $role = 'editor';
        if ($ctx->root !== 'home' && !in_array($role, Shares::grantableRoles($ctx), true)) throw new PermissionException('You cannot create a link with that role');
        $expires = !empty($o['expires_at']) ? substr((string) $o['expires_at'], 0, 19) : null;
        $maxDays = Settings::int('link_max_days');
        if ($maxDays > 0) {
            $limit = gmdate('Y-m-d H:i:s', time() + $maxDays * 86400);
            if (!$expires || $expires > $limit) $expires = $limit;
        }
        $password = (string) ($o['password'] ?? '');
        if (Settings::bool('link_require_password') && $password === '') throw new RuntimeException('A password is required for public links on this server');
        $row = [
            'hash' => Util::token(18),
            'owner_id' => $ctx->ownerId,
            'path' => $ctx->rel,
            'path_hash' => sha1($ctx->rel),
            'is_dir' => $isDir ? 1 : 0,
            'title' => isset($o['title']) && $o['title'] !== '' ? mb_substr((string) $o['title'], 0, 255) : null,
            'message' => isset($o['message']) && $o['message'] !== '' ? (string) $o['message'] : null,
            'role' => $role,
            'password_hash' => $password !== '' ? Auth::hashPassword($password) : null,
            'expires_at' => $expires,
            'max_downloads' => max(0, (int) ($o['max_downloads'] ?? 0)),
            'terms' => isset($o['terms']) && $o['terms'] !== '' ? (string) $o['terms'] : ((string) Settings::get('link_terms_default') ?: null),
            'notify' => !empty($o['notify']) ? 1 : 0,
            'allow_preview_only' => !empty($o['allow_preview_only']) ? 1 : 0,
            'active' => 1,
            'created_by' => (int) $actor['id'],
            'created_at' => fr_now(),
        ];
        $id = DB::insert('weblinks', $row);
        Activity::log('link_create', ['owner_id' => $ctx->ownerId, 'path' => $ctx->rel, 'is_dir' => $row['is_dir'], 'details' => ['role' => $role, 'expires_at' => $expires, 'password' => $password !== '', 'max_downloads' => $row['max_downloads']]]);
        return self::format(self::find($id));
    }

    public static function canManage(array $l, array $user): bool
    {
        return Users::isSuper($user) || (int) $l['owner_id'] === (int) $user['id'] || (int) ($l['created_by'] ?? 0) === (int) $user['id'];
    }

    public static function update(int $id, array $o, array $actor): array
    {
        $l = self::find($id);
        if (!$l || !self::canManage($l, $actor)) throw new PermissionException('Link not found');
        $row = [];
        if (isset($o['role']) && in_array($o['role'], self::LINK_ROLES, true)) $row['role'] = $o['role'];
        foreach (['title', 'message', 'terms'] as $k) {
            if (array_key_exists($k, $o)) $row[$k] = $o[$k] !== '' && $o[$k] !== null ? (string) $o[$k] : null;
        }
        if (array_key_exists('password', $o)) {
            $row['password_hash'] = $o['password'] !== '' && $o['password'] !== null ? Auth::hashPassword((string) $o['password']) : null;
        }
        if (array_key_exists('expires_at', $o)) $row['expires_at'] = $o['expires_at'] ? substr((string) $o['expires_at'], 0, 19) : null;
        if (array_key_exists('max_downloads', $o)) $row['max_downloads'] = max(0, (int) $o['max_downloads']);
        foreach (['notify', 'allow_preview_only', 'active'] as $k) {
            if (array_key_exists($k, $o)) $row[$k] = Util::bool($o[$k]) ? 1 : 0;
        }
        if (array_key_exists('reset_downloads', $o) && Util::bool($o['reset_downloads'])) $row['downloads'] = 0;
        if ($row) DB::update('weblinks', $row, 'id = ?', [$id]);
        Activity::log('link_update', ['owner_id' => (int) $l['owner_id'], 'path' => $l['path'], 'is_dir' => (int) $l['is_dir'], 'details' => array_diff_key($row, ['password_hash' => 1]) + (array_key_exists('password_hash', $row) ? ['password' => $row['password_hash'] !== null] : [])]);
        return self::format(self::find($id));
    }

    public static function delete(int $id, array $actor): void
    {
        $l = self::find($id);
        if (!$l) return;
        if (!self::canManage($l, $actor)) throw new PermissionException('Link not found');
        DB::delete('weblinks', 'id = ?', [$id]);
        Activity::log('link_delete', ['owner_id' => (int) $l['owner_id'], 'path' => $l['path'], 'is_dir' => (int) $l['is_dir']]);
    }

    public static function forItem(int $ownerId, string $path): array
    {
        return array_map([self::class, 'format'], DB::all('SELECT * FROM {{weblinks}} WHERE owner_id = ? AND path_hash = ? ORDER BY created_at', [$ownerId, sha1($path)]));
    }

    public static function mine(array $user): array
    {
        return array_map([self::class, 'format'], DB::all('SELECT * FROM {{weblinks}} WHERE owner_id = ? OR created_by = ? ORDER BY created_at DESC', [(int) $user['id'], (int) $user['id']]));
    }

    public static function all(array $filters = []): array
    {
        $where = ['1=1']; $params = [];
        if (!empty($filters['owner_id'])) { $where[] = 'l.owner_id = ?'; $params[] = (int) $filters['owner_id']; }
        if (!empty($filters['q'])) { $where[] = '(l.path LIKE ? OR l.title LIKE ?)'; $like = '%' . DB::escapeLike((string) $filters['q']) . '%'; $params[] = $like; $params[] = $like; }
        $rows = DB::all('SELECT l.*, u.username AS owner_username FROM {{weblinks}} l JOIN {{users}} u ON u.id = l.owner_id WHERE ' . implode(' AND ', $where) . ' ORDER BY l.created_at DESC LIMIT 1000', $params);
        return array_map(static fn($l) => self::format($l) + ['owner_username' => $l['owner_username']], $rows);
    }

    // ------------------------------------------------------------------
    // Visitor side
    // ------------------------------------------------------------------

    private static function cookieName(array $l): string
    {
        return 'fr_link_' . $l['id'];
    }

    private static function sign(array $l, string $what): string
    {
        return hash_hmac('sha256', $what . '|' . $l['id'] . '|' . $l['hash'] . '|' . ($l['password_hash'] ?? ''), (string) fr_config('secret', 'filerun-web'));
    }

    /** Has the visitor already satisfied the password / terms of this link? */
    public static function visitorState(array $l): array
    {
        $cookie = $_COOKIE[self::cookieName($l)] ?? '';
        $parts = explode('.', $cookie);
        $state = ['password_ok' => empty($l['password_hash']), 'terms_ok' => empty($l['terms'])];
        if (count($parts) === 2 && hash_equals(self::sign($l, $parts[0]), $parts[1])) {
            $flags = explode(',', $parts[0]);
            if (in_array('p', $flags, true)) $state['password_ok'] = true;
            if (in_array('t', $flags, true)) $state['terms_ok'] = true;
        }
        return $state;
    }

    private static function setVisitorState(array $l, array $state): void
    {
        $flags = [];
        if ($state['password_ok']) $flags[] = 'p';
        if ($state['terms_ok']) $flags[] = 't';
        $what = implode(',', $flags);
        Http::setCookie(self::cookieName($l), $what . '.' . self::sign($l, $what), 0, true, 'Lax');
    }

    public static function unlock(array $l, ?string $password, bool $acceptTerms): array
    {
        $state = self::visitorState($l);
        if (!$state['password_ok']) {
            $key = 'link:' . $l['id'] . ':' . Http::ip();
            $row = DB::one('SELECT * FROM {{login_attempts}} WHERE `key` = ?', [$key]);
            if ($row && (int) $row['attempts'] >= 10 && time() - strtotime((string) $row['last_attempt_at']) < 900) {
                throw new RuntimeException('Too many attempts. Try again later.');
            }
            if ($password === null || !Auth::verifyPassword($password, $l['password_hash'])) {
                DB::q('INSERT INTO {{login_attempts}} (`key`, attempts, last_attempt_at) VALUES (?, 1, ?) ON DUPLICATE KEY UPDATE attempts = attempts + 1, last_attempt_at = VALUES(last_attempt_at)', [$key, fr_now()]);
                Activity::log('link_access', ['status' => 'failed', 'reason' => 'wrong link password', 'owner_id' => (int) $l['owner_id'], 'path' => $l['path'], 'client' => 'link', 'details' => ['link_id' => (int) $l['id']]]);
                throw new RuntimeException('Wrong password');
            }
            DB::delete('login_attempts', '`key` = ?', [$key]);
            $state['password_ok'] = true;
        }
        if (!$state['terms_ok'] && $acceptTerms) $state['terms_ok'] = true;
        self::setVisitorState($l, $state);
        return $state;
    }

    /** Record a visit (once per unlock) and return the public description of the link. */
    public static function publicView(array $l, bool $countView = true): array
    {
        if ($countView) {
            DB::q('UPDATE {{weblinks}} SET views = views + 1, last_access_at = ? WHERE id = ?', [fr_now(), (int) $l['id']]);
        }
        $owner = Users::find((int) $l['owner_id']);
        $home = $owner ? Users::homeDir($owner) : '';
        $abs = $l['path'] === '' ? $home : $home . '/' . $l['path'];
        $name = Util::baseName($l['path']);
        return [
            'hash' => $l['hash'],
            'title' => $l['title'] ?: $name,
            'name' => $name,
            'message' => $l['message'],
            'is_dir' => (bool) $l['is_dir'],
            'role' => $l['role'],
            'perms' => Paths::permsForRole((string) $l['role']) + [],
            'allow_preview_only' => (bool) $l['allow_preview_only'],
            'terms' => $l['terms'],
            'has_password' => !empty($l['password_hash']),
            'owner_name' => $owner ? ($owner['name'] ?: $owner['username']) : '',
            'expires_at' => $l['expires_at'],
            'size' => is_file($abs) ? (int) filesize($abs) : 0,
            'mtime' => file_exists($abs) ? (int) filemtime($abs) : 0,
            'kind' => Util::kind($name, (bool) $l['is_dir']),
            'ext' => Util::ext($name),
            'mime' => $l['is_dir'] ? 'inode/directory' : Util::mime($name),
            'downloads_left' => (int) $l['max_downloads'] > 0 ? max(0, (int) $l['max_downloads'] - (int) $l['downloads']) : null,
        ];
    }

    public static function countDownload(array $l): void
    {
        DB::q('UPDATE {{weblinks}} SET downloads = downloads + 1, last_access_at = ? WHERE id = ?', [fr_now(), (int) $l['id']]);
    }

    /** Disable every link on the server (the "one switch"). */
    public static function disableAll(bool $disable): void
    {
        Settings::set('links_enabled', !$disable);
    }

    public static function purgeExpired(): int
    {
        return DB::delete('weblinks', 'expires_at IS NOT NULL AND expires_at < ?', [gmdate('Y-m-d H:i:s', time() - 30 * 86400)]);
    }
}
