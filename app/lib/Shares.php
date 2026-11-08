<?php
declare(strict_types=1);

/**
 * Sharing with users and groups (seven roles), "Shared with me", and sharing
 * with people outside through guest accounts created on the fly.
 */
final class Shares
{
    public static function find(int $id): ?array
    {
        return DB::one('SELECT * FROM {{shares}} WHERE id = ?', [$id]);
    }

    /** Can $user open this share (as a direct target or through a group)? */
    public static function canAccess(array $share, array $user): bool
    {
        if ($share['expires_at'] && strtotime((string) $share['expires_at']) < time()) return false;
        if ($share['target_type'] === 'user') {
            return (int) $share['target_id'] === (int) $user['id'];
        }
        return in_array((int) $share['target_id'], Users::userGroupIds((int) $user['id']), true);
    }

    /** Shares and links on one item (for its owner / sharer). */
    public static function forItem(int $ownerId, string $path): array
    {
        $rows = DB::all('SELECT s.*, u.username AS target_username, u.name AS target_name, u.email AS target_email, u.role AS target_role, g.name AS group_name, c.username AS created_by_name FROM {{shares}} s LEFT JOIN {{users}} u ON s.target_type = \'user\' AND u.id = s.target_id LEFT JOIN {{groups}} g ON s.target_type = \'group\' AND g.id = s.target_id LEFT JOIN {{users}} c ON c.id = s.created_by WHERE s.owner_id = ? AND s.path_hash = ? ORDER BY s.created_at', [$ownerId, sha1($path)]);
        return array_map([self::class, 'format'], $rows);
    }

    public static function format(array $s): array
    {
        return [
            'id' => (int) $s['id'],
            'owner_id' => (int) $s['owner_id'],
            'path' => $s['path'],
            'is_dir' => (bool) $s['is_dir'],
            'target_type' => $s['target_type'],
            'target_id' => (int) $s['target_id'],
            'target_name' => $s['target_type'] === 'group' ? ($s['group_name'] ?? ('Group #' . $s['target_id'])) : (($s['target_name'] ?? '') ?: ($s['target_username'] ?? ('User #' . $s['target_id']))),
            'target_username' => $s['target_username'] ?? null,
            'target_email' => $s['target_email'] ?? null,
            'target_is_guest' => ($s['target_role'] ?? '') === 'guest',
            'role' => $s['role'],
            'role_label' => Paths::ROLE_LABELS[$s['role']] ?? $s['role'],
            'alias' => $s['alias'],
            'notify' => (bool) $s['notify'],
            'created_by' => $s['created_by'] !== null ? (int) $s['created_by'] : null,
            'created_by_name' => $s['created_by_name'] ?? null,
            'created_at' => $s['created_at'],
            'expires_at' => $s['expires_at'],
        ];
    }

    /** Roles a user acting through $ctx may grant to others. */
    public static function grantableRoles(Ctx $ctx): array
    {
        if ($ctx->root === 'home') return Paths::ROLES;
        if ($ctx->role === 'distributor') return ['viewer', 'commenter', 'distributor'];
        if ($ctx->role === 'content_manager') return ['viewer', 'commenter', 'editor', 'contributor', 'content_manager', 'uploader'];
        return [];
    }

    public static function create(Ctx $ctx, string $targetType, int $targetId, string $role, ?string $alias, bool $notify, ?string $expiresAt, array $actor): array
    {
        $ctx->need('share');
        if (!is_dir($ctx->abs) && !is_file($ctx->abs)) throw new NotFoundException('Item not found');
        if ($ctx->rel === '' && $ctx->root === 'home') throw new RuntimeException('The home folder itself cannot be shared; share a folder inside it');
        if (!in_array($role, self::grantableRoles($ctx), true)) throw new PermissionException('You cannot grant the role "' . $role . '"');
        if (!Users::can($actor, 'share_users')) throw new PermissionException('Sharing with users is not enabled for your account');
        if (!in_array($targetType, ['user', 'group'], true)) throw new RuntimeException('Invalid target');
        if ($targetType === 'user') {
            $target = Users::find($targetId);
            if (!$target || !$target['active']) throw new RuntimeException('User not found');
            if ((int) $target['id'] === $ctx->ownerId) throw new RuntimeException('That user owns this item already');
            if ($target['role'] === 'guest' && !Users::can($actor, 'share_guests')) throw new PermissionException('Sharing with guests is not enabled for your account');
        } else {
            if (!DB::one('SELECT id FROM {{groups}} WHERE id = ?', [$targetId])) throw new RuntimeException('Group not found');
        }
        $row = [
            'owner_id' => $ctx->ownerId, 'path' => $ctx->rel, 'path_hash' => sha1($ctx->rel), 'is_dir' => is_dir($ctx->abs) ? 1 : 0,
            'target_type' => $targetType, 'target_id' => $targetId, 'role' => $role, 'alias' => $alias !== null && $alias !== '' ? mb_substr($alias, 0, 255) : null,
            'notify' => $notify ? 1 : 0, 'created_by' => (int) $actor['id'], 'created_at' => fr_now(), 'expires_at' => $expiresAt ?: null,
        ];
        $existing = DB::one('SELECT id FROM {{shares}} WHERE owner_id = ? AND path_hash = ? AND target_type = ? AND target_id = ?', [$ctx->ownerId, sha1($ctx->rel), $targetType, $targetId]);
        if ($existing) {
            DB::update('shares', ['role' => $role, 'alias' => $row['alias'], 'notify' => $row['notify'], 'expires_at' => $row['expires_at']], 'id = ?', [(int) $existing['id']]);
            $id = (int) $existing['id'];
        } else {
            $id = DB::insert('shares', $row);
        }
        Activity::log($existing ? 'share_update' : 'share_create', ['owner_id' => $ctx->ownerId, 'path' => $ctx->rel, 'is_dir' => $row['is_dir'], 'details' => ['target_type' => $targetType, 'target_id' => $targetId, 'role' => $role]]);
        // Tell the recipients.
        $recipients = $targetType === 'user' ? [$targetId] : array_map('intval', DB::col('SELECT user_id FROM {{group_members}} WHERE group_id = ?', [$targetId]));
        $actorName = $actor['name'] ?: $actor['username'];
        foreach ($recipients as $uid) {
            if ($uid === (int) $actor['id']) continue;
            Notify::push($uid, 'share', $actorName . ' shared "' . ($alias ?: Util::baseName($ctx->rel)) . '" with you', 'You have the ' . (Paths::ROLE_LABELS[$role] ?? $role) . ' role. Find it under Shared with me.', ['url' => fr_public_url('/#/shared'), 'share_id' => $id], Settings::bool('share_notify_default'));
        }
        return self::formatById($id);
    }

    public static function formatById(int $id): array
    {
        $s = DB::one('SELECT s.*, u.username AS target_username, u.name AS target_name, u.email AS target_email, u.role AS target_role, g.name AS group_name, c.username AS created_by_name FROM {{shares}} s LEFT JOIN {{users}} u ON s.target_type = \'user\' AND u.id = s.target_id LEFT JOIN {{groups}} g ON s.target_type = \'group\' AND g.id = s.target_id LEFT JOIN {{users}} c ON c.id = s.created_by WHERE s.id = ?', [$id]);
        return $s ? self::format($s) : [];
    }

    public static function canManageShare(array $share, array $user): bool
    {
        return Users::isSuper($user) || (int) $share['owner_id'] === (int) $user['id'] || (int) ($share['created_by'] ?? 0) === (int) $user['id'];
    }

    public static function update(int $id, array $data, array $actor): array
    {
        $s = self::find($id);
        if (!$s || !self::canManageShare($s, $actor)) throw new PermissionException('Share not found');
        $row = [];
        if (isset($data['role']) && in_array($data['role'], Paths::ROLES, true)) $row['role'] = $data['role'];
        if (array_key_exists('alias', $data)) $row['alias'] = $data['alias'] !== '' ? mb_substr((string) $data['alias'], 0, 255) : null;
        if (array_key_exists('notify', $data)) $row['notify'] = Util::bool($data['notify']) ? 1 : 0;
        if (array_key_exists('expires_at', $data)) $row['expires_at'] = $data['expires_at'] ?: null;
        if ($row) DB::update('shares', $row, 'id = ?', [$id]);
        Activity::log('share_update', ['owner_id' => (int) $s['owner_id'], 'path' => $s['path'], 'is_dir' => (int) $s['is_dir'], 'details' => $row]);
        return self::formatById($id);
    }

    public static function delete(int $id, array $actor): void
    {
        $s = self::find($id);
        if (!$s) return;
        $isRecipient = $s['target_type'] === 'user' && (int) $s['target_id'] === (int) $actor['id'];
        if (!self::canManageShare($s, $actor) && !$isRecipient) throw new PermissionException('Share not found');
        DB::delete('shares', 'id = ?', [$id]);
        Activity::log('share_delete', ['owner_id' => (int) $s['owner_id'], 'path' => $s['path'], 'is_dir' => (int) $s['is_dir'], 'details' => ['target_type' => $s['target_type'], 'target_id' => (int) $s['target_id'], 'by_recipient' => $isRecipient]]);
        if ($s['target_type'] === 'user') {
            $t = Users::find((int) $s['target_id']);
            if ($t && $t['role'] === 'guest') Users::cleanupGuests();
        }
    }

    /** Everything shared with $user, newest first; duplicates (same item through several routes) collapsed. */
    public static function sharedWithMe(array $user): array
    {
        $groupIds = Users::userGroupIds((int) $user['id']);
        $where = "(s.target_type = 'user' AND s.target_id = ?)";
        $params = [(int) $user['id']];
        if ($groupIds) {
            $where .= " OR (s.target_type = 'group' AND s.target_id IN (" . implode(',', array_fill(0, count($groupIds), '?')) . '))';
            foreach ($groupIds as $g) $params[] = $g;
        }
        $rows = DB::all("SELECT s.*, o.username AS owner_username, o.name AS owner_name, o.active AS owner_active, g.name AS group_name FROM {{shares}} s JOIN {{users}} o ON o.id = s.owner_id LEFT JOIN {{groups}} g ON s.target_type = 'group' AND g.id = s.target_id WHERE ($where) AND (s.expires_at IS NULL OR s.expires_at > ?) ORDER BY s.created_at DESC", array_merge($params, [fr_now()]));
        $rank = array_flip(['uploader', 'viewer', 'commenter', 'distributor', 'editor', 'contributor', 'content_manager']);
        $out = [];
        foreach ($rows as $s) {
            if (!$s['owner_active']) continue;
            $key = $s['owner_id'] . ':' . $s['path_hash'];
            if (isset($out[$key]) && ($rank[$out[$key]['role']] ?? 0) >= ($rank[$s['role']] ?? 0)) continue;
            $home = Users::homeDir(['home_path' => DB::val('SELECT home_path FROM {{users}} WHERE id = ?', [(int) $s['owner_id']]), 'username' => $s['owner_username']]);
            $abs = $s['path'] === '' ? $home : $home . '/' . $s['path'];
            $exists = file_exists($abs);
            $out[$key] = [
                'id' => (int) $s['id'],
                'vpath' => '@' . $s['id'],
                'name' => $s['alias'] ?: Util::baseName($s['path']),
                'path' => $s['path'],
                'is_dir' => (bool) $s['is_dir'],
                'role' => $s['role'],
                'role_label' => Paths::ROLE_LABELS[$s['role']] ?? $s['role'],
                'perms' => Paths::permsForRole($s['role']),
                'owner' => ['id' => (int) $s['owner_id'], 'username' => $s['owner_username'], 'name' => $s['owner_name'] ?: $s['owner_username']],
                'via_group' => $s['target_type'] === 'group' ? $s['group_name'] : null,
                'created_at' => $s['created_at'],
                'expires_at' => $s['expires_at'],
                'exists' => $exists,
                'size' => $exists && !$s['is_dir'] ? (int) filesize($abs) : 0,
                'mtime' => $exists ? (int) filemtime($abs) : 0,
                'kind' => Util::kind(Util::baseName($s['path']), (bool) $s['is_dir']),
                'ext' => Util::ext(Util::baseName($s['path'])),
                'mime' => $s['is_dir'] ? 'inode/directory' : Util::mime(Util::baseName($s['path'])),
            ];
        }
        return array_values($out);
    }

    /** Items the user has shared with others (owner view). */
    public static function sharedByMe(array $user): array
    {
        $rows = DB::all('SELECT s.*, u.username AS target_username, u.name AS target_name, u.email AS target_email, u.role AS target_role, g.name AS group_name, c.username AS created_by_name FROM {{shares}} s LEFT JOIN {{users}} u ON s.target_type = \'user\' AND u.id = s.target_id LEFT JOIN {{groups}} g ON s.target_type = \'group\' AND g.id = s.target_id LEFT JOIN {{users}} c ON c.id = s.created_by WHERE s.owner_id = ? OR s.created_by = ? ORDER BY s.path, s.created_at', [(int) $user['id'], (int) $user['id']]);
        return array_map([self::class, 'format'], $rows);
    }

    /** Does the owner have any shares or links on this exact path? (used for the "shared" badge) */
    public static function sharedPaths(int $ownerId, array $pathHashes): array
    {
        if (!$pathHashes) return [];
        $out = [];
        foreach (array_chunk($pathHashes, 500) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            foreach (DB::col("SELECT DISTINCT path_hash FROM {{shares}} WHERE owner_id = ? AND path_hash IN ($in)", array_merge([$ownerId], $chunk)) as $h) $out[$h] = 'users';
            foreach (DB::col("SELECT DISTINCT path_hash FROM {{weblinks}} WHERE owner_id = ? AND active = 1 AND path_hash IN ($in)", array_merge([$ownerId], $chunk)) as $h) $out[$h] = isset($out[$h]) ? 'both' : 'link';
        }
        return $out;
    }

    /**
     * Share with an e-mail address. Creates a guest account when needed and
     * e-mails the person their credentials.
     */
    public static function inviteByEmail(Ctx $ctx, string $email, string $role, string $message, array $actor): array
    {
        if (!Users::can($actor, 'share_guests')) throw new PermissionException('Sharing with guests is not enabled for your account');
        [$guest, $created, $password] = Users::guestForEmail($email, $actor);
        $share = self::create($ctx, 'user', (int) $guest['id'], $role, null, false, null, $actor);
        $actorName = $actor['name'] ?: $actor['username'];
        $itemName = Util::baseName($ctx->rel);
        $url = fr_public_url('/#/shared');
        $html = '<p><b>' . Http::escape($actorName) . '</b> shared <b>' . Http::escape($itemName) . '</b> with you on ' . Http::escape((string) Settings::get('app_name')) . '.</p>';
        if ($message !== '') $html .= '<blockquote style="border-left:3px solid #ddd;margin:12px 0;padding:6px 12px;color:#555">' . nl2br(Http::escape($message)) . '</blockquote>';
        if ($created) {
            $html .= '<p>A guest account was created for you:</p><p>Username: <b>' . Http::escape($guest['username']) . '</b><br>Password: <b>' . Http::escape((string) $password) . '</b></p><p>You will be asked to choose your own password when you sign in.</p>';
            DB::update('users', ['must_change_password' => 1], 'id = ?', [(int) $guest['id']]);
            Users::forget((int) $guest['id']);
        } else {
            $html .= '<p>Sign in with your existing account to open it.</p>';
        }
        Mailer::send($email, $actorName . ' shared "' . $itemName . '" with you', Mailer::template('Something was shared with you', $html, $url, 'Open ' . Settings::get('app_name')));
        Activity::log('share_create', ['owner_id' => $ctx->ownerId, 'path' => $ctx->rel, 'is_dir' => is_dir($ctx->abs) ? 1 : 0, 'details' => ['guest' => $email, 'created' => $created, 'role' => $role]]);
        return $share + ['guest_created' => $created];
    }
}
