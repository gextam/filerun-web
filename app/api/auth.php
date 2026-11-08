<?php
declare(strict_types=1);

/** /api/auth/* — signing in and out, second factors, passkeys, SSO, password reset, sign-up. */
final class AuthApi
{
    /** Everything the client needs to boot. */
    public static function status(): void
    {
        Http::ok(self::bootPayload());
    }

    public static function bootPayload(): array
    {
        $u = Auth::user();
        $pending = null;
        if (!$u) {
            $p = Auth::pendingSession();
            if ($p) {
                $pu = Users::find((int) $p['user_id']);
                if ($pu) $pending = ['methods' => Auth::secondFactorMethods($pu), 'email_hint' => Auth::maskEmail((string) $pu['email']), 'passkeys' => (int) DB::val('SELECT COUNT(*) FROM {{passkeys}} WHERE user_id = ?', [(int) $pu['id']]), 'username' => $pu['username']];
            }
        }
        $sess = Auth::session();
        $sessData = $sess ? (array) Util::jsonDecode($sess['data'] ?? null, []) : [];
        return [
            'authenticated' => $u !== null,
            'user' => $u ? Users::publicProfile($u) : null,
            'pending' => $pending,
            'settings' => Settings::publicSettings(),
            'base' => FR_BASE_PATH,
            'asset_base' => FR_ASSET_BASE,
            'public_url' => fr_public_url('/'),
            'upload' => ['chunk_size' => Upload::chunkSize(), 'php_limit' => Util::phpUploadLimit(), 'max_upload' => $u ? Users::maxUpload($u) : 0],
            'features' => [
                'webdav' => Settings::bool('webdav_enabled') && ($u === null || Users::can($u, 'webdav')),
                'webdav_url' => fr_public_url('/dav/'),
                'api' => Settings::bool('api_enabled'),
                'onlyoffice' => class_exists('OnlyOffice') && OnlyOffice::enabled(),
                'google_viewer' => Settings::bool('google_viewer_enabled'),
                'passkeys' => Settings::bool('passkeys_enabled'),
                'oidc' => class_exists('Oidc') && Oidc::enabled(),
                'ocr' => Settings::bool('ocr_enabled'),
                'labels' => Meta::LABELS,
                'metadata_fields' => $u ? Meta::fields() : [],
                'roles' => Paths::ROLE_LABELS,
                'role_descriptions' => Paths::ROLE_DESCRIPTIONS,
            ],
            'impersonated_by' => $sessData['impersonator'] ?? null,
            'server_time' => gmdate('c'),
            'version' => FR_VERSION,
        ];
    }

    public static function login(): void
    {
        if (!Settings::bool('web_enabled')) {
            $probe = Users::findByUsername(Http::str('username'));
            if (!$probe || !Users::isAdmin($probe)) throw new RuntimeException('Web access is disabled on this server');
        }
        $res = Auth::login(Http::str('username'), (string) Http::input('password', ''), Http::bool('remember', false));
        if ($res['status'] === 'ok') $res['user'] = Users::publicProfile(Auth::user());
        Http::ok($res);
    }

    public static function two_factor(): void
    {
        $res = Auth::verifySecondFactor(Http::str('code'), Http::str('method', 'auto'));
        $res['user'] = Users::publicProfile(Auth::user());
        Http::ok($res);
    }

    public static function resend_code(): void
    {
        $p = Auth::pendingSession();
        if (!$p) throw new RuntimeException('No sign-in in progress');
        $u = Users::find((int) $p['user_id']);
        if ($u) Auth::sendEmailCode($u);
        Http::ok(['sent' => true]);
    }

    public static function logout(): void
    {
        Auth::logout();
        $url = null;
        if (class_exists('Oidc') && Oidc::enabled()) {
            try { $url = Oidc::logoutUrl(); } catch (Throwable) { $url = null; }
        }
        Http::ok(['redirect' => $url]);
    }

    public static function forgot(): void
    {
        if (!Settings::bool('allow_password_reset')) throw new RuntimeException('Password reset is disabled');
        Auth::requestPasswordReset(Http::str('identifier'));
        Http::ok(['sent' => true]);
    }

    public static function reset(): void
    {
        $u = Auth::completePasswordReset(Http::str('token'), (string) Http::input('password', ''));
        Http::ok(['username' => $u['username']]);
    }

    public static function signup(): void
    {
        if (!Settings::bool('signup_enabled')) throw new PermissionException('Sign-up is disabled');
        $password = (string) Http::input('password', '');
        Auth::validatePasswordStrength($password);
        $email = trim(Http::str('email'));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('A valid e-mail address is required');
        if (Users::findByEmail($email)) throw new RuntimeException('An account with this e-mail exists already');
        $approval = Settings::bool('signup_requires_approval');
        $u = Users::create(['username' => Http::str('username'), 'email' => $email, 'password' => $password, 'name' => Http::str('name'), 'role' => 'user', 'active' => !$approval], null);
        Activity::log('user_create', ['user' => $u, 'details' => ['self_signup' => true, 'pending_approval' => $approval]]);
        Notify::admins('New account' . ($approval ? ' awaiting approval' : ''), $u['username'] . ' (' . $email . ') signed up.', ['user_id' => (int) $u['id']]);
        if ($approval) Http::ok(['pending_approval' => true]);
        Auth::establish($u, false, 'signup');
        Http::ok(['user' => Users::publicProfile(Auth::user())]);
    }

    // ------------------------------------------------------------------ passkeys

    public static function passkey_options(): void
    {
        if (!Settings::bool('passkeys_enabled')) throw new RuntimeException('Passkeys are disabled');
        $u = null;
        $p = Auth::pendingSession();
        if ($p) $u = Users::find((int) $p['user_id']);
        elseif (Http::str('username') !== '') $u = Users::findByUsername(Http::str('username'));
        Http::ok(WebAuthn::assertionOptions($u));
    }

    public static function passkey_login(): void
    {
        if (!Settings::bool('passkeys_enabled')) throw new RuntimeException('Passkeys are disabled');
        $cred = Http::arr('credential');
        $u = WebAuthn::authenticate($cred, Http::int('challenge_id', 0) ?: null);
        $p = Auth::pendingSession();
        if ($p && (int) $p['user_id'] === (int) $u['id']) {
            // Passkey used as the second factor of a password sign-in.
            DB::update('sessions', ['pending_2fa' => 0], 'id = ?', [$p['id']]);
            DB::update('users', ['failed_2fa' => 0, 'last_login_at' => fr_now()], 'id = ?', [(int) $u['id']]);
            Users::forget((int) $u['id']);
            Activity::log('login', ['status' => 'ok', 'user' => $u, 'details' => ['second_factor' => 'passkey']]);
        } else {
            Auth::establish($u, Http::bool('remember', false), 'passkey');
        }
        Http::ok(['status' => 'ok', 'user' => Users::publicProfile(Auth::user() ?? $u)]);
    }

    // ------------------------------------------------------------------ OpenID Connect

    public static function oidc(): void
    {
        if (!class_exists('Oidc') || !Oidc::enabled()) throw new RuntimeException('Single sign-on is not configured');
        Http::redirect(Oidc::startUrl());
    }

    public static function oidc_callback(): void
    {
        try {
            if (!class_exists('Oidc') || !Oidc::enabled()) throw new RuntimeException('Single sign-on is not configured');
            $u = Oidc::callback($_GET);
            Auth::establish($u, false, 'oidc');
            Http::redirect(fr_url('/'));
        } catch (Throwable $e) {
            fr_log('oidc callback failed: ' . $e->getMessage(), 'warn');
            Http::redirect(fr_url('/?error=' . rawurlencode($e->getMessage())));
        }
    }
}
