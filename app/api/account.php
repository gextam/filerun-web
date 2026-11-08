<?php
declare(strict_types=1);

/** /api/account/* — the signed-in user's own settings and security. */
final class AccountApi
{
    public static function me(): void
    {
        Http::ok(Users::publicProfile(Api::requireUser()));
    }

    public static function profile(): void
    {
        $u = Api::requireUser();
        $data = [];
        if (Http::input('name') !== null) $data['name'] = mb_substr(trim(Http::str('name')), 0, 200);
        if (Http::input('email') !== null) {
            $email = trim(Http::str('email'));
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Invalid e-mail address');
            if ($email !== (string) $u['email']) {
                if ($u['role'] === 'guest') throw new PermissionException('Guests cannot change their e-mail address');
                $data['email'] = $email;
                Activity::log('email_change', ['details' => ['from' => $u['email'], 'to' => $email]]);
            }
        }
        if (Http::input('language') !== null) $data['language'] = preg_replace('/[^a-zA-Z_\-]/', '', Http::str('language')) ?: null;
        if (is_array(Http::input('prefs'))) $data['prefs'] = Http::arr('prefs');
        $updated = Users::update((int) $u['id'], $data, $u);
        Http::ok(Users::publicProfile($updated));
    }

    public static function password(): void
    {
        $u = Api::requireUser();
        $current = (string) Http::input('current', '');
        $new = (string) Http::input('password', '');
        if (!empty($u['password_hash']) && !Auth::verifyPassword($current, $u['password_hash'])) {
            Activity::log('password_change', ['status' => 'failed', 'reason' => 'wrong current password']);
            throw new RuntimeException('The current password is wrong');
        }
        Auth::changePassword($u, $new);
        Http::ok(Users::publicProfile(Users::find((int) $u['id'])));
    }

    public static function usage(): void
    {
        $u = Api::requireUser();
        if (Http::bool('recalc')) Users::recalcUsage($u);
        $u = Users::find((int) $u['id']);
        Http::ok(['used' => (int) $u['used_bytes'], 'quota' => (int) $u['quota_bytes'], 'traffic' => Users::trafficThisMonth((int) $u['id']), 'traffic_quota' => (int) $u['traffic_quota_bytes'], 'index' => Index::stats((int) $u['id']), 'trash' => (int) DB::val('SELECT COALESCE(SUM(size),0) FROM {{trash}} WHERE owner_id = ?', [(int) $u['id']]), 'versions' => (int) DB::val('SELECT COALESCE(SUM(size),0) FROM {{versions}} WHERE owner_id = ?', [(int) $u['id']])]);
    }

    // ------------------------------------------------------------------ sessions

    public static function sessions(): void
    {
        Http::ok(Auth::sessionsOf((int) Api::requireUser()['id']));
    }

    public static function revoke_session(): void
    {
        $u = Api::requireUser();
        $id = Http::str('id', '');
        $current = Auth::session()['id'] ?? null;
        if ($id === 'all' || $id === '') {
            Auth::revokeAllSessions((int) $u['id'], $current);
        } else {
            DB::delete('sessions', 'user_id = ? AND id = ?', [(int) $u['id'], $id]);
        }
        Activity::log('session_revoke', ['details' => ['session' => $id === '' ? 'all' : substr($id, 0, 12)]]);
        Http::ok(Auth::sessionsOf((int) $u['id']));
    }

    // ------------------------------------------------------------------ app passwords

    public static function app_passwords(): void
    {
        Http::ok(Auth::appPasswordsOf((int) Api::requireUser()['id']));
    }

    public static function app_password_create(): void
    {
        $u = Api::requireUser();
        if (!Users::can($u, 'webdav') && !Users::can($u, 'api')) throw new PermissionException('App passwords are not available for your account');
        Http::ok(Auth::createAppPassword($u, Http::str('name'), Http::str('scope', 'all')));
    }

    public static function app_password_delete(): void
    {
        $u = Api::requireUser();
        DB::delete('app_passwords', 'id = ? AND user_id = ?', [Http::int('id'), (int) $u['id']]);
        Activity::log('app_password_delete', ['details' => ['id' => Http::int('id')]]);
        Http::ok(Auth::appPasswordsOf((int) $u['id']));
    }

    // ------------------------------------------------------------------ two-factor

    public static function totp_setup(): void
    {
        $u = Api::requireUser();
        $secret = Totp::secret();
        DB::delete('tokens', "user_id = ? AND type = 'totp_setup'", [(int) $u['id']]);
        DB::insert('tokens', ['user_id' => (int) $u['id'], 'type' => 'totp_setup', 'token_hash' => hash('sha256', $secret), 'data' => Util::json(['secret' => $secret]), 'expires_at' => gmdate('Y-m-d H:i:s', time() + 900), 'created_at' => fr_now()]);
        Http::ok(['secret' => $secret, 'uri' => Totp::uri($secret, $u['username'], (string) Settings::get('app_name'))]);
    }

    public static function totp_enable(): void
    {
        $u = Api::requireUser();
        $row = DB::one("SELECT * FROM {{tokens}} WHERE user_id = ? AND type = 'totp_setup' AND expires_at > ? ORDER BY id DESC LIMIT 1", [(int) $u['id'], fr_now()]);
        if (!$row) throw new RuntimeException('Start the setup again');
        $secret = (string) (Util::jsonDecode($row['data'], [])['secret'] ?? '');
        if (!Totp::verify($secret, Http::str('code'))) throw new RuntimeException('The code is not valid. Check the time on your device.');
        [$plain, $hashes] = Totp::recoveryCodes();
        DB::update('users', ['two_factor_secret' => $secret, 'recovery_codes' => Util::json($hashes), 'failed_2fa' => 0], 'id = ?', [(int) $u['id']]);
        DB::delete('tokens', 'id = ?', [(int) $row['id']]);
        Users::forget((int) $u['id']);
        Activity::log('two_factor_enable', ['details' => ['method' => 'totp']]);
        Http::ok(['recovery_codes' => $plain, 'user' => Users::publicProfile(Users::find((int) $u['id']))]);
    }

    public static function totp_disable(): void
    {
        $u = Api::requireUser();
        if (Settings::bool('two_factor_required')) throw new PermissionException('Two-factor authentication is required on this server');
        $code = Http::str('code');
        $pw = (string) Http::input('password', '');
        $ok = ($code !== '' && !empty($u['two_factor_secret']) && Totp::verify($u['two_factor_secret'], $code)) || ($pw !== '' && Auth::verifyPassword($pw, $u['password_hash']));
        if (!$ok) throw new RuntimeException('Confirm with your authenticator code or your password');
        DB::update('users', ['two_factor_secret' => null, 'recovery_codes' => null], 'id = ?', [(int) $u['id']]);
        Users::forget((int) $u['id']);
        Activity::log('two_factor_disable', ['details' => ['method' => 'totp']]);
        Http::ok(Users::publicProfile(Users::find((int) $u['id'])));
    }

    public static function email_2fa(): void
    {
        $u = Api::requireUser();
        $on = Http::bool('on', true);
        if (!$on && Settings::bool('two_factor_required') && empty($u['two_factor_secret'])) throw new PermissionException('A second factor is required on this server; set up an authenticator app first');
        if ($on && empty($u['email'])) throw new RuntimeException('Add an e-mail address to your account first');
        DB::update('users', ['two_factor_email' => $on ? 1 : 0], 'id = ?', [(int) $u['id']]);
        Users::forget((int) $u['id']);
        Activity::log($on ? 'two_factor_enable' : 'two_factor_disable', ['details' => ['method' => 'email']]);
        Http::ok(Users::publicProfile(Users::find((int) $u['id'])));
    }

    public static function recovery_regenerate(): void
    {
        $u = Api::requireUser();
        if (empty($u['two_factor_secret'])) throw new RuntimeException('Enable an authenticator app first');
        [$plain, $hashes] = Totp::recoveryCodes();
        DB::update('users', ['recovery_codes' => Util::json($hashes)], 'id = ?', [(int) $u['id']]);
        Users::forget((int) $u['id']);
        Http::ok(['recovery_codes' => $plain]);
    }

    // ------------------------------------------------------------------ passkeys

    public static function passkeys(): void
    {
        Http::ok(WebAuthn::listFor((int) Api::requireUser()['id']));
    }

    public static function passkey_register_options(): void
    {
        if (!Settings::bool('passkeys_enabled')) throw new RuntimeException('Passkeys are disabled');
        Http::ok(WebAuthn::registrationOptions(Api::requireUser()));
    }

    public static function passkey_register(): void
    {
        $u = Api::requireUser();
        WebAuthn::register($u, Http::arr('credential'), Http::str('name', 'Passkey'));
        Http::ok(WebAuthn::listFor((int) $u['id']));
    }

    public static function passkey_delete(): void
    {
        $u = Api::requireUser();
        WebAuthn::delete((int) $u['id'], Http::int('id'));
        Http::ok(WebAuthn::listFor((int) $u['id']));
    }

    public static function passkey_rename(): void
    {
        $u = Api::requireUser();
        WebAuthn::rename((int) $u['id'], Http::int('id'), Http::str('name'));
        Http::ok(WebAuthn::listFor((int) $u['id']));
    }

    // ------------------------------------------------------------------ notifications & follows

    public static function notifications(): void
    {
        $u = Api::requireUser();
        Http::ok(['items' => Notify::listFor((int) $u['id'], Http::bool('unread', false), Http::int('limit', 50)), 'unread' => Notify::unreadCount((int) $u['id'])]);
    }

    public static function notifications_read(): void
    {
        $u = Api::requireUser();
        $ids = Http::arr('ids');
        Notify::markRead((int) $u['id'], $ids ?: null);
        Http::ok(['unread' => Notify::unreadCount((int) $u['id'])]);
    }

    public static function follows(): void
    {
        $u = Api::requireUser();
        Http::ok(array_map(static fn($f) => ['owner_id' => (int) $f['owner_id'], 'owner' => $f['owner_name'], 'path' => $f['path'], 'events' => $f['events'], 'created_at' => $f['created_at']], Notify::followsOf((int) $u['id'])));
    }

    public static function unfollow(): void
    {
        $u = Api::requireUser();
        Notify::unfollow((int) $u['id'], Http::int('owner_id'), Http::str('path'));
        Http::ok(null);
    }

    public static function activity(): void
    {
        $u = Api::requireUser();
        Http::ok(Activity::forUser((int) $u['id'], Http::int('limit', 50)));
    }
}
