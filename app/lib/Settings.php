<?php
declare(strict_types=1);

/**
 * Application settings stored in the database (JSON values) with defaults.
 * Settings are what the administrator edits in the control panel; config.php
 * holds only what is needed before the database is reachable.
 */
final class Settings
{
    private static ?array $cache = null;

    /** @return array<string,mixed> */
    public static function defaults(): array
    {
        return [
            // Branding / general
            'app_name' => 'FileRun Web',
            'tagline' => 'Your files, on your own server.',
            'logo_url' => '',
            'favicon_url' => '',
            'accent_color' => '#2563eb',
            'default_theme' => 'auto',           // light | dark | auto
            'custom_css' => '',
            'custom_strings' => [],              // UI wording overrides: key => text
            'default_language' => 'en',
            'privacy_url' => '',
            'terms_url' => '',
            'support_email' => '',
            'login_message' => '',
            'announcement' => '',
            'maintenance_mode' => false,
            'timezone' => 'UTC',
            'date_format' => 'Y-m-d H:i',

            // Security
            'two_factor_email_default' => true,  // e-mailed code for every account with an e-mail address
            'two_factor_required' => false,      // cannot be turned off by the user
            'passkeys_enabled' => true,
            'session_lifetime_hours' => 12,
            'remember_me_days' => 30,
            'password_min_length' => 8,
            'password_require_mixed' => false,
            'login_throttle' => true,
            'allow_password_reset' => true,
            'trusted_proxies' => [],
            'signup_enabled' => false,
            'signup_requires_approval' => true,
            'web_enabled' => true,
            'webdav_enabled' => true,
            'webdav_require_app_password' => false,
            'api_enabled' => true,
            'hide_users_from_everyone' => false,

            // Sharing
            'links_enabled' => true,             // global switch for every public link
            'guest_sharing_enabled' => true,
            'link_max_days' => 0,                // 0 = unlimited
            'link_require_password' => false,
            'link_default_role' => 'viewer',
            'link_terms_default' => '',
            'share_notify_default' => true,

            // Files
            'file_history_enabled' => true,
            'versions_keep' => 10,
            'trash_enabled' => true,
            'trash_retention_days' => 30,
            'max_upload_size' => 0,              // bytes, 0 = unlimited
            'allowed_extensions' => '',
            'denied_extensions' => '',
            'thumbnails_enabled' => true,
            'video_thumbnails' => true,
            'pdf_thumbnails' => true,
            'preview_max_size' => 200 * 1024 * 1024,
            'text_index_enabled' => true,
            'index_max_size' => 20 * 1024 * 1024,
            'ocr_enabled' => false,
            'ocr_languages' => 'eng',
            'exif_enabled' => true,
            'search_max_results' => 500,
            'hidden_files' => '.DS_Store,Thumbs.db,desktop.ini,.git',
            'show_hidden_files' => false,

            // New account defaults
            'default_role' => 'user',
            'default_quota' => 0,
            'default_traffic_quota' => 0,
            'default_max_upload' => 0,
            'default_perms' => [
                'upload' => true, 'download' => true, 'share_users' => true,
                'share_links' => false, 'share_guests' => false, 'file_history' => false,
                'webdav' => true, 'api' => true, 'web' => true, 'hide_users' => false,
                'comments' => true, 'metadata' => true, 'trash' => true,
            ],
            'default_home' => 'users/{username}',

            // E-mail
            'mail_from' => '',
            'mail_from_name' => '',
            'mail_transport' => 'mail',          // mail | smtp | disabled
            'smtp_host' => '',
            'smtp_port' => 587,
            'smtp_user' => '',
            'smtp_pass' => '',
            'smtp_security' => 'tls',            // none | tls | ssl
            'notify_admin_email' => '',
            'email_footer' => '',

            // Integrations
            'onlyoffice_url' => '',
            'onlyoffice_secret' => '',
            'google_viewer_enabled' => false,
            'oidc_enabled' => false,
            'oidc_issuer' => '',
            'oidc_client_id' => '',
            'oidc_client_secret' => '',
            'oidc_button_label' => 'Sign in with SSO',
            'oidc_auto_create' => true,
            'oidc_scopes' => 'openid profile email',

            // Retention / housekeeping
            'inactive_deactivate_days' => 0,
            'inactive_delete_days' => 0,
            'activity_retention_days' => 365,
            'notification_retention_days' => 90,
            'guest_cleanup' => true,

            'schema_version' => 0,
            'installed_at' => '',
        ];
    }

    private static function load(): void
    {
        if (self::$cache !== null) return;
        self::$cache = self::defaults();
        try {
            foreach (DB::all('SELECT `key`,`value` FROM {{settings}}') as $row) {
                self::$cache[$row['key']] = Util::jsonDecode($row['value'], null);
            }
        } catch (Throwable $e) {
            // Database not ready (installer). Defaults remain.
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        self::load();
        if (array_key_exists($key, self::$cache) && self::$cache[$key] !== null) {
            return self::$cache[$key];
        }
        return $default ?? (self::defaults()[$key] ?? null);
    }

    public static function set(string $key, mixed $value): void
    {
        self::load();
        self::$cache[$key] = $value;
        DB::upsert('settings', ['key' => $key, 'value' => Util::json($value), 'updated_at' => fr_now()], ['value', 'updated_at']);
    }

    public static function setMany(array $values): void
    {
        foreach ($values as $k => $v) {
            self::set($k, $v);
        }
    }

    public static function all(): array
    {
        self::load();
        return self::$cache;
    }

    public static function bool(string $key): bool
    {
        return Util::bool(self::get($key));
    }

    public static function int(string $key): int
    {
        return (int) self::get($key);
    }

    /** Settings that are safe to expose to any (even anonymous) client. */
    public static function publicSettings(): array
    {
        return [
            'app_name' => self::get('app_name'),
            'tagline' => self::get('tagline'),
            'logo_url' => self::get('logo_url'),
            'favicon_url' => self::get('favicon_url'),
            'accent_color' => self::get('accent_color'),
            'default_theme' => self::get('default_theme'),
            'custom_css' => self::get('custom_css'),
            'custom_strings' => (object) (self::get('custom_strings') ?: []),
            'default_language' => self::get('default_language'),
            'privacy_url' => self::get('privacy_url'),
            'terms_url' => self::get('terms_url'),
            'support_email' => self::get('support_email'),
            'login_message' => self::get('login_message'),
            'announcement' => self::get('announcement'),
            'passkeys_enabled' => self::bool('passkeys_enabled'),
            'allow_password_reset' => self::bool('allow_password_reset'),
            'signup_enabled' => self::bool('signup_enabled'),
            'oidc_enabled' => self::bool('oidc_enabled') && self::get('oidc_issuer') && self::get('oidc_client_id'),
            'oidc_button_label' => self::get('oidc_button_label'),
            'links_enabled' => self::bool('links_enabled'),
            'onlyoffice' => (bool) self::get('onlyoffice_url'),
            'google_viewer' => self::bool('google_viewer_enabled'),
            'version' => FR_VERSION,
            'maintenance_mode' => self::bool('maintenance_mode'),
            'date_format' => self::get('date_format'),
            'timezone' => self::get('timezone'),
        ];
    }

    /** Keys that only administrators may read back (secrets are masked). */
    public static function adminSettings(): array
    {
        $all = self::all();
        unset($all['oidc_discovery_cache'], $all['oidc_jwks_cache']);
        foreach (['smtp_pass', 'onlyoffice_secret', 'oidc_client_secret'] as $secret) {
            $all[$secret] = $all[$secret] ? '********' : '';
        }
        return $all;
    }
}
