<?php
declare(strict_types=1);

/**
 * User accounts, roles, permissions, quotas, groups and guest accounts.
 *
 * Roles:
 *  - superuser: full control of everything.
 *  - admin:     delegated administrator; manages only the accounts they created
 *               and cannot hand out more storage than they own.
 *  - user:      regular account.
 *  - guest:     created automatically when sharing with an e-mail address; can
 *               only use the web interface and only see what is shared.
 */
final class Users
{
    public const ROLES = ['superuser', 'admin', 'user', 'guest'];

    public const PERM_KEYS = [
        'upload', 'download', 'share_users', 'share_links', 'share_guests', 'file_history',
        'webdav', 'api', 'web', 'hide_users', 'comments', 'metadata', 'trash',
    ];

    private static array $cache = [];

    public static function find(int $id): ?array
    {
        if ($id <= 0) return null;
        if (!array_key_exists($id, self::$cache)) {
            self::$cache[$id] = DB::one('SELECT * FROM {{users}} WHERE id = ?', [$id]);
        }
        return self::$cache[$id];
    }

    public static function forget(int $id): void
    {
        unset(self::$cache[$id]);
    }

    public static function findByUsername(string $username): ?array
    {
        $username = trim($username);
        if ($username === '') return null;
        $row = DB::one('SELECT * FROM {{users}} WHERE username = ?', [$username]);
        if (!$row && str_contains($username, '@')) {
            $row = DB::one('SELECT * FROM {{users}} WHERE email = ? ORDER BY id LIMIT 1', [$username]);
        }
        return $row;
    }

    public static function findByEmail(string $email): ?array
    {
        $email = trim($email);
        return $email === '' ? null : DB::one('SELECT * FROM {{users}} WHERE email = ? ORDER BY id LIMIT 1', [$email]);
    }

    public static function isAdmin(?array $u): bool
    {
        return $u !== null && in_array($u['role'], ['superuser', 'admin'], true);
    }

    public static function isSuper(?array $u): bool
    {
        return $u !== null && $u['role'] === 'superuser';
    }

    public static function isGuest(?array $u): bool
    {
        return $u !== null && $u['role'] === 'guest';
    }

    /** Effective permission flags (defaults merged with the account's own). */
    public static function perms(array $u): array
    {
        $defaults = (array) Settings::get('default_perms');
        $own = Util::jsonDecode($u['perms'] ?? null, []);
        $p = array_merge($defaults, is_array($own) ? $own : []);
        if ($u['role'] === 'guest') {
            $p = array_merge($p, ['share_users' => false, 'share_links' => false, 'share_guests' => false, 'webdav' => false, 'api' => false, 'hide_users' => true, 'upload' => true]);
        }
        if ($u['role'] === 'superuser') {
            foreach (['upload', 'download', 'share_users', 'share_links', 'share_guests', 'file_history', 'webdav', 'api', 'web', 'comments', 'metadata', 'trash'] as $k) {
                $p[$k] = true;
            }
        }
        // Global switches win over per-user flags.
        if (!Settings::bool('links_enabled')) $p['share_links'] = false;
        if (!Settings::bool('guest_sharing_enabled')) $p['share_guests'] = false;
        if (!Settings::bool('file_history_enabled')) $p['file_history'] = false;
        if (!Settings::bool('webdav_enabled')) $p['webdav'] = false;
        if (!Settings::bool('api_enabled')) $p['api'] = false;
        if (!Settings::bool('trash_enabled')) $p['trash'] = false;
        if (Settings::bool('hide_users_from_everyone') && $u['role'] !== 'superuser') $p['hide_users'] = true;
        return $p;
    }

    public static function can(array $u, string $perm): bool
    {
        return Util::bool(self::perms($u)[$perm] ?? false);
    }

    public static function prefs(array $u): array
    {
        $p = Util::jsonDecode($u['prefs'] ?? null, []);
        return is_array($p) ? $p : [];
    }

    /** Absolute path of the account's home folder. */
    public static function homeDir(array $u): string
    {
        $home = (string) ($u['home_path'] ?? '');
        if ($home === '') {
            $home = 'users/' . $u['username'];
        }
        if (preg_match('#^([a-zA-Z]:)?/#', $home)) {
            return rtrim($home, '/');
        }
        return fr_data_dir($home);
    }

    public static function ensureHome(array $u): string
    {
        $dir = self::homeDir($u);
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        return $dir;
    }

    /** Data safe to expose about a user to that user. */
    public static function publicProfile(array $u): array
    {
        $perms = self::perms($u);
        return [
            'id' => (int) $u['id'],
            'username' => $u['username'],
            'email' => $u['email'],
            'name' => $u['name'] !== '' ? $u['name'] : $u['username'],
            'role' => $u['role'],
            'is_admin' => self::isAdmin($u),
            'is_super' => self::isSuper($u),
            'is_guest' => self::isGuest($u),
            'perms' => $perms,
            'prefs' => self::prefs($u),
            'language' => $u['language'] ?: Settings::get('default_language'),
            'quota_bytes' => (int) $u['quota_bytes'],
            'used_bytes' => (int) $u['used_bytes'],
            'traffic_quota_bytes' => (int) $u['traffic_quota_bytes'],
            'traffic_bytes' => self::trafficThisMonth((int) $u['id']),
            'max_upload_bytes' => self::maxUpload($u),
            'two_factor' => ['totp' => !empty($u['two_factor_secret']), 'email' => (bool) $u['two_factor_email'], 'required' => Settings::bool('two_factor_required')],
            'must_change_password' => (bool) $u['must_change_password'],
            'avatar' => $u['avatar'],
            'created_at' => $u['created_at'],
            'last_login_at' => $u['last_login_at'],
            'expires_at' => $u['expires_at'],
            'has_password' => !empty($u['password_hash']),
        ];
    }

    /** Minimal public representation used in share dialogs and lists. */
    public static function brief(?array $u): ?array
    {
        if (!$u) return null;
        return [
            'id' => (int) $u['id'],
            'username' => $u['username'],
            'name' => $u['name'] !== '' ? $u['name'] : $u['username'],
            'email' => $u['email'],
            'role' => $u['role'],
            'avatar' => $u['avatar'],
        ];
    }

    /** Full administrative view of an account. */
    public static function adminView(array $u): array
    {
        $groups = DB::all('SELECT g.id, g.name FROM {{group_members}} m JOIN {{groups}} g ON g.id = m.group_id WHERE m.user_id = ? ORDER BY g.name', [(int) $u['id']]);
        $creator = $u['created_by'] ? self::find((int) $u['created_by']) : null;
        return [
            'id' => (int) $u['id'],
            'username' => $u['username'],
            'email' => $u['email'],
            'name' => $u['name'],
            'role' => $u['role'],
            'created_by' => $u['created_by'] ? (int) $u['created_by'] : null,
            'created_by_name' => $creator ? ($creator['name'] ?: $creator['username']) : null,
            'home_path' => $u['home_path'],
            'quota_bytes' => (int) $u['quota_bytes'],
            'used_bytes' => (int) $u['used_bytes'],
            'traffic_quota_bytes' => (int) $u['traffic_quota_bytes'],
            'traffic_bytes' => self::trafficThisMonth((int) $u['id']),
            'max_upload_bytes' => (int) $u['max_upload_bytes'],
            'allowed_extensions' => $u['allowed_extensions'],
            'denied_extensions' => $u['denied_extensions'],
            'expires_at' => $u['expires_at'],
            'must_change_password' => (bool) $u['must_change_password'],
            'active' => (bool) $u['active'],
            'deactivated_reason' => $u['deactivated_reason'],
            'ip_rules' => $u['ip_rules'],
            'perms' => array_merge((array) Settings::get('default_perms'), (array) Util::jsonDecode($u['perms'] ?? null, [])),
            'perms_effective' => self::perms($u),
            'two_factor_totp' => !empty($u['two_factor_secret']),
            'two_factor_email' => (bool) $u['two_factor_email'],
            'passkeys' => (int) DB::val('SELECT COUNT(*) FROM {{passkeys}} WHERE user_id = ?', [(int) $u['id']]),
            'app_passwords' => (int) DB::val('SELECT COUNT(*) FROM {{app_passwords}} WHERE user_id = ?', [(int) $u['id']]),
            'language' => $u['language'],
            'groups' => $groups,
            'last_login_at' => $u['last_login_at'],
            'last_activity_at' => $u['last_activity_at'],
            'created_at' => $u['created_at'],
            'oidc_subject' => $u['oidc_subject'],
        ];
    }

    public static function maxUpload(array $u): int
    {
        $own = (int) $u['max_upload_bytes'];
        $global = (int) Settings::get('max_upload_size');
        $vals = array_filter([$own, $global], static fn($v) => $v > 0);
        return $vals ? min($vals) : 0;
    }

    /** Validate a username: letters, digits, dot, dash, underscore, @ (for guests). */
    public static function validUsername(string $username): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9][A-Za-z0-9._@+\-]{1,98}$/', $username);
    }

    /**
     * Create an account. $data keys mirror the users table; missing values
     * take the administrator-defined defaults.
     */
    public static function create(array $data, ?array $creator = null): array
    {
        $username = trim((string) ($data['username'] ?? ''));
        if (!self::validUsername($username)) {
            throw new RuntimeException('Invalid username');
        }
        if (self::findByUsername($username)) {
            throw new RuntimeException('Username already exists');
        }
        $role = $data['role'] ?? Settings::get('default_role');
        if (!in_array($role, self::ROLES, true)) $role = 'user';
        if ($creator && !self::isSuper($creator) && in_array($role, ['superuser', 'admin'], true)) {
            $role = 'user';
        }
        $home = (string) ($data['home_path'] ?? '');
        if ($home === '') {
            $home = str_replace('{username}', preg_replace('/[^A-Za-z0-9._@\-]/', '_', $username) ?? $username, (string) Settings::get('default_home'));
        }
        $perms = $data['perms'] ?? null;
        $row = [
            'username' => $username,
            'email' => isset($data['email']) && $data['email'] !== '' ? trim((string) $data['email']) : null,
            'password_hash' => !empty($data['password']) ? Auth::hashPassword((string) $data['password']) : null,
            'name' => trim((string) ($data['name'] ?? '')),
            'role' => $role,
            'created_by' => $creator ? (int) $creator['id'] : null,
            'home_path' => $home,
            'quota_bytes' => (int) ($data['quota_bytes'] ?? Settings::get('default_quota')),
            'traffic_quota_bytes' => (int) ($data['traffic_quota_bytes'] ?? Settings::get('default_traffic_quota')),
            'max_upload_bytes' => (int) ($data['max_upload_bytes'] ?? Settings::get('default_max_upload')),
            'allowed_extensions' => (string) ($data['allowed_extensions'] ?? ''),
            'denied_extensions' => (string) ($data['denied_extensions'] ?? ''),
            'expires_at' => !empty($data['expires_at']) ? $data['expires_at'] : null,
            'must_change_password' => !empty($data['must_change_password']) ? 1 : 0,
            'active' => array_key_exists('active', $data) ? (Util::bool($data['active']) ? 1 : 0) : 1,
            'ip_rules' => $data['ip_rules'] ?? null,
            'perms' => is_array($perms) ? Util::json($perms) : null,
            'prefs' => isset($data['prefs']) && is_array($data['prefs']) ? Util::json($data['prefs']) : null,
            'two_factor_email' => Settings::bool('two_factor_email_default') ? 1 : 0,
            'language' => $data['language'] ?? null,
            'oidc_subject' => $data['oidc_subject'] ?? null,
            'created_at' => fr_now(),
            'password_changed_at' => !empty($data['password']) ? fr_now() : null,
        ];
        if ($creator && !self::isSuper($creator)) {
            self::assertDelegatedQuota($creator, (int) $row['quota_bytes'], null);
        }
        $id = DB::insert('users', $row);
        $user = self::find($id);
        self::ensureHome($user);
        if (!empty($data['groups']) && is_array($data['groups'])) {
            self::setGroups($id, array_map('intval', $data['groups']));
        }
        return $user;
    }

    /** Update editable fields of an account. */
    public static function update(int $id, array $data, ?array $actor = null): array
    {
        $user = self::find($id);
        if (!$user) throw new RuntimeException('User not found');
        $row = [];
        $simple = ['email', 'name', 'home_path', 'allowed_extensions', 'denied_extensions', 'ip_rules', 'language', 'avatar', 'deactivated_reason'];
        foreach ($simple as $k) {
            if (array_key_exists($k, $data)) $row[$k] = $data[$k] === '' && in_array($k, ['email', 'language', 'avatar', 'ip_rules', 'deactivated_reason'], true) ? null : (is_null($data[$k]) ? null : (string) $data[$k]);
        }
        foreach (['quota_bytes', 'traffic_quota_bytes', 'max_upload_bytes'] as $k) {
            if (array_key_exists($k, $data)) $row[$k] = max(0, (int) $data[$k]);
        }
        foreach (['must_change_password', 'active', 'two_factor_email'] as $k) {
            if (array_key_exists($k, $data)) $row[$k] = Util::bool($data[$k]) ? 1 : 0;
        }
        if (array_key_exists('expires_at', $data)) {
            $row['expires_at'] = $data['expires_at'] ? substr((string) $data['expires_at'], 0, 19) : null;
        }
        if (array_key_exists('username', $data) && $data['username'] !== $user['username']) {
            $new = trim((string) $data['username']);
            if (!self::validUsername($new)) throw new RuntimeException('Invalid username');
            if (self::findByUsername($new)) throw new RuntimeException('Username already exists');
            $row['username'] = $new;
        }
        if (array_key_exists('role', $data) && in_array($data['role'], self::ROLES, true)) {
            if (!$actor || self::isSuper($actor)) {
                $row['role'] = $data['role'];
            }
        }
        if (array_key_exists('perms', $data) && is_array($data['perms'])) {
            $clean = [];
            foreach (self::PERM_KEYS as $k) {
                if (array_key_exists($k, $data['perms'])) $clean[$k] = Util::bool($data['perms'][$k]);
            }
            $row['perms'] = Util::json($clean);
        }
        if (array_key_exists('prefs', $data) && is_array($data['prefs'])) {
            $row['prefs'] = Util::json(array_merge(self::prefs($user), $data['prefs']));
        }
        if (!empty($data['password'])) {
            $row['password_hash'] = Auth::hashPassword((string) $data['password']);
            $row['password_changed_at'] = fr_now();
            if (!array_key_exists('must_change_password', $row)) $row['must_change_password'] = 0;
        }
        if (isset($row['active']) && $row['active'] === 1 && !$user['active']) {
            $row['deactivated_reason'] = null;
            $row['failed_2fa'] = 0;
        }
        if ($actor && !self::isSuper($actor) && isset($row['quota_bytes'])) {
            self::assertDelegatedQuota($actor, (int) $row['quota_bytes'], $id);
        }
        if ($row) {
            $row['updated_at'] = fr_now();
            DB::update('users', $row, 'id = ?', [$id]);
        }
        if (array_key_exists('groups', $data) && is_array($data['groups'])) {
            self::setGroups($id, array_map('intval', $data['groups']));
        }
        if (!empty($data['password']) && $actor && (int) $actor['id'] !== $id) {
            // An administrator reset: drop every session, app password and passkey trust.
            Auth::revokeAllSessions($id);
            DB::delete('app_passwords', 'user_id = ?', [$id]);
        }
        self::forget($id);
        $updated = self::find($id);
        if (isset($row['home_path'])) self::ensureHome($updated);
        return $updated;
    }

    /**
     * A delegated administrator cannot give away more storage than they own:
     * the sum of quotas of the accounts they created must stay within theirs.
     */
    private static function assertDelegatedQuota(array $admin, int $newQuota, ?int $excludeId): void
    {
        $own = (int) $admin['quota_bytes'];
        if ($own <= 0) {
            return; // unlimited administrator
        }
        if ($newQuota <= 0) {
            throw new RuntimeException('You cannot create unlimited accounts; your own storage is limited to ' . Util::humanSize($own));
        }
        $used = (int) DB::val('SELECT COALESCE(SUM(quota_bytes),0) FROM {{users}} WHERE created_by = ?' . ($excludeId ? ' AND id <> ?' : ''), $excludeId ? [(int) $admin['id'], $excludeId] : [(int) $admin['id']]);
        if ($used + $newQuota > $own) {
            throw new RuntimeException('Not enough storage left to allocate: ' . Util::humanSize(max(0, $own - $used)) . ' available');
        }
    }

    /** Delete an account, its shares, metadata and optionally its files. */
    public static function delete(int $id, bool $deleteFiles = false): void
    {
        $user = self::find($id);
        if (!$user) return;
        $home = self::homeDir($user);
        DB::tx(static function () use ($id): void {
            DB::delete('sessions', 'user_id = ?', [$id]);
            DB::delete('app_passwords', 'user_id = ?', [$id]);
            DB::delete('passkeys', 'user_id = ?', [$id]);
            DB::delete('tokens', 'user_id = ?', [$id]);
            DB::delete('group_members', 'user_id = ?', [$id]);
            DB::delete('shares', 'owner_id = ? OR (target_type = ? AND target_id = ?)', [$id, 'user', $id]);
            DB::delete('weblinks', 'owner_id = ?', [$id]);
            DB::delete('follows', 'user_id = ? OR owner_id = ?', [$id, $id]);
            DB::delete('stars', 'user_id = ? OR owner_id = ?', [$id, $id]);
            DB::delete('notifications', 'user_id = ?', [$id]);
            DB::delete('file_tags', 'owner_id = ?', [$id]);
            DB::delete('tags', 'user_id = ?', [$id]);
            DB::delete('comments', 'owner_id = ?', [$id]);
            DB::delete('versions', 'owner_id = ?', [$id]);
            DB::delete('trash', 'owner_id = ?', [$id]);
            DB::delete('file_meta', 'owner_id = ?', [$id]);
            DB::delete('file_text', 'owner_id = ?', [$id]);
            DB::delete('file_index', 'owner_id = ?', [$id]);
            DB::delete('dav_locks', 'owner_id = ? OR user_id = ?', [$id, $id]);
            DB::delete('traffic', 'user_id = ?', [$id]);
            DB::delete('users', 'id = ?', [$id]);
        });
        self::forget($id);
        if ($deleteFiles && is_dir($home) && str_starts_with(realpath($home) ?: '', realpath(fr_data_dir()) ?: '/nonexistent')) {
            Util::rmTree($home);
        }
        foreach (['versions', 'trash', 'thumbs'] as $sub) {
            $d = fr_data_dir($sub . '/' . $id);
            if (is_dir($d)) Util::rmTree($d);
        }
    }

    // ------------------------------------------------------------------
    // Lists / visibility
    // ------------------------------------------------------------------

    /** Users an administrator may manage. */
    public static function managedUsers(array $admin, array $filters = []): array
    {
        $where = ['1=1'];
        $params = [];
        if (!self::isSuper($admin)) {
            $where[] = '(created_by = ? OR id = ?)';
            $params[] = (int) $admin['id'];
            $params[] = (int) $admin['id'];
        }
        if (!empty($filters['q'])) {
            $like = '%' . DB::escapeLike((string) $filters['q']) . '%';
            $where[] = '(username LIKE ? OR email LIKE ? OR name LIKE ?)';
            array_push($params, $like, $like, $like);
        }
        if (!empty($filters['role'])) {
            $where[] = 'role = ?';
            $params[] = (string) $filters['role'];
        }
        if (isset($filters['active']) && $filters['active'] !== '') {
            $where[] = 'active = ?';
            $params[] = Util::bool($filters['active']) ? 1 : 0;
        }
        if (!empty($filters['group_id'])) {
            $where[] = 'id IN (SELECT user_id FROM {{group_members}} WHERE group_id = ?)';
            $params[] = (int) $filters['group_id'];
        }
        $sort = in_array($filters['sort'] ?? '', ['username', 'name', 'email', 'created_at', 'last_login_at', 'used_bytes', 'quota_bytes', 'role'], true) ? $filters['sort'] : 'username';
        $dir = ($filters['dir'] ?? 'asc') === 'desc' ? 'DESC' : 'ASC';
        $limit = max(1, min(500, (int) ($filters['limit'] ?? 100)));
        $offset = max(0, (int) ($filters['offset'] ?? 0));
        $total = (int) DB::val('SELECT COUNT(*) FROM {{users}} WHERE ' . implode(' AND ', $where), $params);
        $rows = DB::all('SELECT * FROM {{users}} WHERE ' . implode(' AND ', $where) . " ORDER BY `$sort` $dir, id ASC LIMIT $limit OFFSET $offset", $params);
        return ['total' => $total, 'users' => array_map([self::class, 'adminView'], $rows)];
    }

    public static function canManage(array $admin, array $target): bool
    {
        if (self::isSuper($admin)) return true;
        if (!self::isAdmin($admin)) return (int) $admin['id'] === (int) $target['id'];
        return (int) $target['created_by'] === (int) $admin['id'] || (int) $target['id'] === (int) $admin['id'];
    }

    /** Users visible to $viewer when sharing (respects hide_users). */
    public static function visibleUsers(array $viewer, string $q = '', int $limit = 50): array
    {
        $perms = self::perms($viewer);
        if (!empty($perms['hide_users']) && !self::isAdmin($viewer)) {
            return [];
        }
        $where = ["active = 1", "role <> 'guest'", 'id <> ?'];
        $params = [(int) $viewer['id']];
        if (self::isAdmin($viewer) && !self::isSuper($viewer)) {
            $where[] = '(created_by = ? OR id IN (SELECT user_id FROM {{group_members}} WHERE group_id IN (SELECT group_id FROM {{group_members}} WHERE user_id = ?)))';
            $params[] = (int) $viewer['id'];
            $params[] = (int) $viewer['id'];
        }
        if ($q !== '') {
            $like = '%' . DB::escapeLike($q) . '%';
            $where[] = '(username LIKE ? OR name LIKE ? OR email LIKE ?)';
            array_push($params, $like, $like, $like);
        }
        $rows = DB::all('SELECT id, username, name, email, role, avatar FROM {{users}} WHERE ' . implode(' AND ', $where) . ' ORDER BY name, username LIMIT ' . $limit, $params);
        return array_map([self::class, 'brief'], $rows);
    }

    // ------------------------------------------------------------------
    // Groups
    // ------------------------------------------------------------------

    public static function groups(): array
    {
        return DB::all('SELECT g.*, (SELECT COUNT(*) FROM {{group_members}} m WHERE m.group_id = g.id) AS members FROM {{groups}} g ORDER BY g.name');
    }

    public static function groupMembers(int $groupId): array
    {
        return array_map([self::class, 'brief'], DB::all('SELECT u.id, u.username, u.name, u.email, u.role, u.avatar FROM {{group_members}} m JOIN {{users}} u ON u.id = m.user_id WHERE m.group_id = ? ORDER BY u.name, u.username', [$groupId]));
    }

    public static function userGroupIds(int $userId): array
    {
        return array_map('intval', DB::col('SELECT group_id FROM {{group_members}} WHERE user_id = ?', [$userId]));
    }

    public static function setGroups(int $userId, array $groupIds): void
    {
        DB::delete('group_members', 'user_id = ?', [$userId]);
        foreach (array_unique($groupIds) as $gid) {
            if ($gid > 0) DB::insert('group_members', ['group_id' => $gid, 'user_id' => $userId, 'added_at' => fr_now()], true);
        }
    }

    public static function saveGroup(?int $id, string $name, string $description, ?array $memberIds, ?array $actor): array
    {
        $name = trim($name);
        if ($name === '') throw new RuntimeException('Group name is required');
        if ($id) {
            DB::update('groups', ['name' => $name, 'description' => $description], 'id = ?', [$id]);
        } else {
            $id = DB::insert('groups', ['name' => $name, 'description' => $description, 'created_by' => $actor['id'] ?? null, 'created_at' => fr_now()]);
        }
        if ($memberIds !== null) {
            DB::delete('group_members', 'group_id = ?', [$id]);
            foreach (array_unique(array_map('intval', $memberIds)) as $uid) {
                if ($uid > 0) DB::insert('group_members', ['group_id' => $id, 'user_id' => $uid, 'added_at' => fr_now()], true);
            }
        }
        return DB::one('SELECT * FROM {{groups}} WHERE id = ?', [$id]);
    }

    public static function deleteGroup(int $id): void
    {
        DB::delete('group_members', 'group_id = ?', [$id]);
        DB::delete('shares', 'target_type = ? AND target_id = ?', ['group', $id]);
        DB::delete('groups', 'id = ?', [$id]);
    }

    // ------------------------------------------------------------------
    // Quotas and traffic
    // ------------------------------------------------------------------

    /** Throws when adding $bytes would exceed the owner's storage quota. */
    public static function assertQuota(array $owner, int $bytes): void
    {
        $quota = (int) $owner['quota_bytes'];
        if ($quota <= 0 || $bytes <= 0) return;
        if ((int) $owner['used_bytes'] + $bytes > $quota) {
            throw new RuntimeException('Storage quota exceeded (' . Util::humanSize($quota) . ')');
        }
    }

    public static function addUsage(int $ownerId, int $delta): void
    {
        if ($delta === 0) return;
        DB::q('UPDATE {{users}} SET used_bytes = GREATEST(0, CAST(used_bytes AS SIGNED) + ?) WHERE id = ?', [$delta, $ownerId]);
        self::forget($ownerId);
    }

    /** Recompute real disk usage of an account's home folder. */
    public static function recalcUsage(array $u): int
    {
        $info = Util::treeSize(self::homeDir($u));
        DB::update('users', ['used_bytes' => $info['size'], 'used_calc_at' => fr_now()], 'id = ?', [(int) $u['id']]);
        self::forget((int) $u['id']);
        return $info['size'];
    }

    public static function trafficThisMonth(int $userId): int
    {
        return (int) DB::val('SELECT bytes_down FROM {{traffic}} WHERE user_id = ? AND month = ?', [$userId, gmdate('Y-m')]);
    }

    public static function addTraffic(int $userId, int $down, int $up = 0): void
    {
        if ($userId <= 0 || ($down <= 0 && $up <= 0)) return;
        DB::q('INSERT INTO {{traffic}} (user_id, month, bytes_down, bytes_up) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE bytes_down = bytes_down + VALUES(bytes_down), bytes_up = bytes_up + VALUES(bytes_up)', [$userId, gmdate('Y-m'), max(0, $down), max(0, $up)]);
    }

    public static function assertTraffic(array $owner, int $bytes): void
    {
        $quota = (int) $owner['traffic_quota_bytes'];
        if ($quota <= 0) return;
        if (self::trafficThisMonth((int) $owner['id']) + $bytes > $quota) {
            throw new RuntimeException('Monthly traffic limit reached');
        }
    }

    /** Validate a file name against the account's and the global extension rules. */
    public static function assertFileAllowed(array $owner, string $name): void
    {
        $ext = Util::ext($name);
        $allowed = trim((string) $owner['allowed_extensions']) ?: trim((string) Settings::get('allowed_extensions'));
        $denied = trim((string) $owner['denied_extensions']) . ',' . trim((string) Settings::get('denied_extensions'));
        $list = static fn(string $s): array => array_values(array_filter(array_map(static fn($x) => strtolower(trim($x, " .")), preg_split('/[\s,;]+/', $s) ?: [])));
        $deniedList = $list($denied);
        if ($deniedList && in_array($ext, $deniedList, true)) {
            throw new RuntimeException('Files of type .' . $ext . ' are not allowed');
        }
        $allowedList = $list($allowed);
        if ($allowedList && !in_array($ext, $allowedList, true)) {
            throw new RuntimeException('Only these file types are allowed: ' . implode(', ', $allowedList));
        }
    }

    // ------------------------------------------------------------------
    // Guests
    // ------------------------------------------------------------------

    /** Find or create the guest account for an e-mail address. Returns [user, created, password|null]. */
    public static function guestForEmail(string $email, array $creator): array
    {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Invalid e-mail address');
        }
        $existing = self::findByEmail($email) ?? self::findByUsername($email);
        if ($existing) {
            return [$existing, false, null];
        }
        $password = Util::randomPassword(12);
        $user = self::create([
            'username' => $email,
            'email' => $email,
            'password' => $password,
            'name' => '',
            'role' => 'guest',
            'quota_bytes' => 0,
            'home_path' => 'guests/' . preg_replace('/[^A-Za-z0-9._@\-]/', '_', $email),
            'perms' => ['share_users' => false, 'share_links' => false, 'share_guests' => false, 'webdav' => false, 'api' => false, 'hide_users' => true],
        ], $creator);
        return [$user, true, $password];
    }

    /** Remove guest accounts that no longer receive any share. */
    public static function cleanupGuests(): int
    {
        if (!Settings::bool('guest_cleanup')) return 0;
        $ids = DB::col("SELECT u.id FROM {{users}} u WHERE u.role = 'guest' AND NOT EXISTS (SELECT 1 FROM {{shares}} s WHERE s.target_type = 'user' AND s.target_id = u.id) AND NOT EXISTS (SELECT 1 FROM {{shares}} s JOIN {{group_members}} m ON m.group_id = s.target_id AND s.target_type = 'group' WHERE m.user_id = u.id)");
        foreach ($ids as $id) {
            $u = self::find((int) $id);
            Activity::log('user_delete', ['status' => 'ok', 'details' => ['username' => $u['username'] ?? '', 'reason' => 'guest without shares'], 'system' => true]);
            self::delete((int) $id, true);
        }
        return count($ids);
    }
}
