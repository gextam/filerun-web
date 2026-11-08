<?php
declare(strict_types=1);

/**
 * Authentication: password sign-in with throttling, two-factor steps (TOTP,
 * e-mailed codes, recovery codes), passkeys (via WebAuthn), remember-me,
 * database-backed sessions, app passwords / API tokens and HTTP Basic for WebDAV.
 */
final class Auth
{
    public const COOKIE = 'fr_session';
    public const CSRF_HEADER = 'HTTP_X_REQUESTED_WITH';

    private static ?array $user = null;
    private static ?array $session = null;
    private static bool $resolved = false;
    /** How the current request authenticated: cookie | token | basic | link | none */
    public static string $method = 'none';
    public static ?array $appPassword = null;

    // ------------------------------------------------------------------
    // Passwords
    // ------------------------------------------------------------------

    public static function hashPassword(string $password): string
    {
        if (defined('PASSWORD_ARGON2ID')) {
            return password_hash($password, PASSWORD_ARGON2ID, ['memory_cost' => 65536, 'time_cost' => 3, 'threads' => 1]);
        }
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    public static function verifyPassword(string $password, ?string $hash): bool
    {
        return $hash !== null && $hash !== '' && password_verify($password, $hash);
    }

    public static function validatePasswordStrength(string $password): void
    {
        $min = max(4, Settings::int('password_min_length'));
        if (mb_strlen($password) < $min) {
            throw new RuntimeException("Password must be at least $min characters long");
        }
        if (Settings::bool('password_require_mixed') && !(preg_match('/[a-z]/', $password) && preg_match('/[A-Z]/', $password) && preg_match('/\d/', $password))) {
            throw new RuntimeException('Password must contain lower case, upper case letters and digits');
        }
    }

    // ------------------------------------------------------------------
    // Current request
    // ------------------------------------------------------------------

    /** Resolve the current user from cookie session, bearer token or basic auth. */
    public static function user(): ?array
    {
        if (self::$resolved) return self::$user;
        self::$resolved = true;

        // 1. Bearer token (API) or Basic auth (WebDAV, API clients)
        $authHeader = Http::header('Authorization');
        if ($authHeader) {
            if (preg_match('/^Bearer\s+(\S+)$/i', $authHeader, $m)) {
                $u = self::authenticateToken($m[1], 'api');
                if ($u) { self::$method = 'token'; return self::$user = $u; }
                return null;
            }
            if (preg_match('/^Basic\s+(\S+)$/i', $authHeader, $m)) {
                $decoded = base64_decode($m[1], true);
                if ($decoded !== false && str_contains($decoded, ':')) {
                    [$username, $password] = explode(':', $decoded, 2);
                    $u = self::authenticateBasic($username, $password, 'api');
                    if ($u) { self::$method = 'basic'; return self::$user = $u; }
                }
                return null;
            }
        }
        // 2. Cookie session
        $token = $_COOKIE[self::COOKIE] ?? '';
        if ($token !== '') {
            $sess = DB::one('SELECT * FROM {{sessions}} WHERE id = ?', [hash('sha256', $token)]);
            if ($sess && strtotime((string) $sess['expires_at']) > time() && !$sess['pending_2fa']) {
                $u = Users::find((int) $sess['user_id']);
                if ($u && $u['active'] && self::accountUsable($u) === null) {
                    self::$session = $sess;
                    self::$method = 'cookie';
                    self::touch($sess, $u);
                    return self::$user = $u;
                }
            }
            if (!$sess || strtotime((string) $sess['expires_at']) <= time()) {
                Http::deleteCookie(self::COOKIE);
            }
        }
        return null;
    }

    /** Make an already authenticated user (WebDAV basic auth, office callbacks) the current one. */
    public static function adopt(array $u, string $method = 'basic'): void
    {
        self::$user = $u;
        self::$resolved = true;
        self::$method = $method;
    }

    public static function session(): ?array
    {
        self::user();
        return self::$session;
    }

    /** The pending (password OK, second factor missing) session, if any. */
    public static function pendingSession(): ?array
    {
        $token = $_COOKIE[self::COOKIE] ?? '';
        if ($token === '') return null;
        $sess = DB::one('SELECT * FROM {{sessions}} WHERE id = ? AND pending_2fa = 1', [hash('sha256', $token)]);
        if ($sess && strtotime((string) $sess['expires_at']) > time()) return $sess;
        return null;
    }

    private static function touch(array $sess, array $u): void
    {
        $last = strtotime((string) $sess['last_seen_at']) ?: 0;
        if (time() - $last > 60) {
            $exp = $sess['remember'] ? time() + 86400 * max(1, Settings::int('remember_me_days')) : time() + 3600 * max(1, Settings::int('session_lifetime_hours'));
            DB::update('sessions', ['last_seen_at' => fr_now(), 'expires_at' => gmdate('Y-m-d H:i:s', $exp), 'ip' => Http::ip()], 'id = ?', [$sess['id']]);
            DB::update('users', ['last_activity_at' => fr_now()], 'id = ?', [(int) $u['id']]);
        }
    }

    public static function requireUser(): array
    {
        $u = self::user();
        if (!$u) {
            Http::fail('Authentication required', 401, 'unauthenticated');
        }
        return $u;
    }

    public static function requireAdmin(): array
    {
        $u = self::requireUser();
        if (!Users::isAdmin($u)) Http::fail('Administrator access required', 403, 'forbidden');
        return $u;
    }

    public static function requireSuper(): array
    {
        $u = self::requireUser();
        if (!Users::isSuper($u)) Http::fail('Superuser access required', 403, 'forbidden');
        return $u;
    }

    /** Reject cross-site requests on state-changing cookie-authenticated calls. */
    public static function csrfCheck(): void
    {
        if (self::$method !== 'cookie') return;
        if (in_array(Http::method(), ['GET', 'HEAD', 'OPTIONS'], true)) return;
        if (($_SERVER[self::CSRF_HEADER] ?? '') !== 'XMLHttpRequest') {
            Http::fail('Missing request header', 403, 'csrf');
        }
        $origin = $_SERVER['HTTP_ORIGIN'] ?? ($_SERVER['HTTP_REFERER'] ?? '');
        if ($origin !== '') {
            $host = parse_url($origin, PHP_URL_HOST);
            $self = $_SERVER['HTTP_HOST'] ?? '';
            if ($host && $self && strcasecmp($host, explode(':', $self)[0]) !== 0) {
                Http::fail('Cross-site request rejected', 403, 'csrf');
            }
        }
    }

    // ------------------------------------------------------------------
    // Sign-in flow
    // ------------------------------------------------------------------

    /** Reason an account cannot be used right now, or null when it can. */
    public static function accountUsable(array $u): ?string
    {
        if (!$u['active']) return $u['deactivated_reason'] ? 'Account deactivated: ' . $u['deactivated_reason'] : 'Account deactivated';
        if ($u['expires_at'] && strtotime((string) $u['expires_at']) < time()) return 'Account expired';
        if (!Util::ipAllowed(Http::ip(), $u['ip_rules'] ?? null)) return 'Sign-in is not allowed from your network address';
        if (!Settings::bool('web_enabled') && self::$method === 'cookie' && !Users::isAdmin($u)) return 'Web access is disabled';
        return null;
    }

    /** Throttle key check. Throws with a user-facing message when too early. */
    private static function throttleCheck(string $key): void
    {
        if (!Settings::bool('login_throttle')) return;
        $row = DB::one('SELECT * FROM {{login_attempts}} WHERE `key` = ?', [$key]);
        if (!$row) return;
        $attempts = (int) $row['attempts'];
        $last = strtotime((string) $row['last_attempt_at']) ?: 0;
        if ($attempts >= 10) {
            $wait = 1800 - (time() - $last);
            if ($wait > 0) {
                throw new RuntimeException('Too many failed attempts. Try again in ' . max(1, (int) ceil($wait / 60)) . ' minutes.');
            }
            return;
        }
        $delay = min($attempts, 8);
        if ($attempts > 0 && time() - $last < $delay) {
            throw new RuntimeException('Please wait ' . ($delay - (time() - $last)) . ' seconds before trying again.');
        }
    }

    private static function throttleFail(string $key): void
    {
        DB::q('INSERT INTO {{login_attempts}} (`key`, attempts, last_attempt_at) VALUES (?, 1, ?) ON DUPLICATE KEY UPDATE attempts = attempts + 1, last_attempt_at = VALUES(last_attempt_at)', [$key, fr_now()]);
    }

    private static function throttleClear(string ...$keys): void
    {
        foreach ($keys as $k) DB::delete('login_attempts', '`key` = ?', [$k]);
    }

    /**
     * Step one of signing in. Returns an array describing what happens next:
     *  ['status' => 'ok']                              signed in
     *  ['status' => '2fa', 'methods' => [...], 'email_hint' => '...']   second factor required
     */
    public static function login(string $username, string $password, bool $remember = false): array
    {
        $username = trim($username);
        $ipKey = 'ip:' . Http::ip();
        $userKey = 'u:' . mb_strtolower($username);
        self::throttleCheck($ipKey);
        self::throttleCheck($userKey);

        $u = Users::findByUsername($username);
        if (!$u || !self::verifyPassword($password, $u['password_hash'])) {
            self::throttleFail($ipKey);
            self::throttleFail($userKey);
            Activity::log('login', ['status' => 'failed', 'reason' => $u ? 'wrong password' : 'unknown user', 'user' => $u, 'details' => ['username' => $username]]);
            throw new RuntimeException('Invalid username or password');
        }
        if ($reason = self::accountUsable($u)) {
            Activity::log('login', ['status' => 'failed', 'reason' => $reason, 'user' => $u]);
            throw new RuntimeException($reason);
        }
        if (password_needs_rehash($u['password_hash'], defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT)) {
            DB::update('users', ['password_hash' => self::hashPassword($password)], 'id = ?', [(int) $u['id']]);
        }
        self::throttleClear($ipKey, $userKey);

        $methods = self::secondFactorMethods($u);
        if ($methods) {
            $sess = self::createSession($u, $remember, true);
            if (in_array('email', $methods, true) && !in_array('totp', $methods, true)) {
                self::sendEmailCode($u);
            }
            Activity::log('login', ['status' => 'ok', 'user' => $u, 'details' => ['step' => 'password', 'second_factor' => $methods]]);
            return ['status' => '2fa', 'methods' => $methods, 'email_hint' => self::maskEmail((string) $u['email']), 'passkeys' => (int) DB::val('SELECT COUNT(*) FROM {{passkeys}} WHERE user_id = ?', [(int) $u['id']])];
        }
        self::createSession($u, $remember, false);
        self::afterLogin($u);
        return ['status' => 'ok'];
    }

    /** Which second factors apply to this account (empty = none). */
    public static function secondFactorMethods(array $u): array
    {
        $m = [];
        if (!empty($u['two_factor_secret'])) $m[] = 'totp';
        if ($u['two_factor_email'] && !empty($u['email']) && Settings::get('mail_transport') !== 'disabled') $m[] = 'email';
        if (Settings::bool('two_factor_required') && !$m && !empty($u['email'])) $m[] = 'email';
        if ($m && !empty($u['recovery_codes'])) $m[] = 'recovery';
        return $m;
    }

    public static function maskEmail(string $email): string
    {
        if (!str_contains($email, '@')) return '';
        [$l, $d] = explode('@', $email, 2);
        return mb_substr($l, 0, 2) . str_repeat('*', max(1, mb_strlen($l) - 2)) . '@' . $d;
    }

    public static function sendEmailCode(array $u): void
    {
        if (empty($u['email'])) throw new RuntimeException('No e-mail address on this account');
        $recent = (int) DB::val("SELECT COUNT(*) FROM {{tokens}} WHERE user_id = ? AND type = 'email_2fa' AND created_at > ?", [(int) $u['id'], gmdate('Y-m-d H:i:s', time() - 60)]);
        if ($recent > 0) return; // one code per minute
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        DB::delete('tokens', "user_id = ? AND type = 'email_2fa'", [(int) $u['id']]);
        DB::insert('tokens', ['user_id' => (int) $u['id'], 'type' => 'email_2fa', 'token_hash' => hash('sha256', $code . '|' . $u['id']), 'expires_at' => gmdate('Y-m-d H:i:s', time() + 600), 'created_at' => fr_now()]);
        Mailer::send((string) $u['email'], 'Your sign-in code', Mailer::template('Your sign-in code', '<p>Use this code to finish signing in to ' . Http::escape((string) Settings::get('app_name')) . ':</p><p style="font-size:28px;letter-spacing:6px;font-weight:bold">' . $code . '</p><p>The code is valid for 10 minutes. If you did not try to sign in, change your password.</p>'));
    }

    /** Step two: verify a TOTP / e-mailed / recovery code on the pending session. */
    public static function verifySecondFactor(string $code, string $method = 'auto'): array
    {
        $sess = self::pendingSession();
        if (!$sess) throw new RuntimeException('No sign-in in progress');
        $u = Users::find((int) $sess['user_id']);
        if (!$u) throw new RuntimeException('Account not found');
        $code = trim($code);
        $ok = false;
        $used = '';
        if (($method === 'auto' || $method === 'totp') && !empty($u['two_factor_secret']) && Totp::verify($u['two_factor_secret'], $code)) {
            $ok = true; $used = 'totp';
        }
        if (!$ok && ($method === 'auto' || $method === 'email')) {
            $row = DB::one("SELECT * FROM {{tokens}} WHERE user_id = ? AND type = 'email_2fa' AND token_hash = ? AND used_at IS NULL AND expires_at > ?", [(int) $u['id'], hash('sha256', $code . '|' . $u['id']), fr_now()]);
            if ($row) {
                DB::update('tokens', ['used_at' => fr_now()], 'id = ?', [(int) $row['id']]);
                $ok = true; $used = 'email';
            }
        }
        if (!$ok && ($method === 'auto' || $method === 'recovery') && !empty($u['recovery_codes'])) {
            $codes = Util::jsonDecode($u['recovery_codes'], []);
            $h = hash('sha256', strtolower($code));
            $idx = array_search($h, $codes, true);
            if ($idx !== false) {
                unset($codes[$idx]);
                DB::update('users', ['recovery_codes' => Util::json(array_values($codes))], 'id = ?', [(int) $u['id']]);
                $ok = true; $used = 'recovery';
            }
        }
        if (!$ok) {
            $failed = (int) $u['failed_2fa'] + 1;
            $row = ['failed_2fa' => $failed];
            if ($failed >= 5) {
                $row['active'] = 0;
                $row['deactivated_reason'] = 'five failed two-factor attempts';
                Activity::log('user_deactivate', ['status' => 'ok', 'user' => $u, 'system' => true, 'details' => ['reason' => 'five failed two-factor attempts']]);
                Notify::admins('Account deactivated', 'The account ' . $u['username'] . ' was deactivated after five failed two-factor attempts.', ['user_id' => (int) $u['id']]);
            }
            DB::update('users', $row, 'id = ?', [(int) $u['id']]);
            Users::forget((int) $u['id']);
            Activity::log('login', ['status' => 'failed', 'reason' => 'wrong second factor', 'user' => $u]);
            throw new RuntimeException($failed >= 5 ? 'Account deactivated after five failed attempts' : 'Invalid code');
        }
        DB::update('users', ['failed_2fa' => 0], 'id = ?', [(int) $u['id']]);
        DB::update('sessions', ['pending_2fa' => 0], 'id = ?', [$sess['id']]);
        Users::forget((int) $u['id']);
        self::afterLogin(Users::find((int) $u['id']), $used);
        return ['status' => 'ok'];
    }

    /** Create a session row and set the cookie. */
    public static function createSession(array $u, bool $remember, bool $pending): array
    {
        $token = bin2hex(random_bytes(32));
        $id = hash('sha256', $token);
        $exp = $pending ? time() + 900 : ($remember ? time() + 86400 * max(1, Settings::int('remember_me_days')) : time() + 3600 * max(1, Settings::int('session_lifetime_hours')));
        $row = [
            'id' => $id, 'user_id' => (int) $u['id'], 'ip' => Http::ip(), 'user_agent' => Http::userAgent(), 'client' => Util::clientName(Http::userAgent()),
            'remember' => $remember ? 1 : 0, 'pending_2fa' => $pending ? 1 : 0, 'created_at' => fr_now(), 'last_seen_at' => fr_now(), 'expires_at' => gmdate('Y-m-d H:i:s', $exp),
        ];
        DB::insert('sessions', $row);
        Http::setCookie(self::COOKIE, $token, $remember && !$pending ? $exp : 0);
        self::$session = $row;
        return $row;
    }

    private static function afterLogin(array $u, string $factor = ''): void
    {
        DB::update('users', ['last_login_at' => fr_now(), 'last_activity_at' => fr_now()], 'id = ?', [(int) $u['id']]);
        Users::forget((int) $u['id']);
        self::$user = $u;
        self::$resolved = true;
        self::$method = 'cookie';
        Activity::log('login', ['status' => 'ok', 'user' => $u, 'details' => $factor ? ['second_factor' => $factor] : []]);
        if (random_int(1, 20) === 1) {
            DB::delete('sessions', 'expires_at < ?', [fr_now()]);
            DB::delete('tokens', 'expires_at < ?', [gmdate('Y-m-d H:i:s', time() - 86400)]);
        }
    }

    /** Sign in a user whose identity was proven by other means (passkey, OIDC). */
    public static function establish(array $u, bool $remember = false, string $how = 'passkey'): void
    {
        if ($reason = self::accountUsable($u)) {
            throw new RuntimeException($reason);
        }
        self::createSession($u, $remember, false);
        self::afterLogin($u, $how);
    }

    public static function logout(): void
    {
        $token = $_COOKIE[self::COOKIE] ?? '';
        if ($token !== '') {
            DB::delete('sessions', 'id = ?', [hash('sha256', $token)]);
            Http::deleteCookie(self::COOKIE);
        }
        if (self::$user) {
            Activity::log('logout', ['user' => self::$user]);
        }
        self::$user = null;
        self::$session = null;
    }

    public static function revokeAllSessions(int $userId, ?string $exceptId = null): void
    {
        if ($exceptId) {
            DB::delete('sessions', 'user_id = ? AND id <> ?', [$userId, $exceptId]);
        } else {
            DB::delete('sessions', 'user_id = ?', [$userId]);
        }
    }

    public static function sessionsOf(int $userId): array
    {
        $current = self::session()['id'] ?? null;
        return array_map(static fn($s) => [
            'id' => substr($s['id'], 0, 12), 'full_id' => $s['id'], 'ip' => $s['ip'], 'client' => $s['client'] ?: Util::clientName($s['user_agent']),
            'created_at' => $s['created_at'], 'last_seen_at' => $s['last_seen_at'], 'expires_at' => $s['expires_at'], 'remember' => (bool) $s['remember'], 'current' => $s['id'] === $current,
        ], DB::all('SELECT * FROM {{sessions}} WHERE user_id = ? AND pending_2fa = 0 ORDER BY last_seen_at DESC', [$userId]));
    }

    /** Change the user's own password: validates, rehashes, signs out other sessions. */
    public static function changePassword(array $u, string $new): void
    {
        self::validatePasswordStrength($new);
        DB::update('users', ['password_hash' => self::hashPassword($new), 'password_changed_at' => fr_now(), 'must_change_password' => 0, 'updated_at' => fr_now()], 'id = ?', [(int) $u['id']]);
        Users::forget((int) $u['id']);
        self::revokeAllSessions((int) $u['id'], self::session()['id'] ?? null);
        Activity::log('password_change', ['user' => $u]);
    }

    // ------------------------------------------------------------------
    // Password reset
    // ------------------------------------------------------------------

    public static function requestPasswordReset(string $identifier): void
    {
        $u = Users::findByUsername($identifier) ?? Users::findByEmail($identifier);
        if (!$u || empty($u['email']) || !$u['active']) {
            return; // do not reveal whether the account exists
        }
        $token = Util::token(32);
        DB::delete('tokens', "user_id = ? AND type = 'password_reset'", [(int) $u['id']]);
        DB::insert('tokens', ['user_id' => (int) $u['id'], 'type' => 'password_reset', 'token_hash' => hash('sha256', $token), 'expires_at' => gmdate('Y-m-d H:i:s', time() + 3600), 'created_at' => fr_now()]);
        $url = fr_public_url('/?reset=' . $token);
        Mailer::send((string) $u['email'], 'Reset your password', Mailer::template('Reset your password', '<p>Somebody asked to reset the password of your account <b>' . Http::escape($u['username']) . '</b>. If that was you, open the link below within one hour.</p>', $url, 'Choose a new password'));
        Activity::log('password_reset_request', ['user' => $u]);
    }

    public static function completePasswordReset(string $token, string $newPassword): array
    {
        $row = DB::one("SELECT * FROM {{tokens}} WHERE type = 'password_reset' AND token_hash = ? AND used_at IS NULL AND expires_at > ?", [hash('sha256', $token), fr_now()]);
        if (!$row) throw new RuntimeException('This reset link is invalid or has expired');
        $u = Users::find((int) $row['user_id']);
        if (!$u) throw new RuntimeException('Account not found');
        self::validatePasswordStrength($newPassword);
        DB::update('tokens', ['used_at' => fr_now()], 'id = ?', [(int) $row['id']]);
        DB::update('users', ['password_hash' => self::hashPassword($newPassword), 'password_changed_at' => fr_now(), 'must_change_password' => 0], 'id = ?', [(int) $u['id']]);
        // Resetting removes every session, app password and connected app.
        self::revokeAllSessions((int) $u['id']);
        DB::delete('app_passwords', 'user_id = ?', [(int) $u['id']]);
        Users::forget((int) $u['id']);
        Activity::log('password_reset', ['user' => $u]);
        return Users::find((int) $u['id']);
    }

    // ------------------------------------------------------------------
    // App passwords / API tokens
    // ------------------------------------------------------------------

    public static function createAppPassword(array $u, string $name, string $scope = 'all'): array
    {
        if (!in_array($scope, ['all', 'webdav', 'api'], true)) $scope = 'all';
        $plain = 'frw_' . Util::token(30);
        $id = DB::insert('app_passwords', ['user_id' => (int) $u['id'], 'name' => mb_substr(trim($name) ?: 'App password', 0, 150), 'token_hash' => hash('sha256', $plain), 'prefix' => substr($plain, 0, 10), 'scope' => $scope, 'created_at' => fr_now()]);
        Activity::log('app_password_create', ['user' => $u, 'details' => ['name' => $name, 'scope' => $scope]]);
        return ['id' => $id, 'password' => $plain, 'name' => $name, 'scope' => $scope];
    }

    public static function appPasswordsOf(int $userId): array
    {
        return DB::all('SELECT id, name, prefix, scope, last_used_at, last_ip, created_at FROM {{app_passwords}} WHERE user_id = ? ORDER BY created_at DESC', [$userId]);
    }

    /** Authenticate with an app password / API token string. */
    public static function authenticateToken(string $token, string $scope): ?array
    {
        $row = DB::one('SELECT * FROM {{app_passwords}} WHERE token_hash = ?', [hash('sha256', $token)]);
        if (!$row) return null;
        if ($row['scope'] !== 'all' && $row['scope'] !== $scope) return null;
        $u = Users::find((int) $row['user_id']);
        if (!$u || self::accountUsable($u) !== null) return null;
        if (!Users::can($u, $scope === 'webdav' ? 'webdav' : 'api')) return null;
        DB::update('app_passwords', ['last_used_at' => fr_now(), 'last_ip' => Http::ip()], 'id = ?', [(int) $row['id']]);
        self::$appPassword = $row;
        return $u;
    }

    /** HTTP Basic: app password first, then the account password when allowed. */
    public static function authenticateBasic(string $username, string $password, string $scope): ?array
    {
        $u = self::authenticateToken($password, $scope);
        if ($u) {
            if (strcasecmp($u['username'], $username) !== 0 && strcasecmp((string) $u['email'], $username) !== 0) return null;
            return $u;
        }
        $key = 'basic:' . Http::ip();
        try { self::throttleCheck($key); } catch (RuntimeException) { return null; }
        $u = Users::findByUsername($username);
        if (!$u || !self::verifyPassword($password, $u['password_hash'])) {
            self::throttleFail($key);
            return null;
        }
        if (self::accountUsable($u) !== null) return null;
        if (!Users::can($u, $scope === 'webdav' ? 'webdav' : 'api')) return null;
        $requireApp = ($scope === 'webdav' && Settings::bool('webdav_require_app_password')) || !empty($u['two_factor_secret']);
        if ($requireApp) return null; // accounts with an authenticator must use app passwords
        self::throttleClear($key);
        return $u;
    }
}
