<?php
declare(strict_types=1);

/**
 * In-app notifications, e-mail delivery of them and "follow this folder".
 */
final class Notify
{
    public const TYPES = ['share', 'link', 'upload', 'download', 'comment', 'delete', 'edit', 'rename', 'system', 'mention', 'request'];

    /** Record a notification and (if the user wants it) e-mail it. */
    public static function push(int $userId, string $type, string $title, string $body = '', array $data = [], bool $email = true): void
    {
        $u = Users::find($userId);
        if (!$u || !$u['active']) return;
        // Coalesce bursts (e.g. a folder upload of hundreds of files) into one entry.
        if (!empty($data['coalesce_key'])) {
            $prev = DB::one('SELECT * FROM {{notifications}} WHERE user_id = ? AND type = ? AND read_at IS NULL AND created_at > ? ORDER BY id DESC LIMIT 1', [$userId, $type, gmdate('Y-m-d H:i:s', time() - 600)]);
            if ($prev) {
                $pd = Util::jsonDecode($prev['data'], []);
                if (($pd['coalesce_key'] ?? null) === $data['coalesce_key']) {
                    $pd['count'] = (int) ($pd['count'] ?? 1) + 1;
                    DB::update('notifications', ['body' => $body . ' (' . $pd['count'] . ' items so far)', 'data' => Util::json($pd), 'created_at' => fr_now()], 'id = ?', [(int) $prev['id']]);
                    return;
                }
            }
        }
        $id = DB::insert('notifications', ['user_id' => $userId, 'type' => $type, 'title' => mb_substr($title, 0, 255), 'body' => $body, 'data' => Util::json($data), 'created_at' => fr_now()]);
        $prefs = Users::prefs($u);
        $wantsEmail = $prefs['email_notifications'] ?? true;
        $perType = $prefs['email_notify_types'] ?? null;
        if (is_array($perType) && array_key_exists($type, $perType)) {
            $wantsEmail = Util::bool($perType[$type]);
        }
        if ($email && $wantsEmail && !empty($u['email']) && Settings::get('mail_transport') !== 'disabled') {
            $url = isset($data['url']) ? (string) $data['url'] : fr_public_url('/');
            $sent = Mailer::send((string) $u['email'], $title, Mailer::template($title, '<p>' . nl2br(Http::escape($body)) . '</p>', $url, 'Open'));
            if ($sent) DB::update('notifications', ['emailed' => 1], 'id = ?', [$id]);
        }
    }

    public static function admins(string $title, string $body, array $data = []): void
    {
        foreach (DB::col("SELECT id FROM {{users}} WHERE role = 'superuser' AND active = 1") as $id) {
            self::push((int) $id, 'system', $title, $body, $data);
        }
        $extra = trim((string) Settings::get('notify_admin_email'));
        if ($extra !== '') {
            Mailer::send($extra, $title, Mailer::template($title, '<p>' . nl2br(Http::escape($body)) . '</p>'));
        }
    }

    public static function listFor(int $userId, bool $unreadOnly = false, int $limit = 50): array
    {
        $rows = DB::all('SELECT * FROM {{notifications}} WHERE user_id = ?' . ($unreadOnly ? ' AND read_at IS NULL' : '') . ' ORDER BY id DESC LIMIT ' . max(1, min(200, $limit)), [$userId]);
        return array_map(static fn($r) => [
            'id' => (int) $r['id'], 'type' => $r['type'], 'title' => $r['title'], 'body' => $r['body'],
            'data' => Util::jsonDecode($r['data'], []), 'read' => $r['read_at'] !== null, 'created_at' => $r['created_at'],
        ], $rows);
    }

    public static function unreadCount(int $userId): int
    {
        return (int) DB::val('SELECT COUNT(*) FROM {{notifications}} WHERE user_id = ? AND read_at IS NULL', [$userId]);
    }

    public static function markRead(int $userId, ?array $ids = null): void
    {
        if ($ids === null) {
            DB::update('notifications', ['read_at' => fr_now()], 'user_id = ? AND read_at IS NULL', [$userId]);
        } elseif ($ids) {
            $in = implode(',', array_map('intval', $ids));
            DB::q('UPDATE {{notifications}} SET read_at = ? WHERE user_id = ? AND id IN (' . $in . ')', [fr_now(), $userId]);
        }
    }

    public static function purgeOld(): int
    {
        $days = Settings::int('notification_retention_days');
        if ($days <= 0) return 0;
        return DB::delete('notifications', 'created_at < ?', [gmdate('Y-m-d H:i:s', time() - $days * 86400)]);
    }

    // ------------------------------------------------------------------
    // Follows
    // ------------------------------------------------------------------

    public static function follow(int $userId, int $ownerId, string $path, string $events = 'all'): void
    {
        DB::upsert('follows', ['user_id' => $userId, 'owner_id' => $ownerId, 'path_hash' => sha1($path), 'path' => $path, 'events' => $events, 'created_at' => fr_now()], ['events']);
    }

    public static function unfollow(int $userId, int $ownerId, string $path): void
    {
        DB::delete('follows', 'user_id = ? AND owner_id = ? AND path_hash = ?', [$userId, $ownerId, sha1($path)]);
    }

    public static function isFollowing(int $userId, int $ownerId, string $path): bool
    {
        return (bool) DB::val('SELECT 1 FROM {{follows}} WHERE user_id = ? AND owner_id = ? AND path_hash = ?', [$userId, $ownerId, sha1($path)]);
    }

    public static function followsOf(int $userId): array
    {
        return DB::all('SELECT f.*, u.username AS owner_name FROM {{follows}} f JOIN {{users}} u ON u.id = f.owner_id WHERE f.user_id = ? ORDER BY f.path', [$userId]);
    }

    /**
     * Fan out a file event to everybody who should hear about it: followers of
     * the item or any parent folder, owners of shares that asked to be
     * notified, and the owner of the folder when somebody else acted in it.
     */
    public static function fileEvent(string $event, int $ownerId, string $path, bool $isDir, ?array $actor, array $extra = []): void
    {
        try {
            $actorId = $actor['id'] ?? null;
            $actorName = $actor ? ($actor['name'] ?: $actor['username']) : ($extra['actor_name'] ?? 'Somebody');
            $name = Util::baseName($path) ?: '/';
            $verb = ['upload' => 'uploaded', 'download' => 'downloaded', 'comment' => 'commented on', 'delete' => 'deleted', 'edit' => 'edited', 'rename' => 'renamed', 'create_folder' => 'created the folder', 'move' => 'moved', 'restore' => 'restored', 'link_download' => 'downloaded (via a link)', 'link_upload' => 'uploaded (via a file request)'][$event] ?? $event;
            $title = $actorName . ' ' . $verb . ' ' . $name;
            $body = isset($extra['body']) ? (string) $extra['body'] : ('Path: /' . $path . (isset($extra['count']) ? ' (' . $extra['count'] . ' items)' : ''));
            $url = fr_public_url('/#/files/' . rawurlencode(Util::dirName($path)));
            $recipients = [];

            // Followers of the item and of every ancestor folder.
            $ancestors = [];
            $p = $path;
            while (true) {
                $ancestors[] = sha1($p);
                if ($p === '') break;
                $p = Util::dirName($p);
            }
            $in = implode(',', array_fill(0, count($ancestors), '?'));
            foreach (DB::all("SELECT user_id, events FROM {{follows}} WHERE owner_id = ? AND path_hash IN ($in)", array_merge([$ownerId], $ancestors)) as $f) {
                $events = $f['events'] === 'all' ? null : explode(',', (string) $f['events']);
                if ($events === null || in_array($event, $events, true)) {
                    $recipients[(int) $f['user_id']] = true;
                }
            }
            // Shares with notify flag (owner wants to know what recipients do; recipients learn what the owner does).
            foreach (DB::all("SELECT owner_id, created_by, target_type, target_id FROM {{shares}} WHERE owner_id = ? AND notify = 1 AND path_hash IN ($in)", array_merge([$ownerId], $ancestors)) as $s) {
                $recipients[(int) $s['owner_id']] = true;
                if ($s['created_by']) $recipients[(int) $s['created_by']] = true;
            }
            // The owner of the folder always hears about uploads/comments by others, unless opted out.
            if (in_array($event, ['upload', 'comment', 'link_upload', 'link_download'], true)) {
                $recipients[$ownerId] = true;
            }
            unset($recipients[(int) $actorId]);
            foreach (array_keys($recipients) as $uid) {
                self::push((int) $uid, $event === 'link_download' ? 'download' : (str_starts_with($event, 'link_') ? substr($event, 5) : $event), $title, $body, ['url' => $url, 'path' => $path, 'owner_id' => $ownerId, 'is_dir' => $isDir] + $extra);
            }
        } catch (Throwable $e) {
            fr_log('notify fileEvent failed: ' . $e->getMessage());
        }
    }
}
