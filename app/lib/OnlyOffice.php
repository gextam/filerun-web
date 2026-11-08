<?php
declare(strict_types=1);

/**
 * ONLYOFFICE Document Server connector.
 *
 * Builds the configuration the browser hands to the Document Server's
 * JavaScript API (new DocsAPI.DocEditor(elementId, config)) and handles the
 * server-to-server callback with which the Document Server reports the
 * editing status and delivers the modified document for saving.
 *
 * The Document Server fetches the file through a short-lived file-access
 * token (GET /open/<token>, served with Storage::download) and posts its
 * callbacks to /api/files/office_callback?token=<signed office token>.
 * When a JWT secret is configured (strongly recommended; the Document
 * Server enables it by default) every message in both directions is signed
 * with HS256; without one, only downloads from the configured Document
 * Server host are accepted.
 *
 * Only the curl, hash and json extensions are used.
 */
final class OnlyOffice
{
    /** extension => ONLYOFFICE documentType */
    private const TYPES = [
        'docx' => 'word', 'doc' => 'word', 'odt' => 'word', 'rtf' => 'word', 'txt' => 'word', 'docm' => 'word', 'dotx' => 'word',
        'dotm' => 'word', 'dot' => 'word', 'ott' => 'word', 'fodt' => 'word', 'epub' => 'word', 'fb2' => 'word', 'html' => 'word',
        'htm' => 'word', 'mht' => 'word',
        'xlsx' => 'cell', 'xls' => 'cell', 'ods' => 'cell', 'csv' => 'cell', 'xlsm' => 'cell', 'xltx' => 'cell', 'xltm' => 'cell',
        'xlt' => 'cell', 'ots' => 'cell', 'fods' => 'cell',
        'pptx' => 'slide', 'ppt' => 'slide', 'odp' => 'slide', 'ppsx' => 'slide', 'pps' => 'slide', 'pptm' => 'slide',
        'potx' => 'slide', 'potm' => 'slide', 'pot' => 'slide', 'otp' => 'slide', 'fodp' => 'slide',
        'pdf' => 'pdf', 'djvu' => 'pdf', 'xps' => 'pdf', 'oxps' => 'pdf',
    ];

    /** Formats the Document Server edits in place; everything else opens read-only. */
    private const EDITABLE = ['docx', 'xlsx', 'pptx', 'txt', 'csv'];

    private const FILE_TOKEN_TTL = 6 * 3600;     // how long the Document Server may fetch the file
    private const CALLBACK_TOKEN_TTL = 24 * 3600; // how long the editing session may post callbacks
    private const DOWNLOAD_TIMEOUT = 60;          // seconds to fetch the saved document
    private const JWT_LEEWAY = 300;               // clock skew tolerated on the Document Server's tokens

    // ------------------------------------------------------------------
    // Capability checks
    // ------------------------------------------------------------------

    public static function enabled(): bool
    {
        return self::serverUrl() !== '';
    }

    /** ONLYOFFICE document type ('word' | 'cell' | 'slide' | 'pdf') for a file name, or null when unsupported. */
    public static function supports(string $name): ?string
    {
        return self::TYPES[Util::ext($name)] ?? null;
    }

    /** True for formats that open in edit mode (the others are view-only). */
    public static function editable(string $name): bool
    {
        return in_array(Util::ext($name), self::EDITABLE, true);
    }

    // ------------------------------------------------------------------
    // Editor configuration (GET /api/files/office_config)
    // ------------------------------------------------------------------

    /**
     * Configuration for the browser. $mode 'view' forces read-only, anything
     * else ('auto') edits when the user may and the format allows it.
     * Returns ['documentServerUrl', 'scriptUrl', 'config'] where config is
     * passed as is to DocsAPI.DocEditor (signed in config.token when a secret
     * is set).
     */
    public static function config(Ctx $ctx, ?array $user, string $mode = 'auto'): array
    {
        if (!self::enabled()) {
            throw new RuntimeException('ONLYOFFICE is not configured');
        }
        $name = Util::baseName($ctx->rel);
        $type = self::supports($name);
        if ($type === null) {
            throw new RuntimeException('This file type cannot be opened with ONLYOFFICE');
        }
        if (!is_file($ctx->abs)) {
            throw new NotFoundException('File not found');
        }
        $ctx->need('read');

        $size = (int) filesize($ctx->abs);
        $mtime = (int) filemtime($ctx->abs);
        $canEdit = $mode !== 'view' && $ctx->can('edit') && self::editable($name);
        $userName = $user ? (string) (($user['name'] ?? '') !== '' ? $user['name'] : $user['username']) : 'Guest';
        $lang = (string) (($user['language'] ?? '') ?: Settings::get('default_language', 'en'));

        $config = [
            'documentType' => $type,
            'document' => [
                'fileType' => Util::ext($name),
                // The Document Server caches by key: it must change whenever the file does.
                'key' => substr(sha1($ctx->ownerId . '|' . $ctx->rel . '|' . $mtime . '|' . $size), 0, 20),
                'title' => $name,
                'url' => fr_public_url('/open/' . Tokens::fileAccess($ctx, $user, self::FILE_TOKEN_TTL, false)),
                'permissions' => [
                    'edit' => $canEdit,
                    'download' => $ctx->can('download'),
                    'print' => true,
                    'review' => $canEdit,
                    'comment' => $canEdit,
                ],
            ],
            'editorConfig' => [
                'mode' => $canEdit ? 'edit' : 'view',
                'lang' => $lang,
                'callbackUrl' => fr_public_url('/api/files/office_callback?token=' . Tokens::sign([
                    't' => 'office',
                    'p' => $ctx->vpath,
                    'u' => isset($user['id']) ? (int) $user['id'] : null,
                    'l' => $ctx->link['hash'] ?? null,
                ], self::CALLBACK_TOKEN_TTL)),
                'user' => [
                    'id' => isset($user['id']) ? (string) (int) $user['id'] : 'guest',
                    'name' => $userName,
                ],
                'customization' => [
                    'autosave' => true,
                    'forcesave' => true,
                    'compactHeader' => true,
                ],
            ],
            'type' => preg_match('/Android|iPhone|iPad|iPod|Mobile/i', Http::userAgent()) ? 'mobile' : 'desktop',
        ];
        $secret = (string) (Settings::get('onlyoffice_secret') ?? '');
        if ($secret !== '') {
            $config['token'] = self::jwtEncode($config, $secret);
        }
        return [
            'documentServerUrl' => self::serverUrl(),
            'scriptUrl' => self::serverUrl() . '/web-apps/apps/api/documents/api.js',
            'config' => $config,
        ];
    }

    // ------------------------------------------------------------------
    // Document Server callback (POST /api/files/office_callback?token=...)
    // ------------------------------------------------------------------

    /**
     * Handle a callback. Always returns the JSON the Document Server expects:
     * ['error' => 0] on success, ['error' => 1] when the request must be
     * refused or the save failed (the editor then tells the user).
     */
    public static function callback(string $token): array
    {
        try {
            $t = Tokens::verify($token);
            if (!$t || ($t['t'] ?? '') !== 'office' || empty($t['p'])) {
                fr_log('onlyoffice: callback with a missing, invalid or expired token', 'warn');
                return ['error' => 1];
            }
            $body = json_decode((string) file_get_contents('php://input'), true);
            if (!is_array($body)) {
                fr_log('onlyoffice: callback body is not JSON', 'warn');
                return ['error' => 1];
            }
            $secret = (string) (Settings::get('onlyoffice_secret') ?? '');
            if ($secret !== '') {
                $jwt = isset($body['token']) && is_string($body['token']) ? $body['token'] : null;
                if ($jwt === null) {
                    $auth = trim((string) (Http::header('Authorization') ?? ''));
                    if (preg_match('/^Bearer\s+(\S+)$/i', $auth, $m)) {
                        $jwt = $m[1];
                    }
                }
                $payload = $jwt !== null ? self::jwtDecode($jwt, $secret) : null;
                if ($payload === null) {
                    fr_log('onlyoffice: callback without a valid JWT (check that the secret matches the Document Server)', 'warn');
                    return ['error' => 1];
                }
                // In the header variant the message sits under "payload"; in the body variant it is the payload itself.
                $body = isset($payload['payload']) && is_array($payload['payload']) ? $payload['payload'] : $payload;
            }

            $status = (int) ($body['status'] ?? 0);
            switch ($status) {
                case 2: // document ready for saving (last editor closed it)
                case 6: // force save (Save button, autosave with forcesave, or API request)
                    self::save($t, $body, $secret === '');
                    return ['error' => 0];
                case 3: // saving error
                case 7: // force-save error
                    fr_log('onlyoffice: the Document Server reported a saving error (status ' . $status . ') for ' . $t['p'], 'warn');
                    return ['error' => 0];
                case 1: // being edited
                case 4: // closed without changes
                default:
                    return ['error' => 0];
            }
        } catch (Throwable $e) {
            fr_log('onlyoffice: callback failed: ' . $e->getMessage());
            return ['error' => 1];
        }
    }

    /** Download the saved document from the Document Server and store it as a new version of the file. */
    private static function save(array $t, array $body, bool $restrictHost): void
    {
        $url = isset($body['url']) && is_string($body['url']) ? trim($body['url']) : '';
        if (!preg_match('#^https?://#i', $url)) {
            throw new RuntimeException('the callback carries no document URL');
        }
        if ($restrictHost) {
            // Without a shared secret nothing authenticates the caller: only fetch from the configured Document Server.
            $expected = strtolower((string) parse_url(self::serverUrl(), PHP_URL_HOST));
            $given = strtolower((string) parse_url($url, PHP_URL_HOST));
            if ($expected === '' || $given !== $expected) {
                throw new RuntimeException('document URL host "' . $given . '" is not the configured Document Server "' . $expected . '" (set a JWT secret to allow it)');
            }
        }

        $user = !empty($t['u']) ? Users::find((int) $t['u']) : null;
        $link = !empty($t['l']) ? Links::byHash((string) $t['l']) : null;
        if (!$user && !$link) {
            throw new RuntimeException('the editing account or link no longer exists');
        }
        if ($user && !$user['active']) {
            throw new RuntimeException('the editing account is deactivated');
        }
        $ctx = Paths::resolveExisting((string) $t['p'], $user, $link);
        if (!is_file($ctx->abs)) {
            throw new RuntimeException('not a file: ' . $ctx->vpath);
        }

        $dir = fr_data_dir('temp');
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException('cannot create the temp folder ' . $dir);
        }
        $tmp = $dir . '/office-' . Util::hex(8) . '.tmp';
        try {
            self::download($url, $tmp);
            $size = (int) filesize($tmp);
            if ($size <= 0) {
                throw new RuntimeException('the Document Server delivered an empty file');
            }
            $stream = fopen($tmp, 'rb');
            if ($stream === false) {
                throw new RuntimeException('cannot read the downloaded file');
            }
            try {
                Storage::putStream($ctx, $stream, $size, ['actor' => $user, 'source' => 'edit']);
            } finally {
                fclose($stream);
            }
        } finally {
            @unlink($tmp);
        }
    }

    private static function download(string $url, string $dest): void
    {
        $fh = fopen($dest, 'wb');
        if ($fh === false) {
            throw new RuntimeException('cannot write to the temp folder');
        }
        $ch = curl_init($url);
        if ($ch === false) {
            fclose($fh);
            throw new RuntimeException('curl is not available');
        }
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fh,
            CURLOPT_TIMEOUT => self::DOWNLOAD_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'FileRun-Web/' . FR_VERSION,
        ]);
        $ok = curl_exec($ch);
        $err = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        fclose($fh);
        if ($ok === false) {
            throw new RuntimeException('download from the Document Server failed: ' . $err);
        }
        if ($status !== 200) {
            throw new RuntimeException('the Document Server answered HTTP ' . $status . ' for the saved document');
        }
    }

    // ------------------------------------------------------------------
    // HS256 JSON Web Tokens (the only algorithm the Document Server uses)
    // ------------------------------------------------------------------

    public static function jwtEncode(array $payload, string $secret): string
    {
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        $input = self::b64u((string) json_encode(['alg' => 'HS256', 'typ' => 'JWT'], $flags))
            . '.' . self::b64u((string) json_encode($payload, $flags));
        return $input . '.' . self::b64u(hash_hmac('sha256', $input, $secret, true));
    }

    /** Verified payload of an HS256 token, or null when malformed, tampered with, or expired. */
    public static function jwtDecode(string $jwt, string $secret): ?array
    {
        $parts = explode('.', trim($jwt));
        if (count($parts) !== 3) {
            return null;
        }
        [$h, $p, $s] = $parts;
        $headerJson = self::b64uDecode($h);
        $sig = self::b64uDecode($s);
        if ($headerJson === null || $sig === null) {
            return null;
        }
        $header = json_decode($headerJson, true);
        if (!is_array($header) || ($header['alg'] ?? null) !== 'HS256') {
            return null;
        }
        if (!hash_equals(hash_hmac('sha256', $h . '.' . $p, $secret, true), $sig)) {
            return null;
        }
        $payloadJson = self::b64uDecode($p);
        $payload = $payloadJson === null ? null : json_decode($payloadJson, true);
        if (!is_array($payload)) {
            return null;
        }
        if (isset($payload['exp']) && is_numeric($payload['exp']) && (int) $payload['exp'] + self::JWT_LEEWAY < time()) {
            return null;
        }
        return $payload;
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private static function serverUrl(): string
    {
        return rtrim(trim((string) (Settings::get('onlyoffice_url') ?? '')), '/');
    }

    private static function b64u(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    private static function b64uDecode(string $s): ?string
    {
        $s = strtr($s, '-_', '+/');
        $pad = strlen($s) % 4;
        if ($pad === 1) {
            return null;
        }
        if ($pad !== 0) {
            $s .= str_repeat('=', 4 - $pad);
        }
        $out = base64_decode($s, true);
        return $out === false ? null : $out;
    }
}
