<?php
declare(strict_types=1);

/**
 * The audit trail. Every action is recorded with the IP address and the
 * program that made it; failed actions are recorded with the reason; the user
 * name is stored as text so it survives renames and deletions. Things the
 * software does on its own are filed under the "System" account.
 */
final class Activity
{
    /** web | webdav | api | link | system | cron */
    public static string $client = 'web';

    public const ACTIONS = [
        'login', 'logout', 'password_change', 'password_reset_request', 'password_reset', 'two_factor_enable', 'two_factor_disable', 'passkey_add', 'passkey_remove', 'app_password_create', 'app_password_delete', 'session_revoke',
        'list', 'preview', 'download', 'upload', 'create_folder', 'create_file', 'edit', 'rename', 'move', 'copy', 'delete', 'restore', 'purge', 'version_restore', 'lock', 'unlock',
        'share_create', 'share_update', 'share_delete', 'link_create', 'link_update', 'link_delete', 'link_access', 'link_download', 'link_upload',
        'comment', 'tag', 'star', 'metadata', 'follow', 'unfollow', 'search', 'zip',
        'user_create', 'user_update', 'user_delete', 'user_deactivate', 'user_activate', 'group_create', 'group_update', 'group_delete', 'settings_update', 'email_change', 'role_change',
    ];

    /**
     * @param array $o status, reason, owner_id, path, path2, is_dir, details, user (array|null override), system (bool)
     */
    public static function log(string $action, array $o = []): void
    {
        try {
            $u = array_key_exists('user', $o) ? $o['user'] : (class_exists('Auth', false) ? Auth::user() : null);
            $system = !empty($o['system']);
            $path = isset($o['path']) ? (string) $o['path'] : null;
            DB::insert('activity', [
                'user_id' => $system ? null : ($u['id'] ?? null),
                'user_name' => $system ? 'System' : (string) ($u['username'] ?? ($o['details']['username'] ?? '')),
                'action' => substr($action, 0, 40),
                'status' => ($o['status'] ?? 'ok') === 'failed' ? 'failed' : 'ok',
                'reason' => isset($o['reason']) ? mb_substr((string) $o['reason'], 0, 255) : null,
                'owner_id' => $o['owner_id'] ?? null,
                'path' => $path,
                'path_hash' => $path !== null ? sha1($path) : null,
                'path2' => isset($o['path2']) ? (string) $o['path2'] : null,
                'is_dir' => !empty($o['is_dir']) ? 1 : 0,
                'client' => $system ? 'system' : (string) ($o['client'] ?? self::$client),
                'ip' => PHP_SAPI === 'cli' ? '' : Http::ip(),
                'user_agent' => PHP_SAPI === 'cli' ? 'cli' : Http::userAgent(),
                'details' => isset($o['details']) ? Util::json($o['details']) : null,
                'created_at' => fr_now(),
            ]);
        } catch (Throwable $e) {
            fr_log('activity log failed: ' . $e->getMessage());
        }
    }

    public static function fail(string $action, string $reason, array $o = []): void
    {
        self::log($action, ['status' => 'failed', 'reason' => $reason] + $o);
    }

    /** History of one file or folder (kept even after the file is deleted). */
    public static function forFile(int $ownerId, string $path, int $limit = 100): array
    {
        $rows = DB::all('SELECT * FROM {{activity}} WHERE owner_id = ? AND (path_hash = ? OR path2 = ?) ORDER BY id DESC LIMIT ' . max(1, min(500, $limit)), [$ownerId, sha1($path), $path]);
        return array_map([self::class, 'format'], $rows);
    }

    public static function format(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'user_id' => $r['user_id'] !== null ? (int) $r['user_id'] : null,
            'user_name' => $r['user_name'],
            'action' => $r['action'],
            'status' => $r['status'],
            'reason' => $r['reason'],
            'owner_id' => $r['owner_id'] !== null ? (int) $r['owner_id'] : null,
            'path' => $r['path'],
            'path2' => $r['path2'],
            'is_dir' => (bool) $r['is_dir'],
            'client' => $r['client'],
            'ip' => $r['ip'],
            'user_agent' => $r['user_agent'],
            'client_name' => Util::clientName($r['user_agent']),
            'details' => Util::jsonDecode($r['details'], null),
            'created_at' => $r['created_at'],
        ];
    }

    /** Administrative query with filters and paging. */
    public static function query(array $f): array
    {
        $where = ['1=1'];
        $params = [];
        if (!empty($f['user_id'])) { $where[] = 'user_id = ?'; $params[] = (int) $f['user_id']; }
        if (!empty($f['user'])) { $where[] = 'user_name LIKE ?'; $params[] = '%' . DB::escapeLike((string) $f['user']) . '%'; }
        if (!empty($f['action'])) {
            $actions = array_filter(array_map('trim', explode(',', (string) $f['action'])));
            if ($actions) {
                $where[] = 'action IN (' . implode(',', array_fill(0, count($actions), '?')) . ')';
                foreach ($actions as $a) $params[] = $a;
            }
        }
        if (!empty($f['status'])) { $where[] = 'status = ?'; $params[] = $f['status'] === 'failed' ? 'failed' : 'ok'; }
        if (!empty($f['client'])) { $where[] = 'client = ?'; $params[] = (string) $f['client']; }
        if (!empty($f['ip'])) { $where[] = 'ip LIKE ?'; $params[] = DB::escapeLike((string) $f['ip']) . '%'; }
        if (!empty($f['path'])) { $where[] = '(path LIKE ? OR path2 LIKE ?)'; $like = '%' . DB::escapeLike((string) $f['path']) . '%'; $params[] = $like; $params[] = $like; }
        if (!empty($f['owner_id'])) { $where[] = 'owner_id = ?'; $params[] = (int) $f['owner_id']; }
        if (!empty($f['from'])) { $where[] = 'created_at >= ?'; $params[] = substr((string) $f['from'], 0, 19); }
        if (!empty($f['to'])) { $where[] = 'created_at <= ?'; $params[] = substr((string) $f['to'], 0, 19) . (strlen((string) $f['to']) <= 10 ? ' 23:59:59' : ''); }
        if (!empty($f['managed_by'])) {
            $where[] = '(user_id IN (SELECT id FROM {{users}} WHERE created_by = ? OR id = ?) OR owner_id IN (SELECT id FROM {{users}} WHERE created_by = ? OR id = ?))';
            array_push($params, (int) $f['managed_by'], (int) $f['managed_by'], (int) $f['managed_by'], (int) $f['managed_by']);
        }
        $limit = max(1, min(500, (int) ($f['limit'] ?? 100)));
        $offset = max(0, (int) ($f['offset'] ?? 0));
        $w = implode(' AND ', $where);
        $total = (int) DB::val('SELECT COUNT(*) FROM {{activity}} WHERE ' . $w, $params);
        $rows = DB::all("SELECT * FROM {{activity}} WHERE $w ORDER BY id DESC LIMIT $limit OFFSET $offset", $params);
        return ['total' => $total, 'items' => array_map([self::class, 'format'], $rows)];
    }

    /** Recent activity relevant to a user (their own actions + actions on their files). */
    public static function forUser(int $userId, int $limit = 50): array
    {
        $rows = DB::all('SELECT * FROM {{activity}} WHERE (user_id = ? OR owner_id = ?) AND action NOT IN (\'list\',\'search\') ORDER BY id DESC LIMIT ' . max(1, min(200, $limit)), [$userId, $userId]);
        return array_map([self::class, 'format'], $rows);
    }

    public static function purgeOld(): int
    {
        $days = Settings::int('activity_retention_days');
        if ($days <= 0) return 0;
        return DB::delete('activity', 'created_at < ?', [gmdate('Y-m-d H:i:s', time() - $days * 86400)]);
    }

    /** Export as CSV string (admin). */
    public static function csv(array $filters): string
    {
        $filters['limit'] = 10000;
        $res = self::query($filters);
        $out = fopen('php://temp', 'w+');
        fputcsv($out, ['time', 'user', 'action', 'status', 'reason', 'client', 'ip', 'owner_id', 'path', 'path2', 'details']);
        foreach ($res['items'] as $r) {
            fputcsv($out, [$r['created_at'], $r['user_name'], $r['action'], $r['status'], $r['reason'], $r['client'], $r['ip'], $r['owner_id'], $r['path'], $r['path2'], $r['details'] ? Util::json($r['details']) : '']);
        }
        rewind($out);
        return (string) stream_get_contents($out);
    }
}
