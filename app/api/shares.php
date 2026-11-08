<?php
declare(strict_types=1);

/** /api/shares/* — sharing with users, groups, guests and through public links. */
final class SharesApi
{
    /** Everything about sharing one item: existing shares, links, who can be picked, grantable roles. */
    public static function item(): void
    {
        $u = Api::requireUser();
        $ctx = Api::ctx();
        $canShare = $ctx->can('share');
        $isOwnerSide = $ctx->root === 'home';
        Http::ok([
            'shares' => $canShare ? Shares::forItem($ctx->ownerId, $ctx->rel) : [],
            'links' => $canShare ? Links::forItem($ctx->ownerId, $ctx->rel) : [],
            'can_share' => $canShare,
            'can_share_users' => $canShare && Users::can($u, 'share_users'),
            'can_share_links' => $canShare && Users::can($u, 'share_links') && Settings::bool('links_enabled'),
            'can_share_guests' => $canShare && Users::can($u, 'share_guests'),
            'roles' => array_map(static fn($r) => ['id' => $r, 'label' => Paths::ROLE_LABELS[$r], 'description' => Paths::ROLE_DESCRIPTIONS[$r]], Shares::grantableRoles($ctx)),
            'link_roles' => array_values(array_filter(Links::LINK_ROLES, static fn($r) => is_dir($ctx->abs) || !in_array($r, ['uploader', 'contributor', 'content_manager'], true))),
            'is_owner' => $isOwnerSide,
            'link_max_days' => Settings::int('link_max_days'),
            'link_require_password' => Settings::bool('link_require_password'),
            'is_dir' => is_dir($ctx->abs),
        ]);
    }

    public static function users(): void
    {
        $u = Api::requireUser();
        $q = Http::str('q', '');
        Http::ok(['users' => Users::visibleUsers($u, $q, 50), 'groups' => Users::can($u, 'hide_users') && !Users::isAdmin($u) ? [] : array_map(static fn($g) => ['id' => (int) $g['id'], 'name' => $g['name'], 'members' => (int) $g['members']], Users::groups())]);
    }

    public static function create(): void
    {
        $u = Api::requireUser();
        $ctx = Api::ctx();
        $targets = Http::arr('targets');
        if (!$targets) $targets = [['type' => Http::str('target_type', 'user'), 'id' => Http::int('target_id')]];
        $out = [];
        foreach ($targets as $t) {
            $out[] = Shares::create($ctx, (string) ($t['type'] ?? 'user'), (int) ($t['id'] ?? 0), Http::str('role', 'viewer'), Http::str('alias', '') ?: null, Http::bool('notify', false), Http::str('expires_at', '') ?: null, $u);
        }
        Http::ok($out);
    }

    public static function update(): void
    {
        $u = Api::requireUser();
        Http::ok(Shares::update(Http::int('id'), Http::json() + $_POST, $u));
    }

    public static function delete(): void
    {
        $u = Api::requireUser();
        Shares::delete(Http::int('id'), $u);
        Http::ok(null);
    }

    public static function invite(): void
    {
        $u = Api::requireUser();
        $ctx = Api::ctx();
        $emails = Http::arr('emails') ?: [Http::str('email')];
        $out = [];
        foreach ($emails as $e) {
            $out[] = Shares::inviteByEmail($ctx, trim((string) $e), Http::str('role', 'viewer'), (string) Http::input('message', ''), $u);
        }
        Http::ok($out);
    }

    public static function with_me(): void
    {
        $u = Api::requireUser();
        Http::ok(['items' => Shares::sharedWithMe($u)]);
    }

    public static function by_me(): void
    {
        $u = Api::requireUser();
        Http::ok(['shares' => Shares::sharedByMe($u), 'links' => Links::mine($u)]);
    }

    /** Leave a share that was given to me. */
    public static function leave(): void
    {
        $u = Api::requireUser();
        Shares::delete(Http::int('id'), $u);
        Http::ok(null);
    }

    // ------------------------------------------------------------------ public links

    public static function link_create(): void
    {
        $u = Api::requireUser();
        $ctx = Api::ctx();
        Http::ok(Links::create($ctx, Http::json() + $_POST, $u));
    }

    public static function link_update(): void
    {
        $u = Api::requireUser();
        Http::ok(Links::update(Http::int('id'), Http::json() + $_POST, $u));
    }

    public static function link_delete(): void
    {
        $u = Api::requireUser();
        Links::delete(Http::int('id'), $u);
        Http::ok(null);
    }

    public static function links_mine(): void
    {
        $u = Api::requireUser();
        Http::ok(['items' => Links::mine($u)]);
    }

    /** E-mail a link to somebody. */
    public static function link_send(): void
    {
        $u = Api::requireUser();
        $l = Links::find(Http::int('id'));
        if (!$l || !Links::canManage($l, $u)) throw new PermissionException('Link not found');
        $emails = Http::arr('emails') ?: [Http::str('email')];
        $message = (string) Http::input('message', '');
        $name = $u['name'] ?: $u['username'];
        $sent = 0;
        foreach ($emails as $e) {
            $e = trim((string) $e);
            if (!filter_var($e, FILTER_VALIDATE_EMAIL)) continue;
            $html = '<p><b>' . Http::escape($name) . '</b> sent you a link to <b>' . Http::escape($l['title'] ?: Util::baseName($l['path'])) . '</b>.</p>' . ($message !== '' ? '<blockquote style="border-left:3px solid #ddd;margin:12px 0;padding:6px 12px;color:#555">' . nl2br(Http::escape($message)) . '</blockquote>' : '');
            if ($l['expires_at']) $html .= '<p>The link expires on ' . Http::escape(substr((string) $l['expires_at'], 0, 16)) . '.</p>';
            if (Mailer::send($e, $name . ' shared a link with you', Mailer::template('A link was shared with you', $html, Links::url($l), 'Open link'))) $sent++;
        }
        Http::ok(['sent' => $sent]);
    }
}
