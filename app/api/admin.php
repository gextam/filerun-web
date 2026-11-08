<?php
declare(strict_types=1);

/** /api/admin/* — the control panel. Superusers see everything; delegated administrators only their own accounts. */
final class AdminApi
{
    private static function admin(): array
    {
        return Auth::requireAdmin();
    }

    private static function target(int $id, array $admin): array
    {
        $t = Users::find($id);
        if (!$t || !Users::canManage($admin, $t)) throw new NotFoundException('User not found');
        return $t;
    }

    // ------------------------------------------------------------------ users

    public static function users(): void
    {
        $a = self::admin();
        Http::ok(Users::managedUsers($a, ['q' => Http::str('q'), 'role' => Http::str('role'), 'active' => Http::str('active'), 'group_id' => Http::int('group_id'), 'sort' => Http::str('sort', 'username'), 'dir' => Http::str('dir', 'asc'), 'limit' => Http::int('limit', 100), 'offset' => Http::int('offset', 0)]));
    }

    public static function user(): void
    {
        $a = self::admin();
        $t = self::target(Http::int('id'), $a);
        Http::ok(Users::adminView($t) + ['sessions' => Auth::sessionsOf((int) $t['id']), 'links' => count(Links::mine($t)), 'shares' => count(Shares::sharedByMe($t)), 'recent' => Activity::forUser((int) $t['id'], 20)]);
    }

    public static function user_save(): void
    {
        $a = self::admin();
        $d = Http::json() + $_POST;
        $id = (int) ($d['id'] ?? 0);
        if (!empty($d['password'])) Auth::validatePasswordStrength((string) $d['password']);
        if ($id) {
            $t = self::target($id, $a);
            if ((int) $t['id'] === (int) $a['id'] && isset($d['role']) && $d['role'] !== $a['role']) throw new RuntimeException('You cannot change your own role');
            if ((int) $t['id'] === (int) $a['id'] && isset($d['active']) && !Util::bool($d['active'])) throw new RuntimeException('You cannot deactivate your own account');
            $before = Users::adminView($t);
            $u = Users::update($id, $d, $a);
            $after = Users::adminView($u);
            $changed = array_keys(array_filter($after, static fn($v, $k) => ($before[$k] ?? null) !== $v && !in_array($k, ['recent', 'last_activity_at'], true), ARRAY_FILTER_USE_BOTH));
            Activity::log('user_update', ['details' => ['user_id' => $id, 'username' => $u['username'], 'changed' => $changed]]);
            if (in_array('role', $changed, true)) Activity::log('role_change', ['details' => ['user_id' => $id, 'from' => $before['role'], 'to' => $after['role']]]);
            if (in_array('active', $changed, true)) Activity::log($after['active'] ? 'user_activate' : 'user_deactivate', ['details' => ['user_id' => $id, 'username' => $u['username']]]);
            if (in_array('groups', $changed, true)) Activity::log('group_update', ['details' => ['user_id' => $id, 'groups' => array_column($after['groups'], 'name')]]);
        } else {
            $u = Users::create($d, $a);
            Activity::log('user_create', ['details' => ['user_id' => (int) $u['id'], 'username' => $u['username'], 'role' => $u['role']]]);
            if (!empty($d['send_welcome']) && !empty($u['email'])) {
                $html = '<p>An account was created for you on ' . Http::escape((string) Settings::get('app_name')) . '.</p><p>Username: <b>' . Http::escape($u['username']) . '</b>' . (!empty($d['password']) ? '<br>Password: <b>' . Http::escape((string) $d['password']) . '</b>' : '') . '</p>';
                Mailer::send((string) $u['email'], 'Your new account', Mailer::template('Welcome', $html, fr_public_url('/'), 'Sign in'));
            }
        }
        Http::ok(Users::adminView($u));
    }

    public static function user_delete(): void
    {
        $a = self::admin();
        $t = self::target(Http::int('id'), $a);
        if ((int) $t['id'] === (int) $a['id']) throw new RuntimeException('You cannot delete your own account');
        if ($t['role'] === 'superuser' && !Users::isSuper($a)) throw new PermissionException('Not allowed');
        Users::delete((int) $t['id'], Http::bool('delete_files', false));
        Activity::log('user_delete', ['details' => ['user_id' => (int) $t['id'], 'username' => $t['username'], 'files_deleted' => Http::bool('delete_files', false)]]);
        Http::ok(null);
    }

    public static function user_reset_2fa(): void
    {
        $a = self::admin();
        $t = self::target(Http::int('id'), $a);
        DB::update('users', ['two_factor_secret' => null, 'recovery_codes' => null, 'failed_2fa' => 0], 'id = ?', [(int) $t['id']]);
        if (Http::bool('passkeys', false)) DB::delete('passkeys', 'user_id = ?', [(int) $t['id']]);
        Users::forget((int) $t['id']);
        Activity::log('two_factor_disable', ['details' => ['user_id' => (int) $t['id'], 'by_admin' => true]]);
        Http::ok(Users::adminView(Users::find((int) $t['id'])));
    }

    public static function user_revoke_sessions(): void
    {
        $a = self::admin();
        $t = self::target(Http::int('id'), $a);
        Auth::revokeAllSessions((int) $t['id']);
        Activity::log('session_revoke', ['details' => ['user_id' => (int) $t['id'], 'by_admin' => true]]);
        Http::ok(null);
    }

    /** Superusers can sign in as another account (recorded in the log). */
    public static function impersonate(): void
    {
        $a = Auth::requireSuper();
        $t = Users::find(Http::int('id'));
        if (!$t) throw new NotFoundException('User not found');
        if ((int) $t['id'] === (int) $a['id']) throw new RuntimeException('That is you');
        Activity::log('login', ['user' => $t, 'details' => ['impersonated_by' => $a['username']]]);
        $sess = Auth::createSession($t, false, false);
        DB::update('sessions', ['data' => Util::json(['impersonator' => $a['username']])], 'id = ?', [$sess['id']]);
        Http::ok(['user' => Users::publicProfile($t)]);
    }

    public static function recalc_usage(): void
    {
        $a = self::admin();
        $t = self::target(Http::int('id'), $a);
        Http::ok(['used_bytes' => Users::recalcUsage($t)]);
    }

    public static function rescan(): void
    {
        $a = self::admin();
        $t = self::target(Http::int('id'), $a);
        Http::ok(Index::rescan($t));
    }

    public static function reindex(): void
    {
        $a = self::admin();
        $t = self::target(Http::int('id'), $a);
        Jobs::push('index_tree', ['owner_id' => (int) $t['id'], 'path' => '']);
        Http::ok(['queued' => true, 'pending' => Jobs::pending()]);
    }

    // ------------------------------------------------------------------ groups

    public static function groups(): void
    {
        self::admin();
        Http::ok(array_map(static fn($g) => ['id' => (int) $g['id'], 'name' => $g['name'], 'description' => $g['description'], 'members' => (int) $g['members'], 'created_at' => $g['created_at']], Users::groups()));
    }

    public static function group(): void
    {
        self::admin();
        $g = DB::one('SELECT * FROM {{groups}} WHERE id = ?', [Http::int('id')]);
        if (!$g) throw new NotFoundException('Group not found');
        Http::ok(['id' => (int) $g['id'], 'name' => $g['name'], 'description' => $g['description'], 'members' => Users::groupMembers((int) $g['id'])]);
    }

    public static function group_save(): void
    {
        $a = self::admin();
        $id = Http::int('id', 0) ?: null;
        $members = Http::input('members') !== null ? array_map('intval', Http::arr('members')) : null;
        $g = Users::saveGroup($id, Http::str('name'), Http::str('description'), $members, $a);
        Activity::log($id ? 'group_update' : 'group_create', ['details' => ['group_id' => (int) $g['id'], 'name' => $g['name']]]);
        Http::ok(['id' => (int) $g['id'], 'name' => $g['name'], 'description' => $g['description'], 'members' => Users::groupMembers((int) $g['id'])]);
    }

    public static function group_delete(): void
    {
        self::admin();
        $g = DB::one('SELECT * FROM {{groups}} WHERE id = ?', [Http::int('id')]);
        if ($g) {
            Users::deleteGroup((int) $g['id']);
            Activity::log('group_delete', ['details' => ['group_id' => (int) $g['id'], 'name' => $g['name']]]);
        }
        Http::ok(null);
    }

    // ------------------------------------------------------------------ settings

    public static function settings(): void
    {
        Auth::requireSuper();
        Http::ok(['settings' => Settings::adminSettings(), 'defaults' => Settings::defaults()]);
    }

    public static function settings_save(): void
    {
        Auth::requireSuper();
        $values = Http::arr('values');
        $defaults = Settings::defaults();
        $clean = [];
        foreach ($values as $k => $v) {
            if (!array_key_exists($k, $defaults) || in_array($k, ['schema_version', 'installed_at', 'cron_last_run'], true)) continue;
            if (in_array($k, ['smtp_pass', 'onlyoffice_secret', 'oidc_client_secret'], true) && $v === '********') continue;
            $d = $defaults[$k];
            if (is_bool($d)) $v = Util::bool($v);
            elseif (is_int($d)) $v = (int) (is_string($v) && preg_match('/[kmgt]b?$/i', trim($v)) ? Util::parseSize($v) : $v);
            elseif (is_array($d)) {
                if (is_string($v)) { $dec = json_decode($v, true); $v = is_array($dec) ? $dec : array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $v) ?: []), 'strlen')); }
                if (!is_array($v)) $v = [];
            } else $v = is_scalar($v) || $v === null ? (string) $v : Util::json($v);
            if ($k === 'accent_color' && !preg_match('/^#[0-9a-fA-F]{3,8}$/', (string) $v)) $v = $d;
            if ($k === 'timezone' && !in_array($v, timezone_identifiers_list(), true)) $v = 'UTC';
            $clean[$k] = $v;
        }
        Settings::setMany($clean);
        Activity::log('settings_update', ['details' => ['keys' => array_keys($clean)]]);
        Http::ok(['settings' => Settings::adminSettings()]);
    }

    public static function test_email(): void
    {
        Auth::requireSuper();
        Http::ok(Mailer::test(Http::str('to')));
    }

    /** Upload a logo or favicon. multipart field "file", param "kind" = logo|favicon. */
    public static function brand_upload(): void
    {
        Auth::requireSuper();
        $kind = Http::str('kind', 'logo') === 'favicon' ? 'favicon' : 'logo';
        $f = $_FILES['file'] ?? null;
        if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK) throw new RuntimeException('No file received');
        $ext = Util::ext((string) $f['name']);
        if (!in_array($ext, ['png', 'jpg', 'jpeg', 'svg', 'webp', 'gif', 'ico'], true)) throw new RuntimeException('Use a PNG, JPG, SVG, WEBP, GIF or ICO file');
        if ($ext === 'svg') {
            $svg = (string) file_get_contents((string) $f['tmp_name']);
            if (preg_match('/<script|on\w+\s*=|javascript:/i', $svg)) throw new RuntimeException('This SVG contains scripting and was rejected');
        }
        $dir = fr_data_dir('branding');
        if (!is_dir($dir)) @mkdir($dir, 0750, true);
        foreach (glob($dir . '/' . $kind . '.*') ?: [] as $old) @unlink($old);
        $dest = $dir . '/' . $kind . '.' . $ext;
        if (!@move_uploaded_file((string) $f['tmp_name'], $dest) && !@rename((string) $f['tmp_name'], $dest)) throw new RuntimeException('Cannot store the file');
        $url = fr_url('/api/public/brand?f=' . $kind . '&v=' . time());
        Settings::set($kind . '_url', $url);
        Activity::log('settings_update', ['details' => ['keys' => [$kind . '_url']]]);
        Http::ok(['url' => $url]);
    }

    public static function brand_remove(): void
    {
        Auth::requireSuper();
        $kind = Http::str('kind', 'logo') === 'favicon' ? 'favicon' : 'logo';
        foreach (glob(fr_data_dir('branding') . '/' . $kind . '.*') ?: [] as $old) @unlink($old);
        Settings::set($kind . '_url', '');
        Http::ok(null);
    }

    // ------------------------------------------------------------------ metadata fields

    public static function metadata_fields(): void
    {
        self::admin();
        Http::ok(Meta::fields());
    }

    public static function metadata_field_save(): void
    {
        Auth::requireSuper();
        $d = Http::json() + $_POST;
        $f = Meta::saveField((int) ($d['id'] ?? 0) ?: null, $d);
        Activity::log('settings_update', ['details' => ['metadata_field' => $f['key']]]);
        Http::ok(Meta::fields());
    }

    public static function metadata_field_delete(): void
    {
        Auth::requireSuper();
        Meta::deleteField(Http::int('id'));
        Http::ok(Meta::fields());
    }

    // ------------------------------------------------------------------ activity, links, system

    public static function activity(): void
    {
        $a = self::admin();
        $f = ['user_id' => Http::int('user_id'), 'user' => Http::str('user'), 'action' => Http::str('action'), 'status' => Http::str('status'), 'client' => Http::str('client'), 'ip' => Http::str('ip'), 'path' => Http::str('path'), 'owner_id' => Http::int('owner_id'), 'from' => Http::str('from'), 'to' => Http::str('to'), 'limit' => Http::int('limit', 100), 'offset' => Http::int('offset', 0)];
        if (!Users::isSuper($a)) $f['managed_by'] = (int) $a['id'];
        $res = Activity::query($f);
        $res['actions'] = Activity::ACTIONS;
        Http::ok($res);
    }

    public static function activity_csv(): void
    {
        $a = self::admin();
        $f = ['user' => Http::str('user'), 'action' => Http::str('action'), 'status' => Http::str('status'), 'client' => Http::str('client'), 'from' => Http::str('from'), 'to' => Http::str('to')];
        if (!Users::isSuper($a)) $f['managed_by'] = (int) $a['id'];
        Http::sendBlob(Activity::csv($f), 'activity-' . date('Ymd-His') . '.csv', 'text/csv', false);
    }

    public static function links(): void
    {
        $a = self::admin();
        $all = Links::all(['owner_id' => Http::int('owner_id'), 'q' => Http::str('q')]);
        if (!Users::isSuper($a)) {
            $mine = array_flip(array_map('intval', DB::col('SELECT id FROM {{users}} WHERE created_by = ? OR id = ?', [(int) $a['id'], (int) $a['id']])));
            $all = array_values(array_filter($all, static fn($l) => isset($mine[$l['owner_id']])));
        }
        Http::ok(['items' => $all, 'links_enabled' => Settings::bool('links_enabled')]);
    }

    public static function links_toggle_all(): void
    {
        Auth::requireSuper();
        Links::disableAll(Http::bool('disable', true));
        Activity::log('settings_update', ['details' => ['links_enabled' => !Http::bool('disable', true)]]);
        Http::ok(['links_enabled' => Settings::bool('links_enabled')]);
    }

    public static function link_delete(): void
    {
        $a = self::admin();
        $l = Links::find(Http::int('id'));
        if ($l) {
            self::target((int) $l['owner_id'], $a);
            DB::delete('weblinks', 'id = ?', [(int) $l['id']]);
            Activity::log('link_delete', ['owner_id' => (int) $l['owner_id'], 'path' => $l['path'], 'details' => ['by_admin' => true]]);
        }
        Http::ok(null);
    }

    public static function shares(): void
    {
        $a = self::admin();
        $rows = DB::all('SELECT s.*, o.username AS owner_username, u.username AS target_username, u.name AS target_name, u.email AS target_email, u.role AS target_role, g.name AS group_name, c.username AS created_by_name FROM {{shares}} s JOIN {{users}} o ON o.id = s.owner_id LEFT JOIN {{users}} u ON s.target_type = \'user\' AND u.id = s.target_id LEFT JOIN {{groups}} g ON s.target_type = \'group\' AND g.id = s.target_id LEFT JOIN {{users}} c ON c.id = s.created_by ' . (Users::isSuper($a) ? '' : 'WHERE o.created_by = ? OR o.id = ? ') . 'ORDER BY s.created_at DESC LIMIT 2000', Users::isSuper($a) ? [] : [(int) $a['id'], (int) $a['id']]);
        Http::ok(['items' => array_map(static fn($s) => Shares::format($s) + ['owner_username' => $s['owner_username']], $rows)]);
    }

    public static function share_delete(): void
    {
        $a = self::admin();
        $s = Shares::find(Http::int('id'));
        if ($s) {
            self::target((int) $s['owner_id'], $a);
            DB::delete('shares', 'id = ?', [(int) $s['id']]);
            Activity::log('share_delete', ['owner_id' => (int) $s['owner_id'], 'path' => $s['path'], 'details' => ['by_admin' => true]]);
        }
        Http::ok(null);
    }

    public static function system(): void
    {
        Auth::requireSuper();
        $bins = [];
        foreach (['gm', 'convert', 'ffmpeg', 'ffprobe', 'pdftoppm', 'pdftotext', 'gs', 'tesseract', '7z', 'unrar'] as $b) $bins[$b] = Util::bin($b) !== null;
        $data = fr_data_dir();
        $counts = [
            'users' => (int) DB::val('SELECT COUNT(*) FROM {{users}}'), 'active_users' => (int) DB::val('SELECT COUNT(*) FROM {{users}} WHERE active = 1'), 'guests' => (int) DB::val("SELECT COUNT(*) FROM {{users}} WHERE role = 'guest'"),
            'groups' => (int) DB::val('SELECT COUNT(*) FROM {{groups}}'), 'files' => (int) DB::val('SELECT COUNT(*) FROM {{file_index}} WHERE is_dir = 0'), 'folders' => (int) DB::val('SELECT COUNT(*) FROM {{file_index}} WHERE is_dir = 1'),
            'indexed_text' => (int) DB::val('SELECT COUNT(*) FROM {{file_text}}'), 'shares' => (int) DB::val('SELECT COUNT(*) FROM {{shares}}'), 'links' => (int) DB::val('SELECT COUNT(*) FROM {{weblinks}} WHERE active = 1'),
            'activity' => (int) DB::val('SELECT COUNT(*) FROM {{activity}}'), 'sessions' => (int) DB::val('SELECT COUNT(*) FROM {{sessions}} WHERE expires_at > ?', [fr_now()]), 'trash_bytes' => (int) DB::val('SELECT COALESCE(SUM(size),0) FROM {{trash}}'),
            'versions_bytes' => (int) DB::val('SELECT COALESCE(SUM(size),0) FROM {{versions}}'), 'used_bytes' => (int) DB::val('SELECT COALESCE(SUM(used_bytes),0) FROM {{users}}'), 'jobs_pending' => Jobs::pending(),
        ];
        Http::ok([
            'version' => FR_VERSION, 'php' => PHP_VERSION, 'sapi' => PHP_SAPI, 'os' => PHP_OS_FAMILY . ' ' . php_uname('r'), 'db' => (string) DB::val('SELECT VERSION()'), 'server_time' => gmdate('c'),
            'extensions' => ['gd' => extension_loaded('gd'), 'zip' => class_exists('ZipArchive'), 'curl' => extension_loaded('curl'), 'mbstring' => extension_loaded('mbstring'), 'fileinfo' => function_exists('finfo_open'), 'openssl' => extension_loaded('openssl'), 'exif' => function_exists('exif_read_data'), 'argon2id' => defined('PASSWORD_ARGON2ID'), 'opcache' => function_exists('opcache_get_status')],
            'binaries' => $bins, 'data_dir' => $data, 'data_dir_writable' => is_writable($data), 'disk_free' => @disk_free_space($data) ?: 0, 'disk_total' => @disk_total_space($data) ?: 0,
            'php_limits' => ['upload_max_filesize' => ini_get('upload_max_filesize'), 'post_max_size' => ini_get('post_max_size'), 'memory_limit' => ini_get('memory_limit'), 'max_execution_time' => ini_get('max_execution_time'), 'chunk_size' => Upload::chunkSize()],
            'cron_last_run' => Settings::get('cron_last_run', ''), 'cron_command' => Installer::cronCommand(), 'counts' => $counts, 'base_path' => FR_BASE_PATH, 'public_url' => fr_public_url('/'), 'webdav_url' => fr_public_url('/dav/'),
            'log_tail' => is_file($data . '/logs/app.log') ? implode("\n", array_slice(file($data . '/logs/app.log') ?: [], -40)) : '',
        ]);
    }

    public static function run_jobs(): void
    {
        Auth::requireSuper();
        Http::ok(['processed' => Jobs::run(20, 50), 'pending' => Jobs::pending()]);
    }

    public static function run_cron(): void
    {
        Auth::requireSuper();
        Http::ok(Installer::runMaintenance(20));
    }
}
