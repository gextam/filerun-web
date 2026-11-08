<?php
declare(strict_types=1);

/**
 * OpenID Connect sign-in (authorization code flow with PKCE).
 *
 * Works with any compliant provider (Keycloak, Authentik, Authelia, Okta,
 * Microsoft Entra ID, Google, ...) from three settings: issuer URL, client id
 * and client secret. Endpoints come from the provider's discovery document,
 * ID tokens are verified against its JWKS (RS256/384/512 and ES256/384/512;
 * "none" is rejected), and accounts are matched by the stored subject
 * ("issuer|sub") or by verified e-mail address, with optional automatic
 * account creation.
 *
 * Flow as wired by the router:
 *   GET /api/auth/oidc           -> Http::redirect(Oidc::startUrl())
 *   GET /api/auth/oidc_callback  -> $user = Oidc::callback($_GET);
 *                                   Auth::establish($user, false, 'oidc');
 *
 * Nothing is kept client side: state, nonce and the PKCE verifier live in
 * {{tokens}} (type "oidc_state") for ten minutes; the discovery document and
 * the JWKS are cached in {{settings}} for an hour. Only the curl, openssl and
 * json extensions are needed; TLS certificates are always verified.
 */
final class Oidc
{
    private const STATE_TTL = 600;       // seconds a started sign-in stays valid
    private const CACHE_TTL = 3600;      // discovery document / JWKS cache lifetime
    private const HTTP_TIMEOUT = 10;     // seconds per request to the provider
    private const LEEWAY = 60;           // tolerated clock skew for exp / nbf / iat
    private const DEFAULT_SCOPES = 'openid profile email';

    /** JWS algorithm => [openssl digest, JWK key type, EC coordinate length in bytes] */
    private const ALGS = [
        'RS256' => [OPENSSL_ALGO_SHA256, 'RSA', 0],
        'RS384' => [OPENSSL_ALGO_SHA384, 'RSA', 0],
        'RS512' => [OPENSSL_ALGO_SHA512, 'RSA', 0],
        'ES256' => [OPENSSL_ALGO_SHA256, 'EC', 32],
        'ES384' => [OPENSSL_ALGO_SHA384, 'EC', 48],
        'ES512' => [OPENSSL_ALGO_SHA512, 'EC', 66],
    ];

    /** JWK curve name => [DER encoded curve OID, coordinate length in bytes] */
    private const CURVES = [
        'P-256' => ["\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07", 32],   // prime256v1
        'P-384' => ["\x06\x05\x2b\x81\x04\x00\x22", 48],               // secp384r1
        'P-521' => ["\x06\x05\x2b\x81\x04\x00\x23", 66],               // secp521r1
    ];

    private const OID_RSA_ENCRYPTION = "\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01";
    private const OID_EC_PUBLIC_KEY = "\x06\x07\x2a\x86\x48\xce\x3d\x02\x01";

    // ------------------------------------------------------------------
    // Public API
    // ------------------------------------------------------------------

    /** Single sign-on is switched on and has an issuer and a client id. */
    public static function enabled(): bool
    {
        return Settings::bool('oidc_enabled') && self::issuer() !== '' && self::clientId() !== '';
    }

    /**
     * Start a sign-in: remembers state, nonce and PKCE verifier for ten minutes
     * and returns the provider URL the browser must be redirected to.
     */
    public static function startUrl(): string
    {
        self::assertEnabled();
        $doc = self::discovery();
        $state = Util::token(32);
        $nonce = Util::token(32);
        $verifier = Util::token(48); // 64 unreserved characters (RFC 7636 allows 43-128)

        DB::delete('tokens', "type = 'oidc_state' AND expires_at < ?", [fr_now()]);
        DB::insert('tokens', [
            'user_id' => null,
            'type' => 'oidc_state',
            'token_hash' => hash('sha256', $state),
            'data' => Util::json(['nonce' => $nonce, 'code_verifier' => $verifier, 'created' => time()]),
            'expires_at' => gmdate('Y-m-d H:i:s', time() + self::STATE_TTL),
            'created_at' => fr_now(),
        ]);

        $params = [
            'response_type' => 'code',
            'client_id' => self::clientId(),
            'redirect_uri' => self::redirectUri(),
            'scope' => self::scopes(),
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => self::pkceChallenge($verifier),
            'code_challenge_method' => 'S256',
        ];
        return self::withQuery((string) $doc['authorization_endpoint'], $params);
    }

    /**
     * Finish a sign-in from the query string the provider redirected back
     * with. Returns the matching (or newly created) user row; the caller signs
     * it in with Auth::establish($user, false, 'oidc'). Every failure is
     * recorded as a failed login and rethrown as a RuntimeException.
     */
    public static function callback(array $query): array
    {
        $user = null;
        $hint = '';
        try {
            self::assertEnabled();

            $state = self::str($query['state'] ?? null);
            if ($state === '' || strlen($state) > 255) {
                throw new RuntimeException('The sign-in response carries no state; please start again');
            }
            $row = DB::one("SELECT * FROM {{tokens}} WHERE type = 'oidc_state' AND token_hash = ? AND used_at IS NULL AND expires_at > ?", [hash('sha256', $state), fr_now()]);
            if (!$row) {
                throw new RuntimeException('This sign-in attempt is invalid or has expired; please start again');
            }
            DB::update('tokens', ['used_at' => fr_now()], 'id = ?', [(int) $row['id']]); // single use
            $stored = Util::jsonDecode($row['data'] ?? null, []);
            $nonce = self::str($stored['nonce'] ?? null);
            $verifier = self::str($stored['code_verifier'] ?? null);
            if ($nonce === '' || $verifier === '') {
                throw new RuntimeException('The sign-in state is incomplete; please start again');
            }

            if (self::str($query['error'] ?? null) !== '') {
                $desc = self::str($query['error_description'] ?? null);
                throw new RuntimeException('The identity provider refused the sign-in: ' . ($desc !== '' ? $desc : self::str($query['error'])));
            }
            $code = self::str($query['code'] ?? null);
            if ($code === '') {
                throw new RuntimeException('The identity provider returned no authorization code');
            }

            $doc = self::discovery();
            $tokens = self::exchangeCode($doc, $code, $verifier);
            $idToken = $tokens['id_token'] ?? null;
            if (!is_string($idToken) || $idToken === '') {
                throw new RuntimeException('The identity provider returned no ID token');
            }
            $claims = self::verifyJwt($idToken);
            self::validateClaims($claims, $nonce);
            $hint = self::str($claims['email'] ?? ($claims['preferred_username'] ?? $claims['sub']));

            $accessToken = $tokens['access_token'] ?? null;
            $needsProfile = self::str($claims['email'] ?? null) === ''
                || (self::str($claims['name'] ?? null) === '' && self::str($claims['preferred_username'] ?? null) === '');
            if ($needsProfile && is_string($accessToken) && $accessToken !== '' && !empty($doc['userinfo_endpoint'])) {
                $claims = self::mergeUserinfo($claims, (string) $doc['userinfo_endpoint'], $accessToken);
                $hint = self::str($claims['email'] ?? ($claims['preferred_username'] ?? $claims['sub']));
            }

            $user = self::findOrCreateUser($claims);
            if ($reason = Auth::accountUsable($user)) {
                throw new RuntimeException($reason);
            }
            return $user;
        } catch (Throwable $e) {
            Activity::log('login', [
                'status' => 'failed',
                'reason' => $e->getMessage(),
                'user' => $user,
                'details' => ['oidc' => true, 'username' => $user['username'] ?? $hint],
            ]);
            throw $e;
        }
    }

    /** Provider logout URL (RP-initiated logout) that returns to the start page, or null when unsupported. */
    public static function logoutUrl(): ?string
    {
        if (!self::enabled()) {
            return null;
        }
        try {
            $doc = self::discovery();
        } catch (Throwable $e) {
            fr_log('oidc: discovery failed while building the logout URL: ' . $e->getMessage(), 'warn');
            return null;
        }
        $end = $doc['end_session_endpoint'] ?? null;
        if (!is_string($end) || !preg_match('#^https?://#i', $end)) {
            return null;
        }
        return self::withQuery($end, ['post_logout_redirect_uri' => fr_public_url('/'), 'client_id' => self::clientId()]);
    }

    // ------------------------------------------------------------------
    // Public helpers (pure functions, also used by the self tests)
    // ------------------------------------------------------------------

    /** PKCE S256 code challenge of a verifier (RFC 7636 §4.2). */
    public static function pkceChallenge(string $verifier): string
    {
        return self::b64u(hash('sha256', $verifier, true));
    }

    public static function b64u(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    public static function b64uDecode(string $s): string
    {
        $s = strtr($s, '-_', '+/');
        $pad = strlen($s) % 4;
        if ($pad === 1) {
            throw new RuntimeException('Malformed base64url data');
        }
        if ($pad !== 0) {
            $s .= str_repeat('=', 4 - $pad);
        }
        $out = base64_decode($s, true);
        if ($out === false) {
            throw new RuntimeException('Malformed base64url data');
        }
        return $out;
    }

    /** Convert a public JWK (RSA, or EC on P-256 / P-384 / P-521) to a SubjectPublicKeyInfo PEM. */
    public static function jwkToPem(array $jwk): string
    {
        $kty = self::str($jwk['kty'] ?? null);
        if ($kty === 'RSA') {
            if (empty($jwk['n']) || empty($jwk['e'])) {
                throw new RuntimeException('RSA JWK lacks n or e');
            }
            $n = self::b64uDecode((string) $jwk['n']);
            $e = self::b64uDecode((string) $jwk['e']);
            $algId = self::der(0x30, self::OID_RSA_ENCRYPTION . "\x05\x00");
            $pub = self::der(0x30, self::derInt($n) . self::derInt($e));
            $spki = self::der(0x30, $algId . self::der(0x03, "\x00" . $pub));
        } elseif ($kty === 'EC') {
            $crv = self::str($jwk['crv'] ?? null);
            if (!isset(self::CURVES[$crv])) {
                throw new RuntimeException('Unsupported EC curve' . ($crv !== '' ? ' ' . $crv : ''));
            }
            if (empty($jwk['x']) || empty($jwk['y'])) {
                throw new RuntimeException('EC JWK lacks x or y');
            }
            [$oid, $len] = self::CURVES[$crv];
            $x = ltrim(self::b64uDecode((string) $jwk['x']), "\x00");
            $y = ltrim(self::b64uDecode((string) $jwk['y']), "\x00");
            if (strlen($x) > $len || strlen($y) > $len) {
                throw new RuntimeException('EC JWK coordinates do not fit curve ' . $crv);
            }
            $point = "\x04" . str_pad($x, $len, "\x00", STR_PAD_LEFT) . str_pad($y, $len, "\x00", STR_PAD_LEFT);
            $algId = self::der(0x30, self::OID_EC_PUBLIC_KEY . $oid);
            $spki = self::der(0x30, $algId . self::der(0x03, "\x00" . $point));
        } else {
            throw new RuntimeException('Unsupported JWK key type' . ($kty !== '' ? ' ' . $kty : ''));
        }
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    /** Convert a JOSE ECDSA signature (r || s, fixed width) to the DER SEQUENCE openssl_verify expects. */
    public static function joseToDer(string $sig): string
    {
        $len = strlen($sig);
        if ($len === 0 || $len % 2 !== 0) {
            throw new RuntimeException('Malformed ECDSA signature');
        }
        $half = intdiv($len, 2);
        return self::der(0x30, self::derInt(substr($sig, 0, $half)) . self::derInt(substr($sig, $half)));
    }

    /**
     * Verify a compact JWS and return its payload. Only RS256/384/512 and
     * ES256/384/512 are accepted; "none" and HMAC algorithms are rejected.
     * With $jwks null the provider's cached key set is used and fetched again
     * once when the token names an unknown key id (key rotation). $jwks may
     * be a full JWKS document ({"keys": [...]}) or a plain list of JWKs.
     * Claims (iss, aud, exp, nonce) are NOT checked here.
     */
    public static function verifyJwt(string $jwt, ?array $jwks = null): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new RuntimeException('Malformed ID token');
        }
        [$h, $p, $s] = $parts;
        $header = json_decode(self::b64uDecode($h), true);
        $payload = json_decode(self::b64uDecode($p), true);
        $signature = self::b64uDecode($s);
        if (!is_array($header) || !is_array($payload)) {
            throw new RuntimeException('Malformed ID token');
        }
        $alg = self::str($header['alg'] ?? null);
        if ($alg === '' || !isset(self::ALGS[$alg])) {
            throw new RuntimeException('Unsupported ID token algorithm' . ($alg !== '' ? ' ' . $alg : ''));
        }
        if ($signature === '') {
            throw new RuntimeException('ID token is not signed');
        }
        $kid = isset($header['kid']) && is_scalar($header['kid']) ? (string) $header['kid'] : null;
        $input = $h . '.' . $p;

        $keys = $jwks === null ? self::jwks() : self::keyList($jwks);
        $candidates = self::candidates($keys, $kid, $alg);
        if (!$candidates && $jwks === null) {
            $candidates = self::candidates(self::jwks(true), $kid, $alg); // the provider may have rotated its keys
        }
        if (!$candidates) {
            throw new RuntimeException('No signing key matches the ID token' . ($kid !== null ? ' (kid ' . $kid . ')' : ''));
        }
        foreach ($candidates as $jwk) {
            if (self::verifySignature($alg, $input, $signature, $jwk)) {
                return $payload;
            }
        }
        throw new RuntimeException('ID token signature is invalid');
    }

    /** Check one signature against one JWK. */
    public static function verifySignature(string $alg, string $input, string $signature, array $jwk): bool
    {
        if (!isset(self::ALGS[$alg])) {
            return false;
        }
        [$digest, $kty, $coord] = self::ALGS[$alg];
        if (self::str($jwk['kty'] ?? null) !== $kty) {
            return false;
        }
        try {
            $pem = self::jwkToPem($jwk);
            if ($kty === 'EC') {
                $crv = self::str($jwk['crv'] ?? null);
                if ((self::CURVES[$crv][1] ?? 0) !== $coord || strlen($signature) !== 2 * $coord) {
                    return false; // curve must match the algorithm, signature must be r||s of the curve width
                }
                $signature = self::joseToDer($signature);
            }
        } catch (Throwable) {
            return false;
        }
        $key = openssl_pkey_get_public($pem);
        if ($key === false) {
            return false;
        }
        return openssl_verify($input, $signature, $key, $digest) === 1;
    }

    // ------------------------------------------------------------------
    // Settings
    // ------------------------------------------------------------------

    private static function assertEnabled(): void
    {
        if (!self::enabled()) {
            throw new RuntimeException('Single sign-on is not enabled');
        }
    }

    private static function issuer(): string
    {
        return rtrim(self::str(Settings::get('oidc_issuer')), '/');
    }

    private static function clientId(): string
    {
        return self::str(Settings::get('oidc_client_id'));
    }

    private static function clientSecret(): string
    {
        return (string) (Settings::get('oidc_client_secret') ?? '');
    }

    private static function redirectUri(): string
    {
        return fr_public_url('/api/auth/oidc_callback');
    }

    /** Configured scopes, always including "openid". */
    private static function scopes(): string
    {
        $scopes = preg_split('/[\s,]+/', self::str(Settings::get('oidc_scopes')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (!$scopes) {
            $scopes = explode(' ', self::DEFAULT_SCOPES);
        }
        if (!in_array('openid', $scopes, true)) {
            array_unshift($scopes, 'openid');
        }
        return implode(' ', array_values(array_unique($scopes)));
    }

    // ------------------------------------------------------------------
    // Discovery and keys (cached in settings)
    // ------------------------------------------------------------------

    /** The provider's OpenID Provider Metadata, cached for an hour. */
    private static function discovery(): array
    {
        $issuer = self::issuer();
        if ($issuer === '') {
            throw new RuntimeException('OpenID Connect issuer is not configured');
        }
        $cache = Settings::get('oidc_discovery_cache');
        if (is_array($cache) && ($cache['issuer'] ?? null) === $issuer
            && (int) ($cache['fetched_at'] ?? 0) > time() - self::CACHE_TTL
            && is_array($cache['doc'] ?? null) && !empty($cache['doc']['authorization_endpoint'])) {
            return $cache['doc'];
        }
        $doc = self::httpGet($issuer . '/.well-known/openid-configuration');
        foreach (['issuer', 'authorization_endpoint', 'token_endpoint', 'jwks_uri'] as $k) {
            if (empty($doc[$k]) || !is_string($doc[$k])) {
                throw new RuntimeException('OpenID Connect discovery document lacks ' . $k);
            }
        }
        if (rtrim($doc['issuer'], '/') !== $issuer) {
            throw new RuntimeException('OpenID Connect discovery document belongs to issuer ' . $doc['issuer'] . ', not ' . $issuer);
        }
        Settings::set('oidc_discovery_cache', ['fetched_at' => time(), 'issuer' => $issuer, 'doc' => $doc]);
        return $doc;
    }

    /** The provider's signing keys (list of JWKs), cached for an hour. */
    private static function jwks(bool $force = false): array
    {
        $uri = (string) self::discovery()['jwks_uri'];
        $cache = Settings::get('oidc_jwks_cache');
        $cached = is_array($cache) && ($cache['uri'] ?? null) === $uri && is_array($cache['keys'] ?? null);
        $age = $cached ? time() - (int) ($cache['fetched_at'] ?? 0) : PHP_INT_MAX;
        // Serve from cache while fresh; a forced refresh is still throttled to once a minute
        // so that tokens with made-up key ids cannot make us hammer the provider.
        if ($cached && ($age < ($force ? 60 : self::CACHE_TTL))) {
            return $cache['keys'];
        }
        $doc = self::httpGet($uri);
        if (!isset($doc['keys']) || !is_array($doc['keys'])) {
            throw new RuntimeException('The JWKS document of the identity provider has no keys');
        }
        $keys = array_values(array_filter($doc['keys'], 'is_array'));
        Settings::set('oidc_jwks_cache', ['fetched_at' => time(), 'uri' => $uri, 'keys' => $keys]);
        return $keys;
    }

    /** Accept either a JWKS document or a plain list of JWKs. */
    private static function keyList(array $jwks): array
    {
        $list = isset($jwks['keys']) && is_array($jwks['keys']) ? $jwks['keys'] : $jwks;
        return array_values(array_filter($list, 'is_array'));
    }

    /** Keys of the right type (and key id, when the token names one) for an algorithm. */
    private static function candidates(array $keys, ?string $kid, string $alg): array
    {
        $kty = self::ALGS[$alg][1];
        $out = [];
        foreach ($keys as $k) {
            if (self::str($k['kty'] ?? null) !== $kty) continue;
            if (self::str($k['use'] ?? 'sig') !== 'sig') continue;
            if (self::str($k['alg'] ?? null) !== '' && $k['alg'] !== $alg) continue;
            if ($kid !== null && self::str($k['kid'] ?? null) !== $kid) continue;
            $out[] = $k;
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // Token exchange and claims
    // ------------------------------------------------------------------

    /** Redeem the authorization code at the token endpoint. */
    private static function exchangeCode(array $doc, string $code, string $verifier): array
    {
        $fields = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => self::redirectUri(),
            'client_id' => self::clientId(),
            'code_verifier' => $verifier,
        ];
        $basic = null;
        $secret = self::clientSecret();
        if ($secret !== '') {
            // client_secret_post unless the provider says it only takes HTTP Basic.
            $methods = $doc['token_endpoint_auth_methods_supported'] ?? null;
            $usePost = !is_array($methods) || in_array('client_secret_post', $methods, true) || !in_array('client_secret_basic', $methods, true);
            if ($usePost) {
                $fields['client_secret'] = $secret;
            } else {
                $basic = [self::clientId(), $secret];
            }
        }
        return self::httpPost((string) $doc['token_endpoint'], $fields, $basic);
    }

    /** OIDC Core 3.1.3.7: issuer, audience, lifetime and nonce of the ID token. */
    private static function validateClaims(array $c, string $nonce): void
    {
        $now = time();
        if (rtrim(self::str($c['iss'] ?? null), '/') !== self::issuer()) {
            throw new RuntimeException('ID token issuer mismatch');
        }
        $aud = $c['aud'] ?? [];
        $aud = array_map('strval', array_filter(is_array($aud) ? $aud : [$aud], 'is_scalar'));
        if (!in_array(self::clientId(), $aud, true)) {
            throw new RuntimeException('ID token was not issued for this application');
        }
        if (isset($c['azp']) && self::str($c['azp']) !== self::clientId()) {
            throw new RuntimeException('ID token authorized party mismatch');
        }
        if (!isset($c['exp']) || !is_numeric($c['exp'])) {
            throw new RuntimeException('ID token has no expiry');
        }
        if ((int) $c['exp'] + self::LEEWAY < $now) {
            throw new RuntimeException('ID token has expired');
        }
        if (isset($c['nbf']) && is_numeric($c['nbf']) && (int) $c['nbf'] - self::LEEWAY > $now) {
            throw new RuntimeException('ID token is not valid yet');
        }
        if (isset($c['iat']) && is_numeric($c['iat']) && (int) $c['iat'] - self::LEEWAY > $now) {
            throw new RuntimeException('ID token was issued in the future');
        }
        if (self::str($c['sub'] ?? null) === '') {
            throw new RuntimeException('ID token has no subject');
        }
        $got = self::str($c['nonce'] ?? null);
        if ($got === '' || !hash_equals($nonce, $got)) {
            throw new RuntimeException('ID token nonce mismatch');
        }
    }

    /** Fill missing profile claims from the userinfo endpoint (its subject must match the ID token). */
    private static function mergeUserinfo(array $claims, string $endpoint, string $accessToken): array
    {
        try {
            $info = self::httpGet($endpoint, ['Authorization: Bearer ' . $accessToken]);
        } catch (Throwable $e) {
            fr_log('oidc: userinfo request failed: ' . $e->getMessage(), 'warn');
            return $claims;
        }
        if (self::str($info['sub'] ?? null) !== self::str($claims['sub'])) {
            throw new RuntimeException('Userinfo subject does not match the ID token');
        }
        foreach (['email', 'email_verified', 'name', 'preferred_username', 'given_name', 'family_name'] as $k) {
            if (!isset($claims[$k]) && isset($info[$k])) {
                $claims[$k] = $info[$k];
            }
        }
        return $claims;
    }

    /**
     * Match the identity to an account: by stored subject first, then by
     * verified e-mail address (linking the subject to that account), else
     * create one when oidc_auto_create allows it.
     */
    private static function findOrCreateUser(array $c): array
    {
        $issuer = self::issuer();
        $subject = $issuer . '|' . self::str($c['sub']);
        $email = strtolower(self::str($c['email'] ?? null));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $email = '';
        }
        $verified = !array_key_exists('email_verified', $c) || Util::bool($c['email_verified']);

        $user = DB::one('SELECT * FROM {{users}} WHERE oidc_subject = ? ORDER BY id LIMIT 1', [$subject]);
        if ($user) {
            return $user;
        }

        if ($email !== '') {
            if (!$verified) {
                fr_log('oidc: e-mail address ' . $email . ' is not verified by the provider; existing accounts are not matched by it', 'warn');
            } elseif ($user = Users::findByEmail($email)) {
                $linked = self::str($user['oidc_subject'] ?? null);
                if ($linked !== '' && str_starts_with($linked, $issuer . '|')) {
                    throw new RuntimeException('The account with this e-mail address is linked to a different identity');
                }
                DB::update('users', ['oidc_subject' => $subject, 'updated_at' => fr_now()], 'id = ?', [(int) $user['id']]);
                Users::forget((int) $user['id']);
                return Users::find((int) $user['id']) ?? $user;
            }
        }

        if (!Settings::bool('oidc_auto_create')) {
            throw new RuntimeException('No account for this identity');
        }
        $name = self::str($c['name'] ?? null);
        if ($name === '') {
            $name = trim(self::str($c['given_name'] ?? null) . ' ' . self::str($c['family_name'] ?? null));
        }
        $base = self::str($c['preferred_username'] ?? null);
        if ($base === '' && $email !== '') {
            $base = (string) strstr($email, '@', true);
        }
        if ($base === '') {
            $base = 'user-' . substr(sha1($subject), 0, 8);
        }
        $username = self::uniqueUsername($base);
        $user = Users::create([
            'username' => $username,
            'email' => $email !== '' ? $email : null,
            'name' => $name,
            'oidc_subject' => $subject,
            'role' => Settings::get('default_role'),
        ], null);
        Activity::log('user_create', ['system' => true, 'details' => ['username' => $username, 'email' => $email, 'oidc' => true, 'auto' => true]]);
        return $user;
    }

    /** Turn a preferred username / e-mail local part into a valid, unused username. */
    private static function uniqueUsername(string $base): string
    {
        $base = preg_replace('/[^A-Za-z0-9._@+\-]+/', '_', trim($base)) ?? '';
        $base = ltrim($base, '._@+-'); // must start with a letter or digit
        $base = substr($base, 0, 60);
        if (strlen($base) < 2) {
            $base = 'user' . $base;
        }
        $name = $base;
        for ($i = 2; $i < 10000; $i++) {
            if (!Users::findByUsername($name)) {
                return $name;
            }
            $name = $base . $i;
        }
        return $base . '-' . Util::hex(4);
    }

    // ------------------------------------------------------------------
    // HTTP (curl, JSON, strict TLS)
    // ------------------------------------------------------------------

    private static function httpGet(string $url, array $headers = []): array
    {
        return self::http('GET', $url, null, $headers);
    }

    /** Form-encoded POST; $basic = [user, password] switches to HTTP Basic client authentication. */
    private static function httpPost(string $url, array $fields, ?array $basic = null): array
    {
        $body = http_build_query($fields, '', '&', PHP_QUERY_RFC3986);
        return self::http('POST', $url, $body, ['Content-Type: application/x-www-form-urlencoded'], $basic);
    }

    private static function http(string $method, string $url, ?string $body, array $headers = [], ?array $basic = null): array
    {
        if (!preg_match('#^https?://#i', $url)) {
            throw new RuntimeException('Refusing to contact a non-HTTP URL for OpenID Connect');
        }
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('curl is not available');
        }
        $headers[] = 'Accept: application/json';
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::HTTP_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => self::HTTP_TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_USERAGENT => 'FileRun-Web/' . FR_VERSION,
        ];
        if ($method === 'POST') {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = (string) $body;
        }
        if ($basic !== null) {
            // RFC 6749 §2.3.1: credentials are form-urlencoded before being used in Basic auth.
            $opts[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
            $opts[CURLOPT_USERPWD] = rawurlencode((string) $basic[0]) . ':' . rawurlencode((string) $basic[1]);
        }
        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        $host = (string) (parse_url($url, PHP_URL_HOST) ?: $url);
        if ($raw === false) {
            throw new RuntimeException('Could not reach ' . $host . ': ' . $err);
        }
        $data = json_decode((string) $raw, true);
        if ($status >= 400) {
            $detail = is_array($data) ? self::str($data['error_description'] ?? ($data['error'] ?? null)) : '';
            throw new RuntimeException($host . ' answered HTTP ' . $status . ($detail !== '' ? ': ' . $detail : ''));
        }
        if (!is_array($data)) {
            throw new RuntimeException($host . ' did not return a JSON document');
        }
        return $data;
    }

    // ------------------------------------------------------------------
    // Small helpers
    // ------------------------------------------------------------------

    /** Scalar to trimmed string; anything else becomes ''. */
    private static function str(mixed $v): string
    {
        return is_scalar($v) ? trim((string) $v) : '';
    }

    private static function withQuery(string $url, array $params): string
    {
        return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    /** DER TLV with definite length. */
    private static function der(int $tag, string $content): string
    {
        $len = strlen($content);
        if ($len < 0x80) {
            $lenBytes = chr($len);
        } else {
            $hex = dechex($len);
            if (strlen($hex) % 2 === 1) {
                $hex = '0' . $hex;
            }
            $bin = (string) hex2bin($hex);
            $lenBytes = chr(0x80 | strlen($bin)) . $bin;
        }
        return chr($tag) . $lenBytes . $content;
    }

    /** DER INTEGER from an unsigned big-endian magnitude. */
    private static function derInt(string $bin): string
    {
        $bin = ltrim($bin, "\x00");
        if ($bin === '') {
            $bin = "\x00";
        }
        if ((ord($bin[0]) & 0x80) !== 0) {
            $bin = "\x00" . $bin; // keep it positive
        }
        return self::der(0x02, $bin);
    }
}
