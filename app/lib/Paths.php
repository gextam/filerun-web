<?php
declare(strict_types=1);

/**
 * Resolves the virtual paths used by clients into real locations plus the
 * permissions the current user has there.
 *
 *   /Photos/2024            inside the user's own home folder
 *   @17/sub/file.jpg        inside the item shared with the user through share #17
 *   (link mode)  /sub/x     relative to the root of a public web link
 *
 * The seven sharing roles (Viewer, Commenter, Editor, Contributor, Distributor,
 * Content manager, Uploader) are mapped to flat permission sets so that every
 * client (web, WebDAV, API, links) runs the exact same checks.
 */
final class Ctx
{
    public int $ownerId;
    public array $owner;
    public string $rel = '';        // path relative to the owner's home ('' = home itself)
    public string $abs = '';        // absolute filesystem path
    public string $vpath = '/';     // normalized virtual path as the client sees it
    public string $root = 'home';   // home | share | link
    public ?array $share = null;
    public ?array $link = null;
    public string $role = 'owner';
    public array $perms = [];
    public ?array $user = null;

    public function can(string $perm): bool
    {
        return !empty($this->perms[$perm]);
    }

    public function need(string $perm): void
    {
        if (!$this->can($perm)) {
            throw new PermissionException('You are not allowed to ' . Paths::permLabel($perm) . ' here');
        }
    }

    public function isHome(): bool
    {
        return $this->root === 'home';
    }

    public function name(): string
    {
        if ($this->rel === '') {
            return $this->share['alias'] ?? ($this->link['title'] ?? ($this->owner['name'] ?: $this->owner['username']));
        }
        return Util::baseName($this->rel);
    }

    /** Virtual path of a child entry. */
    public function child(string $name): string
    {
        return Paths::join($this->vpath, $name);
    }

    /** Context for a child entry (same root / share / link). */
    public function childCtx(string $name): Ctx
    {
        return Paths::resolve($this->child($name), $this->user, $this->link);
    }

    public function parentVpath(): ?string
    {
        if ($this->root === 'home') {
            return $this->rel === '' ? null : '/' . Util::dirName($this->rel);
        }
        $prefix = $this->root === 'share' ? '@' . $this->share['id'] : '';
        $shareRel = $this->shareRel();
        if ($shareRel === '') return null;
        $d = Util::dirName($shareRel);
        return $prefix . '/' . $d;
    }

    /** Path relative to the share / link root. */
    public function shareRel(): string
    {
        $base = $this->root === 'share' ? (string) $this->share['path'] : ($this->root === 'link' ? (string) $this->link['path'] : '');
        if ($base === '') return $this->rel;
        if ($this->rel === $base) return '';
        return substr($this->rel, strlen($base) + 1);
    }
}

final class PermissionException extends RuntimeException
{
}

final class Paths
{
    public const ROLES = ['viewer', 'commenter', 'editor', 'contributor', 'distributor', 'content_manager', 'uploader'];

    public const ROLE_LABELS = [
        'owner' => 'Owner', 'viewer' => 'Viewer', 'commenter' => 'Commenter', 'editor' => 'Editor', 'contributor' => 'Contributor',
        'distributor' => 'Distributor', 'content_manager' => 'Content manager', 'uploader' => 'Uploader',
    ];

    public const ROLE_DESCRIPTIONS = [
        'viewer' => 'Can browse, preview and download.',
        'commenter' => 'Viewer, plus can leave comments.',
        'editor' => 'Commenter, plus can edit existing files and their metadata.',
        'contributor' => 'Editor, plus can upload and create new files and folders.',
        'distributor' => 'Viewer, plus can share the content further and create links.',
        'content_manager' => 'Full control of the content: upload, edit, rename, move and delete.',
        'uploader' => 'Can only add files. Cannot see or download what is already there.',
    ];

    private const PERMS = [
        'owner' => ['read' => 1, 'download' => 1, 'comment' => 1, 'edit' => 1, 'create' => 1, 'delete' => 1, 'rename' => 1, 'share' => 1, 'meta' => 1, 'lock' => 1],
        'viewer' => ['read' => 1, 'download' => 1],
        'commenter' => ['read' => 1, 'download' => 1, 'comment' => 1],
        'editor' => ['read' => 1, 'download' => 1, 'comment' => 1, 'edit' => 1, 'meta' => 1, 'lock' => 1],
        'contributor' => ['read' => 1, 'download' => 1, 'comment' => 1, 'edit' => 1, 'create' => 1, 'meta' => 1, 'lock' => 1],
        'distributor' => ['read' => 1, 'download' => 1, 'comment' => 1, 'share' => 1],
        'content_manager' => ['read' => 1, 'download' => 1, 'comment' => 1, 'edit' => 1, 'create' => 1, 'delete' => 1, 'rename' => 1, 'meta' => 1, 'lock' => 1],
        'uploader' => ['create' => 1],
    ];

    public static function permsForRole(string $role): array
    {
        $p = self::PERMS[$role] ?? self::PERMS['viewer'];
        return array_map('boolval', $p + ['read' => 0, 'download' => 0, 'comment' => 0, 'edit' => 0, 'create' => 0, 'delete' => 0, 'rename' => 0, 'share' => 0, 'meta' => 0, 'lock' => 0]);
    }

    public static function permLabel(string $perm): string
    {
        return ['read' => 'view files', 'download' => 'download', 'comment' => 'comment', 'edit' => 'edit files', 'create' => 'add files', 'delete' => 'delete', 'rename' => 'rename or move', 'share' => 'share', 'meta' => 'change metadata', 'lock' => 'lock files'][$perm] ?? $perm;
    }

    public static function join(string $vpath, string $name): string
    {
        $vpath = rtrim($vpath, '/');
        if ($vpath === '' || $vpath === '@') return '/' . $name;
        return $vpath . '/' . $name;
    }

    /** Normalize a client-supplied virtual path. */
    public static function normalize(string $vpath): string
    {
        $vpath = trim(str_replace('\\', '/', $vpath));
        if ($vpath === '' || $vpath === '/') return '/';
        if ($vpath[0] === '@') {
            if (!preg_match('#^@(\d+)(/.*)?$#', $vpath, $m)) {
                throw new RuntimeException('Invalid path');
            }
            $rest = Util::normPath($m[2] ?? '');
            return '@' . $m[1] . ($rest === '' ? '' : '/' . $rest);
        }
        $n = Util::normPath($vpath);
        return '/' . $n;
    }

    /**
     * Resolve a virtual path for $user (or for an anonymous visitor of $link).
     */
    public static function resolve(string $vpath, ?array $user, ?array $link = null): Ctx
    {
        $vpath = self::normalize($vpath);
        $ctx = new Ctx();
        $ctx->user = $user;
        $ctx->vpath = $vpath;

        if ($link !== null) {
            $ctx->root = 'link';
            $ctx->link = $link;
            $owner = Users::find((int) $link['owner_id']);
            if (!$owner) throw new RuntimeException('Link owner not found');
            $ctx->owner = $owner;
            $ctx->ownerId = (int) $owner['id'];
            $sub = ltrim($vpath, '/');
            if (!$link['is_dir'] && $sub !== '') {
                throw new RuntimeException('Not found');
            }
            $ctx->rel = Util::joinPath((string) $link['path'], $sub);
            $ctx->role = (string) $link['role'];
            $ctx->perms = self::permsForRole($ctx->role);
            if ($link['allow_preview_only']) $ctx->perms['download'] = false;
            // A link can never grant more than its creator currently has.
            if (!Users::can($owner, 'download')) $ctx->perms['download'] = false;
            $ctx->perms['share'] = false;
            $ctx->perms['lock'] = false;
        } elseif ($vpath[0] === '@') {
            if ($user === null) throw new PermissionException('Authentication required');
            preg_match('#^@(\d+)(?:/(.*))?$#', $vpath, $m);
            $share = Shares::find((int) $m[1]);
            if (!$share || !Shares::canAccess($share, $user)) {
                throw new PermissionException('This share is not available');
            }
            $owner = Users::find((int) $share['owner_id']);
            if (!$owner || !$owner['active']) throw new PermissionException('This share is not available');
            $ctx->root = 'share';
            $ctx->share = $share;
            $ctx->owner = $owner;
            $ctx->ownerId = (int) $owner['id'];
            $sub = $m[2] ?? '';
            if (!$share['is_dir'] && $sub !== '') throw new RuntimeException('Not found');
            $ctx->rel = Util::joinPath((string) $share['path'], $sub);
            $ctx->role = (string) $share['role'];
            $ctx->perms = self::permsForRole($ctx->role);
            if (!Users::can($user, 'download')) $ctx->perms['download'] = false;
            if (!Users::can($user, 'upload')) $ctx->perms['create'] = false;
            if (!Users::can($user, 'comments')) $ctx->perms['comment'] = false;
            if (!Users::can($user, 'metadata')) $ctx->perms['meta'] = false;
            if (!Users::can($user, 'share_users') && !Users::can($user, 'share_links')) $ctx->perms['share'] = false;
        } else {
            if ($user === null) throw new PermissionException('Authentication required');
            $ctx->root = 'home';
            $ctx->owner = $user;
            $ctx->ownerId = (int) $user['id'];
            $ctx->rel = ltrim($vpath, '/');
            $ctx->role = 'owner';
            $ctx->perms = self::permsForRole('owner');
            if (!Users::can($user, 'download')) $ctx->perms['download'] = false;
            if (!Users::can($user, 'upload')) { $ctx->perms['create'] = false; $ctx->perms['edit'] = false; }
            if (!Users::can($user, 'comments')) $ctx->perms['comment'] = false;
            if (!Users::can($user, 'metadata')) $ctx->perms['meta'] = false;
            if (!Users::can($user, 'share_users') && !Users::can($user, 'share_links') && !Users::can($user, 'share_guests')) $ctx->perms['share'] = false;
        }
        $home = Users::homeDir($ctx->owner);
        $ctx->abs = $ctx->rel === '' ? $home : $home . '/' . $ctx->rel;
        // Defence in depth against traversal through odd encodings or symlinks.
        if (str_contains($ctx->rel, "\0") || str_contains('/' . $ctx->rel . '/', '/../')) {
            throw new RuntimeException('Invalid path');
        }
        return $ctx;
    }

    /** Resolve and require the item to exist. */
    public static function resolveExisting(string $vpath, ?array $user, ?array $link = null): Ctx
    {
        $ctx = self::resolve($vpath, $user, $link);
        if (!file_exists($ctx->abs)) {
            throw new NotFoundException('Not found: ' . $ctx->vpath);
        }
        return $ctx;
    }

    /** Full file name of an item. */
    public static function displayPath(Ctx $ctx): string
    {
        return '/' . $ctx->rel;
    }
}

final class NotFoundException extends RuntimeException
{
}
