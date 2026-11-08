<?php
declare(strict_types=1);

/**
 * First-run installer (web wizard at /setup and the CLI in bin/install.php),
 * configuration writer and the maintenance routine run by cron.
 */
final class Installer
{
    public static function requirements(): array
    {
        $data = fr_config('data_dir', FR_ROOT_DIR . '/data');
        return [
            ['PHP 8.1 or newer', version_compare(PHP_VERSION, '8.1.0', '>='), PHP_VERSION],
            ['PDO MySQL', extension_loaded('pdo_mysql'), ''],
            ['mbstring', extension_loaded('mbstring'), ''],
            ['json', function_exists('json_encode'), ''],
            ['openssl', extension_loaded('openssl'), ''],
            ['GD (thumbnails)', extension_loaded('gd'), 'optional'],
            ['zip (archives)', class_exists('ZipArchive'), 'optional'],
            ['curl (SSO, office editors)', extension_loaded('curl'), 'optional'],
            ['fileinfo', function_exists('finfo_open'), 'optional'],
            ['Writable application folder (for config.php)', is_writable(FR_ROOT_DIR), FR_ROOT_DIR],
        ];
    }

    /** Create schema, config, data folders and the first administrator. */
    public static function install(array $o): array
    {
        $db = ['host' => (string) ($o['db_host'] ?? '127.0.0.1'), 'port' => (int) ($o['db_port'] ?? 3306), 'name' => (string) ($o['db_name'] ?? ''), 'user' => (string) ($o['db_user'] ?? ''), 'pass' => (string) ($o['db_pass'] ?? ''), 'prefix' => (string) ($o['db_prefix'] ?? 'fr_'), 'socket' => (string) ($o['db_socket'] ?? '')];
        if ($db['name'] === '' || $db['user'] === '') throw new RuntimeException('Database name and user are required');
        if (!preg_match('/^[a-zA-Z0-9_]*$/', $db['prefix'])) throw new RuntimeException('Invalid table prefix');
        $dataDir = rtrim((string) ($o['data_dir'] ?? ''), '/\\') ?: FR_ROOT_DIR . '/data';
        $adminUser = trim((string) ($o['admin_user'] ?? 'admin'));
        $adminPass = (string) ($o['admin_pass'] ?? '');
        $adminEmail = trim((string) ($o['admin_email'] ?? ''));
        if (!Users::validUsername($adminUser)) throw new RuntimeException('Invalid administrator username');
        if (strlen($adminPass) < 8) throw new RuntimeException('Administrator password must have at least 8 characters');
        if ($adminEmail !== '' && !filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Invalid administrator e-mail');

        // 1. Database
        try {
            DB::connect($db);
        } catch (Throwable $e) {
            throw new RuntimeException('Cannot connect to the database: ' . $e->getMessage());
        }
        // 2. Data folders
        foreach (['', 'users', 'versions', 'trash', 'thumbs', 'temp', 'logs', 'branding'] as $sub) {
            $d = $sub === '' ? $dataDir : $dataDir . '/' . $sub;
            if (!is_dir($d) && !@mkdir($d, 0750, true)) throw new RuntimeException('Cannot create data folder ' . $d);
        }
        if (!is_writable($dataDir)) throw new RuntimeException('Data folder is not writable: ' . $dataDir);
        // Protect the data folder if it happens to be inside a web root.
        @file_put_contents($dataDir . '/.htaccess', "Require all denied\n");
        @file_put_contents($dataDir . '/index.html', '');
        // 3. Config
        $config = [
            'db' => $db,
            'data_dir' => $dataDir,
            'secret' => bin2hex(random_bytes(32)),
            'public_url' => rtrim((string) ($o['public_url'] ?? ''), '/') ?: null,
            'debug' => false,
            'trusted_proxies' => [],
            'asset_url' => null,
            'binaries' => [],
        ];
        $php = "<?php\n// FileRun Web configuration. Keep this file private.\nreturn " . var_export($config, true) . ";\n";
        $file = $GLOBALS['FR_CONFIG_FILE'] ?? (FR_ROOT_DIR . '/config.php');
        if (@file_put_contents($file, $php, LOCK_EX) === false) throw new RuntimeException('Cannot write ' . $file);
        @chmod($file, 0640);
        $GLOBALS['FR_CONFIG'] = $config;
        // 4. Schema & settings
        Schema::migrate();
        Settings::set('installed_at', fr_now());
        if (!empty($o['app_name'])) Settings::set('app_name', mb_substr(trim((string) $o['app_name']), 0, 100));
        if ($adminEmail !== '') Settings::set('mail_from', 'no-reply@' . (parse_url((string) ($config['public_url'] ?? ''), PHP_URL_HOST) ?: 'localhost'));
        // 5. Administrator
        if (!Users::findByUsername($adminUser)) {
            $admin = Users::create(['username' => $adminUser, 'email' => $adminEmail ?: null, 'password' => $adminPass, 'name' => (string) ($o['admin_name'] ?? 'Administrator'), 'role' => 'superuser', 'quota_bytes' => 0, 'perms' => ['share_links' => true, 'share_guests' => true, 'file_history' => true]], null);
            Users::ensureHome($admin);
            // A friendly first folder with a welcome note.
            $home = Users::homeDir($admin);
            if (!file_exists($home . '/Welcome.md')) {
                @file_put_contents($home . '/Welcome.md', "# Welcome to " . Settings::get('app_name') . "\n\nThis is your home folder. Upload files by dragging them into the browser, create folders, share them with other accounts or through links, and connect any WebDAV client to **" . fr_public_url('/dav/') . "**.\n\nOpen the control panel from your avatar menu to add users, groups and change settings.\n");
                Index::rescan($admin);
            }
            Activity::log('user_create', ['user' => $admin, 'system' => true, 'details' => ['username' => $adminUser, 'installer' => true]]);
        }
        return ['ok' => true, 'config' => $file, 'data_dir' => $dataDir, 'admin' => $adminUser];
    }

    public static function cronCommand(): string
    {
        $php = PHP_BINARY && !str_contains(PHP_BINARY, 'php-fpm') ? PHP_BINARY : 'php';
        return '* * * * * ' . $php . ' ' . FR_APP_DIR . '/cron.php >/dev/null 2>&1';
    }

    /** Everything the cron job does; also callable from the control panel. */
    public static function runMaintenance(int $budgetSeconds = 50): array
    {
        $start = time();
        $r = [];
        Activity::$client = 'cron';
        $r['jobs'] = Jobs::run(max(5, $budgetSeconds - 10), 500);
        $r['trash_purged'] = Storage::trashAutoPurge();
        $r['uploads_purged'] = Upload::purgeStale();
        $r['sessions_purged'] = DB::delete('sessions', 'expires_at < ?', [fr_now()]);
        $r['tokens_purged'] = DB::delete('tokens', 'expires_at < ?', [gmdate('Y-m-d H:i:s', time() - 86400)]);
        $r['attempts_purged'] = DB::delete('login_attempts', 'last_attempt_at < ?', [gmdate('Y-m-d H:i:s', time() - 86400)]);
        $r['activity_purged'] = Activity::purgeOld();
        $r['notifications_purged'] = Notify::purgeOld();
        $r['links_purged'] = Links::purgeExpired();
        $r['guests_removed'] = Users::cleanupGuests();
        $r['inactive'] = self::handleInactiveAccounts();
        $r['expired_accounts'] = self::handleExpiredAccounts();
        // Heavier, less frequent work.
        $lastDaily = (string) Settings::get('cron_last_daily', '');
        if ($lastDaily === '' || time() - strtotime($lastDaily) > 86400) {
            $r['thumbs_purged'] = Thumbs::purgeOld(60);
            foreach (DB::all('SELECT * FROM {{users}} WHERE active = 1 ORDER BY used_calc_at IS NULL DESC, used_calc_at ASC LIMIT 20') as $u) {
                if (time() - $start > $budgetSeconds) break;
                Users::recalcUsage($u);
            }
            Settings::set('cron_last_daily', fr_now());
        }
        Settings::set('cron_last_run', fr_now());
        $r['seconds'] = time() - $start;
        return $r;
    }

    private static function handleInactiveAccounts(): array
    {
        $deact = Settings::int('inactive_deactivate_days');
        $del = Settings::int('inactive_delete_days');
        $out = ['deactivated' => 0, 'deleted' => 0];
        if ($deact > 0) {
            $cut = gmdate('Y-m-d H:i:s', time() - $deact * 86400);
            foreach (DB::all("SELECT * FROM {{users}} WHERE active = 1 AND role <> 'superuser' AND COALESCE(last_activity_at, last_login_at, created_at) < ?", [$cut]) as $u) {
                DB::update('users', ['active' => 0, 'deactivated_reason' => 'inactive for ' . $deact . ' days'], 'id = ?', [(int) $u['id']]);
                Users::forget((int) $u['id']);
                Activity::log('user_deactivate', ['system' => true, 'details' => ['user_id' => (int) $u['id'], 'username' => $u['username'], 'reason' => 'inactivity']]);
                $out['deactivated']++;
            }
        }
        if ($del > 0) {
            $cut = gmdate('Y-m-d H:i:s', time() - $del * 86400);
            foreach (DB::all("SELECT * FROM {{users}} WHERE active = 0 AND role <> 'superuser' AND deactivated_reason LIKE 'inactive%' AND COALESCE(last_activity_at, last_login_at, created_at) < ?", [$cut]) as $u) {
                Activity::log('user_delete', ['system' => true, 'details' => ['user_id' => (int) $u['id'], 'username' => $u['username'], 'reason' => 'inactivity']]);
                Users::delete((int) $u['id'], true);
                $out['deleted']++;
            }
        }
        return $out;
    }

    private static function handleExpiredAccounts(): int
    {
        $n = 0;
        foreach (DB::all("SELECT * FROM {{users}} WHERE active = 1 AND expires_at IS NOT NULL AND expires_at < ?", [fr_now()]) as $u) {
            DB::update('users', ['active' => 0, 'deactivated_reason' => 'account end date reached'], 'id = ?', [(int) $u['id']]);
            Users::forget((int) $u['id']);
            Activity::log('user_deactivate', ['system' => true, 'details' => ['user_id' => (int) $u['id'], 'username' => $u['username'], 'reason' => 'expired']]);
            $n++;
        }
        return $n;
    }

    // ------------------------------------------------------------------ web wizard

    public static function handleWeb(string $path): never
    {
        if (Http::method() === 'POST' && $path === '/setup') {
            try {
                $res = self::install($_POST);
                Http::redirect(fr_url('/?installed=1'));
            } catch (Throwable $e) {
                self::page($e->getMessage(), $_POST);
            }
        }
        self::page(null, []);
    }

    private static function page(?string $error, array $v): never
    {
        $e = static fn($k, $d = '') => Http::escape((string) ($v[$k] ?? $d));
        $rows = '';
        foreach (self::requirements() as [$label, $ok, $note]) {
            $rows .= '<li class="' . ($ok ? 'ok' : ($note === 'optional' ? 'warn' : 'bad')) . '">' . ($ok ? '✔' : ($note === 'optional' ? '•' : '✖')) . ' ' . Http::escape($label) . ($note && $note !== 'optional' ? ' <small>' . Http::escape($note) . '</small>' : '') . ($note === 'optional' && !$ok ? ' <small>(optional)</small>' : '') . '</li>';
        }
        $guessUrl = fr_public_url('/');
        $guessData = dirname(FR_ROOT_DIR) . '/filerun-data';
        $html = '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Install FileRun Web</title><style>
body{font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;background:#f3f4f6;color:#111827;margin:0;padding:32px 16px}main{max-width:720px;margin:0 auto;background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:32px 36px}h1{margin:0 0 6px;font-size:24px}p.lead{color:#6b7280;margin-top:0}h2{font-size:15px;text-transform:uppercase;letter-spacing:.06em;color:#6b7280;margin:28px 0 12px}ul{list-style:none;padding:0;margin:0;columns:2}li{padding:4px 0;font-size:14px}li.bad{color:#b91c1c}li.warn{color:#92400e}li.ok{color:#065f46}small{color:#6b7280}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:12px 16px}label{display:block;font-size:13px;font-weight:600;margin-bottom:4px}input{width:100%;box-sizing:border-box;padding:9px 11px;border:1px solid #d1d5db;border-radius:8px;font-size:14px}input:focus{outline:2px solid #2563eb;border-color:transparent}.full{grid-column:1/-1}button{margin-top:24px;background:#2563eb;color:#fff;border:0;border-radius:8px;padding:12px 22px;font-size:15px;font-weight:600;cursor:pointer}.err{background:#fef2f2;border:1px solid #fecaca;color:#991b1b;padding:10px 14px;border-radius:8px;margin:16px 0}.hint{font-size:12px;color:#6b7280;margin-top:4px}
</style></head><body><main><h1>Install FileRun Web</h1><p class="lead">A few details and you are done. Everything can be changed later in the control panel.</p>'
            . ($error ? '<div class="err">' . Http::escape($error) . '</div>' : '')
            . '<h2>Server check</h2><ul>' . $rows . '</ul>'
            . '<form method="post" action="' . Http::escape(fr_url('/setup')) . '"><h2>Database (MySQL / MariaDB)</h2><div class="grid">'
            . '<div><label>Host</label><input name="db_host" value="' . $e('db_host', '127.0.0.1') . '"></div><div><label>Port</label><input name="db_port" value="' . $e('db_port', '3306') . '"></div>'
            . '<div><label>Database name</label><input name="db_name" value="' . $e('db_name') . '" required></div><div><label>Table prefix</label><input name="db_prefix" value="' . $e('db_prefix', 'fr_') . '"></div>'
            . '<div><label>User</label><input name="db_user" value="' . $e('db_user') . '" required></div><div><label>Password</label><input name="db_pass" type="password" value="' . $e('db_pass') . '"></div>'
            . '<div class="full"><label>Socket (optional)</label><input name="db_socket" value="' . $e('db_socket') . '" placeholder="/var/run/mysqld/mysqld.sock"></div></div>'
            . '<h2>Files</h2><div class="grid"><div class="full"><label>Data folder</label><input name="data_dir" value="' . $e('data_dir', $guessData) . '"><div class="hint">User files, versions, trash and thumbnails are stored here. Keep it outside the web root.</div></div>'
            . '<div class="full"><label>Public URL</label><input name="public_url" value="' . $e('public_url', rtrim($guessUrl, '/')) . '"><div class="hint">Used in e-mails and shared links.</div></div>'
            . '<div class="full"><label>Application name</label><input name="app_name" value="' . $e('app_name', 'FileRun Web') . '"></div></div>'
            . '<h2>Administrator</h2><div class="grid"><div><label>Username</label><input name="admin_user" value="' . $e('admin_user', 'admin') . '" required></div><div><label>E-mail</label><input name="admin_email" type="email" value="' . $e('admin_email') . '"></div>'
            . '<div><label>Password</label><input name="admin_pass" type="password" required minlength="8"></div><div><label>Display name</label><input name="admin_name" value="' . $e('admin_name', 'Administrator') . '"></div></div>'
            . '<button type="submit">Install</button></form></main></body></html>';
        Http::html($html, $error ? 400 : 200);
    }
}
