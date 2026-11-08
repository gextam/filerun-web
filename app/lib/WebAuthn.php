<?php
declare(strict_types=1);

/**
 * Passkeys: a WebAuthn relying party implemented without external libraries.
 *
 * Two ceremonies are supported:
 *  - registration:   registrationOptions() -> navigator.credentials.create() -> register()
 *  - authentication: assertionOptions()    -> navigator.credentials.get()    -> authenticate()
 *
 * Everything binary (challenge, credential ids, clientDataJSON, attestation
 * object, authenticator data, signature, user handle) crosses the API as
 * base64url strings; the browser helper converts ArrayBuffers both ways.
 * Challenges are rows of {{tokens}} (type webauthn_reg / webauthn_auth) that
 * live for five minutes and are consumed exactly once. Credentials are rows of
 * {{passkeys}} holding the public key as a PEM SubjectPublicKeyInfo so that an
 * assertion signature can be checked with plain openssl_verify().
 *
 * Attestation statements are intentionally NOT verified. Verifying them needs
 * a trust store of authenticator vendor certificates (FIDO Metadata Service)
 * and buys nothing for a self-hosted file server: a passkey is registered from
 * within an already authenticated session, so what it guarantees is that every
 * later sign-in proves possession of the private key created at registration
 * ("self attestation" level of assurance). Only the authenticator data inside
 * the attestation object is used, whatever the "fmt" says.
 *
 * Supported credential algorithms: ES256 (COSE -7, P-256) and RS256 (COSE -257).
 * Requires ext/openssl. Every failure is reported as a RuntimeException whose
 * message is safe to show to the user.
 */
final class WebAuthn
{
    /** Seconds a challenge stays valid. */
    public const CHALLENGE_TTL = 300;
    /** Timeout hint handed to the browser, in milliseconds. */
    public const TIMEOUT_MS = 120000;
    public const TYPE_REG = 'webauthn_reg';
    public const TYPE_AUTH = 'webauthn_auth';
    public const ALG_ES256 = -7;
    public const ALG_RS256 = -257;

    private const FLAG_UP = 0x01;
    private const FLAG_UV = 0x04;
    private const FLAG_BE = 0x08;
    private const FLAG_BS = 0x10;
    private const FLAG_AT = 0x40;
    private const FLAG_ED = 0x80;

    private const MAX_CREDENTIAL_ID = 1024;  // VARBINARY(1024)
    private const MAX_BLOB = 131072;         // sanity cap for any base64url input
    private const CBOR_MAX_DEPTH = 32;
    private const KNOWN_TRANSPORTS = ['usb', 'nfc', 'ble', 'internal', 'hybrid', 'smart-card', 'cable'];

    // DER fragments used when building SubjectPublicKeyInfo structures.
    private const OID_EC_PUBLIC_KEY = "\x06\x07\x2a\x86\x48\xce\x3d\x02\x01";          // 1.2.840.10045.2.1
    private const OID_PRIME256V1 = "\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07";         // 1.2.840.10045.3.1.7
    private const OID_RSA_ENCRYPTION = "\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01"; // 1.2.840.113549.1.1.1
    private const DER_NULL = "\x05\x00";

    // ------------------------------------------------------------------
    // Relying party identity
    // ------------------------------------------------------------------

    /** Relying party id: the host name without port (config "webauthn_rp_id" overrides). */
    public static function rpId(): string
    {
        $configured = trim((string) fr_config('webauthn_rp_id', ''));
        if ($configured !== '') {
            return strtolower($configured);
        }
        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
        if ($host === '') {
            $host = (string) ((parse_url(self::origin(), PHP_URL_HOST) ?: null) ?? ($_SERVER['SERVER_NAME'] ?? 'localhost'));
        }
        return self::hostOnly($host);
    }

    /** Origin the browser must report in clientDataJSON: scheme://host[:port] of the public URL. */
    public static function origin(): string
    {
        $parts = parse_url(fr_public_url('/'));
        if (!is_array($parts) || empty($parts['host'])) {
            throw new RuntimeException('The public URL of this installation cannot be determined');
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        $host = strtolower((string) $parts['host']);
        $port = isset($parts['port']) ? (int) $parts['port'] : 0;
        $default = $scheme === 'https' ? 443 : 80;
        return $scheme . '://' . $host . ($port > 0 && $port !== $default ? ':' . $port : '');
    }

    // ------------------------------------------------------------------
    // Registration ceremony
    // ------------------------------------------------------------------

    /** PublicKeyCredentialCreationOptions for navigator.credentials.create(). */
    public static function registrationOptions(array $user): array
    {
        $userId = (int) ($user['id'] ?? 0);
        if ($userId <= 0) {
            throw new RuntimeException('A signed-in account is required to register a passkey');
        }
        [$challenge, $challengeId] = self::issueChallenge(self::TYPE_REG, $userId);
        $exclude = [];
        foreach (self::credentialsOf($userId) as $row) {
            $exclude[] = self::descriptor($row);
        }
        $username = (string) ($user['username'] ?? '');
        $displayName = trim((string) ($user['name'] ?? ''));
        return [
            'rp' => ['id' => self::rpId(), 'name' => (string) (Settings::get('app_name') ?: 'FileRun Web')],
            'user' => [
                'id' => self::b64uEncode(self::userHandle($userId)),
                'name' => $username,
                'displayName' => $displayName !== '' ? $displayName : $username,
            ],
            'challenge' => $challenge,
            'challenge_id' => $challengeId,
            'pubKeyCredParams' => [
                ['type' => 'public-key', 'alg' => self::ALG_ES256],
                ['type' => 'public-key', 'alg' => self::ALG_RS256],
            ],
            'timeout' => self::TIMEOUT_MS,
            'attestation' => 'none',
            'authenticatorSelection' => ['residentKey' => 'preferred', 'userVerification' => 'preferred'],
            'excludeCredentials' => $exclude,
        ];
    }

    /**
     * Verify the browser's PublicKeyCredential from credentials.create() and store it.
     *
     * @param array $credential {id, rawId, type, response: {clientDataJSON, attestationObject, transports?}} (base64url values)
     * @return array stored passkey description {id, name, created_at, last_used_at, transports, aaguid}
     */
    public static function register(array $user, array $credential, string $name = ''): array
    {
        $userId = (int) ($user['id'] ?? 0);
        if ($userId <= 0) {
            throw new RuntimeException('A signed-in account is required to register a passkey');
        }
        $response = self::responseOf($credential);
        $clientDataRaw = self::blob($response, 'clientDataJSON');
        $attestationRaw = self::blob($response, 'attestationObject');
        $rawId = self::credentialIdOf($credential);

        $client = self::clientData($clientDataRaw, 'webauthn.create');
        self::consumeChallenge(self::TYPE_REG, $client['challenge'], null, $userId);
        self::checkOrigin($client['origin']);

        [$attestation] = self::cborDecodeFirst($attestationRaw);
        if (!is_array($attestation)
            || !is_string($attestation['fmt'] ?? null)
            || !is_string($attestation['authData'] ?? null)
            || !is_array($attestation['attStmt'] ?? null)) {
            throw new RuntimeException('Malformed attestation object');
        }
        // $attestation['attStmt'] is deliberately left unverified (see the class
        // docblock): fmt "none" and every other format are treated alike.
        $auth = self::parseAuthData($attestation['authData']);
        self::checkAuthData($auth);
        if (!$auth['at'] || $auth['credentialId'] === null || $auth['credentialPublicKey'] === null) {
            throw new RuntimeException('The attestation carries no credential data');
        }
        if (!hash_equals($auth['credentialId'], $rawId)) {
            throw new RuntimeException('The credential id does not match the attested credential');
        }
        $pem = self::coseKeyToPem($auth['credentialPublicKey']);
        if (DB::one('SELECT id FROM {{passkeys}} WHERE credential_id = UNHEX(?) LIMIT 1', [bin2hex($rawId)])) {
            throw new RuntimeException('This passkey is already registered');
        }

        $transports = self::cleanTransports($response['transports'] ?? null);
        $name = self::cleanName($name);
        $aaguid = self::formatAaguid((string) $auth['aaguid']);
        $now = fr_now();
        DB::q(
            'INSERT INTO {{passkeys}} (`user_id`,`credential_id`,`public_key`,`sign_count`,`name`,`transports`,`aaguid`,`created_at`)'
            . ' VALUES (?,UNHEX(?),?,?,?,?,?,?)',
            [$userId, bin2hex($rawId), $pem, $auth['signCount'], $name, $transports ? implode(',', $transports) : null, $aaguid, $now]
        );
        $id = DB::lastId();
        Activity::log('passkey_add', ['user' => $user, 'details' => ['passkey_id' => $id, 'name' => $name, 'aaguid' => $aaguid, 'transports' => $transports]]);
        return ['id' => $id, 'name' => $name, 'created_at' => $now, 'last_used_at' => null, 'transports' => $transports, 'aaguid' => $aaguid];
    }

    // ------------------------------------------------------------------
    // Authentication ceremony
    // ------------------------------------------------------------------

    /**
     * PublicKeyCredentialRequestOptions for navigator.credentials.get().
     * With a user: allowCredentials lists that account's passkeys. Without:
     * discoverable-credential ("username-less") sign-in, allowCredentials = [].
     * 'challenge_id' is the {{tokens}} row id; the client hands it back to authenticate().
     */
    public static function assertionOptions(?array $user): array
    {
        $userId = $user !== null ? (int) ($user['id'] ?? 0) : null;
        if ($userId !== null && $userId <= 0) {
            throw new RuntimeException('Invalid user');
        }
        [$challenge, $challengeId] = self::issueChallenge(self::TYPE_AUTH, $userId);
        $allow = [];
        if ($userId !== null) {
            foreach (self::credentialsOf($userId) as $row) {
                $allow[] = self::descriptor($row);
            }
        }
        return [
            'challenge' => $challenge,
            'challenge_id' => $challengeId,
            'rpId' => self::rpId(),
            'timeout' => self::TIMEOUT_MS,
            'userVerification' => 'preferred',
            'allowCredentials' => $allow,
        ];
    }

    /**
     * Verify an assertion from credentials.get() and return the owning user
     * (Users::find()). The caller signs the user in (Auth::establish) and that
     * is where the 'login' activity gets logged.
     *
     * @param array $credential {id, rawId, type, response: {clientDataJSON, authenticatorData, signature, userHandle?}}
     */
    public static function authenticate(array $credential, ?int $challengeId = null): array
    {
        $response = self::responseOf($credential);
        $clientDataRaw = self::blob($response, 'clientDataJSON');
        $authDataRaw = self::blob($response, 'authenticatorData');
        $signature = self::blob($response, 'signature');
        $rawId = self::credentialIdOf($credential);
        $userHandle = null;
        if (isset($response['userHandle']) && is_string($response['userHandle']) && $response['userHandle'] !== '') {
            $userHandle = self::b64uDecode($response['userHandle']);
        }

        $client = self::clientData($clientDataRaw, 'webauthn.get');
        $token = self::consumeChallenge(self::TYPE_AUTH, $client['challenge'], $challengeId, null);
        self::checkOrigin($client['origin']);

        $row = DB::one('SELECT * FROM {{passkeys}} WHERE credential_id = UNHEX(?) LIMIT 1', [bin2hex($rawId)]);
        if (!$row) {
            throw new RuntimeException('This passkey is not registered here');
        }
        $ownerId = (int) $row['user_id'];
        if ($token['user_id'] !== null && (int) $token['user_id'] !== $ownerId) {
            throw new RuntimeException('This passkey belongs to a different account');
        }
        if ($userHandle !== null && !hash_equals(self::userHandle($ownerId), $userHandle)) {
            throw new RuntimeException('The passkey user handle does not match its account');
        }

        $auth = self::parseAuthData($authDataRaw);
        self::checkAuthData($auth);

        $key = openssl_pkey_get_public((string) $row['public_key']);
        if ($key === false) {
            throw new RuntimeException('The stored public key of this passkey is unreadable');
        }
        // Signed data: authenticatorData || SHA-256(clientDataJSON). ES256 signatures
        // arrive DER-encoded, RS256 is PKCS#1 v1.5 - exactly what openssl_verify expects.
        $signed = $authDataRaw . hash('sha256', $clientDataRaw, true);
        if (openssl_verify($signed, $signature, $key, OPENSSL_ALGO_SHA256) !== 1) {
            throw new RuntimeException('The passkey signature is invalid');
        }

        $stored = (int) $row['sign_count'];
        $fresh = (int) $auth['signCount'];
        if ($stored > 0 && $fresh <= $stored) {
            fr_log('passkey #' . $row['id'] . ' of user #' . $ownerId . ' presented signature counter ' . $fresh . ' <= stored ' . $stored . ' (possible cloned authenticator)', 'warning');
            throw new RuntimeException('This passkey was rejected because its usage counter went backwards (possible cloned authenticator)');
        }
        DB::update('passkeys', ['sign_count' => $fresh, 'last_used_at' => fr_now()], 'id = ?', [(int) $row['id']]);

        $user = Users::find($ownerId);
        if (!$user) {
            throw new RuntimeException('The account of this passkey no longer exists');
        }
        return $user;
    }

    // ------------------------------------------------------------------
    // Account page helpers
    // ------------------------------------------------------------------

    /** Passkeys of an account, newest first. */
    public static function listFor(int $userId): array
    {
        $rows = DB::all(
            'SELECT id, name, created_at, last_used_at, transports, aaguid FROM {{passkeys}} WHERE user_id = ? ORDER BY created_at DESC, id DESC',
            [$userId]
        );
        return array_map([self::class, 'describe'], $rows);
    }

    public static function rename(int $userId, int $id, string $name): void
    {
        $name = mb_substr(trim($name), 0, 150);
        if ($name === '') {
            throw new RuntimeException('The passkey name cannot be empty');
        }
        self::owned($userId, $id);
        DB::update('passkeys', ['name' => $name], 'id = ? AND user_id = ?', [$id, $userId]);
    }

    public static function delete(int $userId, int $id): void
    {
        $row = self::owned($userId, $id);
        DB::delete('passkeys', 'id = ? AND user_id = ?', [$id, $userId]);
        Activity::log('passkey_remove', [
            'user' => Users::find($userId),
            'details' => ['passkey_id' => $id, 'name' => $row['name'], 'aaguid' => $row['aaguid']],
        ]);
    }

    // ------------------------------------------------------------------
    // COSE key -> PEM (public so it can be unit-tested without a database)
    // ------------------------------------------------------------------

    /**
     * Convert a decoded COSE_Key map (RFC 9052) into a PEM SubjectPublicKeyInfo.
     * EC2 / P-256 (ES256) and RSA (RS256) are supported.
     */
    public static function coseKeyToPem(array $cose): string
    {
        $kty = $cose[1] ?? null;
        $alg = $cose[3] ?? null;
        if ($kty === 2) {
            if ($alg !== null && $alg !== self::ALG_ES256) {
                throw new RuntimeException('Unsupported EC credential algorithm ' . self::str($alg) . ' (only ES256 is accepted)');
            }
            if (($cose[-1] ?? null) !== 1) {
                throw new RuntimeException('Unsupported elliptic curve (only P-256 is accepted)');
            }
            $x = self::fixedLength($cose[-2] ?? null, 32, 'x');
            $y = self::fixedLength($cose[-3] ?? null, 32, 'y');
            // SubjectPublicKeyInfo { AlgorithmIdentifier { ecPublicKey, prime256v1 }, BIT STRING uncompressed point }
            $spki = self::derTlv(0x30,
                self::derTlv(0x30, self::OID_EC_PUBLIC_KEY . self::OID_PRIME256V1)
                . self::derBitString("\x04" . $x . $y)
            );
        } elseif ($kty === 3) {
            if ($alg !== null && $alg !== self::ALG_RS256) {
                throw new RuntimeException('Unsupported RSA credential algorithm ' . self::str($alg) . ' (only RS256 is accepted)');
            }
            $n = $cose[-1] ?? null;
            $e = $cose[-2] ?? null;
            if (!is_string($n) || !is_string($e) || ltrim($n, "\0") === '' || ltrim($e, "\0") === '') {
                throw new RuntimeException('Malformed RSA credential public key');
            }
            if (strlen(ltrim($n, "\0")) < 256) {
                throw new RuntimeException('The RSA credential key is too small (2048 bits required)');
            }
            // SubjectPublicKeyInfo { AlgorithmIdentifier { rsaEncryption, NULL }, BIT STRING RSAPublicKey { n, e } }
            $spki = self::derTlv(0x30,
                self::derTlv(0x30, self::OID_RSA_ENCRYPTION . self::DER_NULL)
                . self::derBitString(self::derTlv(0x30, self::derInteger($n) . self::derInteger($e)))
            );
        } else {
            throw new RuntimeException('Unsupported credential key type ' . self::str($kty));
        }
        $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
        if (openssl_pkey_get_public($pem) === false) {
            throw new RuntimeException('The credential public key could not be loaded');
        }
        return $pem;
    }

    // ------------------------------------------------------------------
    // Challenges
    // ------------------------------------------------------------------

    /** @return array{0:string,1:int} [base64url challenge, token row id] */
    private static function issueChallenge(string $type, ?int $userId): array
    {
        $now = time();
        // Housekeeping: finished ceremonies and, for registrations, the user's previous unfinished one.
        DB::delete('tokens', "type IN ('webauthn_reg','webauthn_auth') AND expires_at < ?", [gmdate('Y-m-d H:i:s', $now)]);
        if ($type === self::TYPE_REG && $userId !== null) {
            DB::delete('tokens', 'user_id = ? AND type = ? AND used_at IS NULL', [$userId, $type]);
        }
        $challenge = self::b64uEncode(random_bytes(32));
        $id = DB::insert('tokens', [
            'user_id' => $userId,
            'type' => $type,
            'token_hash' => hash('sha256', $challenge),
            'data' => Util::json(['challenge' => $challenge, 'user_id' => $userId]),
            'expires_at' => gmdate('Y-m-d H:i:s', $now + self::CHALLENGE_TTL),
            'created_at' => fr_now(),
        ]);
        return [$challenge, $id];
    }

    /**
     * Locate a live challenge by its hash and mark it used in one atomic step,
     * so a challenge can never be redeemed twice, even by concurrent requests.
     */
    private static function consumeChallenge(string $type, string $challenge, ?int $tokenId, ?int $userId): array
    {
        $sql = 'SELECT * FROM {{tokens}} WHERE type = ? AND token_hash = ? AND used_at IS NULL AND expires_at > ?';
        $params = [$type, hash('sha256', $challenge), fr_now()];
        if ($tokenId !== null) {
            $sql .= ' AND id = ?';
            $params[] = $tokenId;
        }
        if ($userId !== null) {
            $sql .= ' AND user_id = ?';
            $params[] = $userId;
        }
        $row = DB::one($sql, $params);
        if (!$row) {
            throw new RuntimeException('The passkey challenge is unknown, expired or already used. Please try again.');
        }
        $data = Util::jsonDecode($row['data'] ?? null, []);
        if (!is_array($data) || !hash_equals((string) ($data['challenge'] ?? ''), $challenge)) {
            throw new RuntimeException('The passkey challenge does not match');
        }
        if (DB::update('tokens', ['used_at' => fr_now()], 'id = ? AND used_at IS NULL', [(int) $row['id']]) !== 1) {
            throw new RuntimeException('The passkey challenge was already used');
        }
        return $row;
    }

    // ------------------------------------------------------------------
    // Request parsing and checks
    // ------------------------------------------------------------------

    private static function responseOf(array $credential): array
    {
        if (($credential['type'] ?? 'public-key') !== 'public-key') {
            throw new RuntimeException('Unexpected credential type');
        }
        $response = $credential['response'] ?? null;
        if (!is_array($response)) {
            throw new RuntimeException('The credential has no response');
        }
        return $response;
    }

    private static function credentialIdOf(array $credential): string
    {
        $encoded = $credential['rawId'] ?? ($credential['id'] ?? null);
        if (!is_string($encoded) || $encoded === '' || strlen($encoded) > self::MAX_BLOB) {
            throw new RuntimeException('The credential id is missing');
        }
        $raw = self::b64uDecode($encoded);
        if ($raw === '' || strlen($raw) > self::MAX_CREDENTIAL_ID) {
            throw new RuntimeException('The credential id has an invalid length');
        }
        return $raw;
    }

    /** Fetch and decode a base64url field of the credential response. */
    private static function blob(array $response, string $key): string
    {
        $v = $response[$key] ?? null;
        if (!is_string($v) || $v === '' || strlen($v) > self::MAX_BLOB) {
            throw new RuntimeException('The credential response lacks ' . $key);
        }
        $raw = self::b64uDecode($v);
        if ($raw === '') {
            throw new RuntimeException('The credential response lacks ' . $key);
        }
        return $raw;
    }

    /** @return array{type:string,challenge:string,origin:string} challenge is re-encoded canonically */
    private static function clientData(string $raw, string $expectedType): array
    {
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new RuntimeException('Malformed clientDataJSON');
        }
        $type = $data['type'] ?? null;
        $challenge = $data['challenge'] ?? null;
        $origin = $data['origin'] ?? null;
        if (!is_string($type) || !is_string($challenge) || !is_string($origin)) {
            throw new RuntimeException('Malformed clientDataJSON');
        }
        if ($type !== $expectedType) {
            throw new RuntimeException('Unexpected ceremony type "' . $type . '" (expected ' . $expectedType . ')');
        }
        if (!empty($data['crossOrigin'])) {
            throw new RuntimeException('Cross-origin passkey ceremonies are not allowed');
        }
        // Canonical (unpadded) base64url so the hash matches the one stored at issue time.
        return ['type' => $type, 'challenge' => self::b64uEncode(self::b64uDecode($challenge)), 'origin' => $origin];
    }

    private static function checkOrigin(string $origin): void
    {
        $expected = self::origin();
        if (strtolower(rtrim($origin, '/')) !== strtolower($expected)) {
            throw new RuntimeException('Origin mismatch: the request came from "' . $origin . '" but this installation is served from "' . $expected . '"');
        }
    }

    private static function checkAuthData(array $auth): void
    {
        if (!hash_equals(hash('sha256', self::rpId(), true), (string) $auth['rpIdHash'])) {
            throw new RuntimeException('The passkey was issued for a different site (relying party "' . self::rpId() . '")');
        }
        if (!$auth['up']) {
            throw new RuntimeException('The authenticator did not confirm user presence');
        }
    }

    /**
     * Split authenticator data (WebAuthn 6.1): rpIdHash(32) flags(1) signCount(4)
     * [aaguid(16) credIdLen(2) credId credentialPublicKey(CBOR)] [extensions(CBOR)].
     */
    private static function parseAuthData(string $data): array
    {
        $len = strlen($data);
        if ($len < 37) {
            throw new RuntimeException('Authenticator data is too short');
        }
        $flags = ord($data[32]);
        $out = [
            'rpIdHash' => substr($data, 0, 32),
            'flags' => $flags,
            'up' => (bool) ($flags & self::FLAG_UP),
            'uv' => (bool) ($flags & self::FLAG_UV),
            'be' => (bool) ($flags & self::FLAG_BE),
            'bs' => (bool) ($flags & self::FLAG_BS),
            'at' => (bool) ($flags & self::FLAG_AT),
            'ed' => (bool) ($flags & self::FLAG_ED),
            'signCount' => unpack('N', substr($data, 33, 4))[1],
            'aaguid' => null,
            'credentialId' => null,
            'credentialPublicKey' => null,
        ];
        $pos = 37;
        if ($out['at']) {
            if ($len < $pos + 18) {
                throw new RuntimeException('Authenticator data is truncated (attested credential data)');
            }
            $out['aaguid'] = substr($data, $pos, 16);
            $pos += 16;
            $credLen = unpack('n', substr($data, $pos, 2))[1];
            $pos += 2;
            if ($credLen === 0 || $credLen > 1023 || $len < $pos + $credLen) {
                throw new RuntimeException('Authenticator data has an invalid credential id length');
            }
            $out['credentialId'] = substr($data, $pos, $credLen);
            $pos += $credLen;
            [$key, $used] = self::cborDecodeFirst(substr($data, $pos));
            if (!is_array($key)) {
                throw new RuntimeException('Malformed credential public key');
            }
            $out['credentialPublicKey'] = $key;
            $pos += $used;
        }
        if ($out['ed'] && $pos < $len) {
            // Extension outputs: decoded only to make sure the structure is sound, none are used.
            self::cborDecodeFirst(substr($data, $pos));
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // Small helpers
    // ------------------------------------------------------------------

    /** Stable user.id handed to the authenticator (<= 64 bytes, not personal data). */
    private static function userHandle(int $userId): string
    {
        return 'u:' . $userId;
    }

    private static function credentialsOf(int $userId): array
    {
        return DB::all('SELECT id, credential_id, transports FROM {{passkeys}} WHERE user_id = ? ORDER BY id', [$userId]);
    }

    /** PublicKeyCredentialDescriptor for allow / exclude lists. */
    private static function descriptor(array $row): array
    {
        $d = ['type' => 'public-key', 'id' => self::b64uEncode((string) $row['credential_id'])];
        $transports = self::splitTransports($row['transports'] ?? null);
        if ($transports) {
            $d['transports'] = $transports;
        }
        return $d;
    }

    private static function describe(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'name' => (string) $r['name'],
            'created_at' => $r['created_at'],
            'last_used_at' => $r['last_used_at'] ?? null,
            'transports' => self::splitTransports($r['transports'] ?? null),
            'aaguid' => $r['aaguid'] ?? null,
        ];
    }

    private static function owned(int $userId, int $id): array
    {
        $row = DB::one('SELECT * FROM {{passkeys}} WHERE id = ? AND user_id = ?', [$id, $userId]);
        if (!$row) {
            throw new RuntimeException('Passkey not found');
        }
        return $row;
    }

    private static function cleanName(string $name): string
    {
        $name = mb_substr(trim($name), 0, 150);
        return $name === '' ? 'Passkey' : $name;
    }

    /** Keep only well-formed transport hints, unique, bounded to the VARCHAR(200) column. */
    private static function cleanTransports(mixed $list): array
    {
        if (!is_array($list)) {
            return [];
        }
        $out = [];
        foreach ($list as $t) {
            if (!is_string($t)) continue;
            $t = strtolower(trim($t));
            if (!in_array($t, self::KNOWN_TRANSPORTS, true) && !preg_match('/^[a-z][a-z0-9-]{0,19}$/', $t)) continue;
            if (!in_array($t, $out, true)) $out[] = $t;
            if (count($out) >= 8) break;
        }
        while (strlen(implode(',', $out)) > 200) {
            array_pop($out);
        }
        return $out;
    }

    private static function splitTransports(?string $csv): array
    {
        $csv = trim((string) $csv);
        return $csv === '' ? [] : array_values(array_filter(array_map('trim', explode(',', $csv)), static fn($t) => $t !== ''));
    }

    /** 16 raw bytes -> canonical lowercase UUID text (8-4-4-4-12). */
    private static function formatAaguid(string $raw): ?string
    {
        if (strlen($raw) !== 16) {
            return null;
        }
        $hex = bin2hex($raw);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
    }

    private static function hostOnly(string $host): string
    {
        $host = strtolower(trim($host));
        if (str_starts_with($host, '[')) { // [::1]:8080
            $end = strpos($host, ']');
            return $end === false ? trim($host, '[]') : substr($host, 1, $end - 1);
        }
        $colon = strpos($host, ':');
        return $colon === false ? $host : substr($host, 0, $colon);
    }

    /** Left-pad a big-endian integer to $len bytes, rejecting anything that does not fit. */
    private static function fixedLength(mixed $v, int $len, string $what): string
    {
        if (!is_string($v) || $v === '') {
            throw new RuntimeException('Malformed EC credential public key (' . $what . ')');
        }
        $v = ltrim($v, "\0");
        if (strlen($v) > $len) {
            throw new RuntimeException('Malformed EC credential public key (' . $what . ' too long)');
        }
        return str_pad($v, $len, "\0", STR_PAD_LEFT);
    }

    private static function str(mixed $v): string
    {
        return is_scalar($v) ? (string) $v : gettype($v);
    }

    // ------------------------------------------------------------------
    // base64url
    // ------------------------------------------------------------------

    private static function b64uEncode(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    /** Strict base64url (also tolerates standard base64 and padding). */
    private static function b64uDecode(string $s): string
    {
        $s = rtrim(strtr(trim($s), '-_', '+/'), '=');
        $rem = strlen($s) % 4;
        if ($rem === 1) {
            throw new RuntimeException('Invalid base64url data');
        }
        if ($rem !== 0) {
            $s .= str_repeat('=', 4 - $rem);
        }
        $bin = base64_decode($s, true);
        if ($bin === false) {
            throw new RuntimeException('Invalid base64url data');
        }
        return $bin;
    }

    // ------------------------------------------------------------------
    // DER encoding (just enough for SubjectPublicKeyInfo)
    // ------------------------------------------------------------------

    private static function derLength(int $len): string
    {
        if ($len < 0x80) {
            return chr($len);
        }
        $bytes = ltrim(pack('N', $len), "\0");
        return chr(0x80 | strlen($bytes)) . $bytes;
    }

    private static function derTlv(int $tag, string $content): string
    {
        return chr($tag) . self::derLength(strlen($content)) . $content;
    }

    /** INTEGER from an unsigned big-endian byte string. */
    private static function derInteger(string $unsigned): string
    {
        $v = ltrim($unsigned, "\0");
        if ($v === '') {
            $v = "\0";
        } elseif (ord($v[0]) & 0x80) {
            $v = "\0" . $v;
        }
        return self::derTlv(0x02, $v);
    }

    private static function derBitString(string $bytes): string
    {
        return self::derTlv(0x03, "\0" . $bytes);
    }

    // ------------------------------------------------------------------
    // CBOR decoding (RFC 8949, definite lengths only - all WebAuthn needs)
    // ------------------------------------------------------------------

    /** @return array{0:mixed,1:int} [decoded item, number of bytes consumed] */
    private static function cborDecodeFirst(string $data): array
    {
        $pos = 0;
        $value = self::cborDecode($data, $pos, 0);
        return [$value, $pos];
    }

    private static function cborDecode(string $data, int &$pos, int $depth): mixed
    {
        if ($depth > self::CBOR_MAX_DEPTH) {
            throw new RuntimeException('CBOR nesting too deep');
        }
        $initial = self::cborByte($data, $pos);
        $major = $initial >> 5;
        $info = $initial & 0x1f;
        if ($info === 31) {
            throw new RuntimeException('Indefinite-length CBOR items are not supported');
        }
        if ($major === 7) {
            return self::cborSimple($data, $pos, $info);
        }
        $arg = self::cborArgument($data, $pos, $info);
        switch ($major) {
            case 0: // unsigned integer
                return $arg;
            case 1: // negative integer
                return -1 - $arg;
            case 2: // byte string
            case 3: // text string
                return self::cborTake($data, $pos, $arg);
            case 4: // array
                self::cborCountGuard($data, $pos, $arg);
                $out = [];
                for ($i = 0; $i < $arg; $i++) {
                    $out[] = self::cborDecode($data, $pos, $depth + 1);
                }
                return $out;
            case 5: // map
                self::cborCountGuard($data, $pos, $arg * 2);
                $out = [];
                for ($i = 0; $i < $arg; $i++) {
                    $k = self::cborDecode($data, $pos, $depth + 1);
                    if (!is_int($k) && !is_string($k)) {
                        throw new RuntimeException('Unsupported CBOR map key type');
                    }
                    $out[$k] = self::cborDecode($data, $pos, $depth + 1);
                }
                return $out;
            default: // 6: tag - the tag number is dropped, the tagged item returned
                return self::cborDecode($data, $pos, $depth + 1);
        }
    }

    /** The "argument" of a data item: the value, length, tag number or simple value. */
    private static function cborArgument(string $data, int &$pos, int $info): int
    {
        if ($info < 24) {
            return $info;
        }
        if ($info === 24) {
            return self::cborByte($data, $pos);
        }
        if ($info === 25) {
            return unpack('n', self::cborTake($data, $pos, 2))[1];
        }
        if ($info === 26) {
            return unpack('N', self::cborTake($data, $pos, 4))[1];
        }
        if ($info === 27) {
            $v = unpack('J', self::cborTake($data, $pos, 8))[1];
            if ($v < 0) {
                throw new RuntimeException('CBOR integer exceeds the supported range');
            }
            return $v;
        }
        throw new RuntimeException('Reserved CBOR additional information ' . $info);
    }

    private static function cborSimple(string $data, int &$pos, int $info): mixed
    {
        if ($info < 24) {
            return match ($info) {
                20 => false,
                21 => true,
                22, 23 => null, // null, undefined
                default => throw new RuntimeException('Unassigned CBOR simple value ' . $info),
            };
        }
        if ($info === 24) {
            $v = self::cborByte($data, $pos);
            throw new RuntimeException('Unassigned CBOR simple value ' . $v);
        }
        if ($info === 25) {
            return self::halfFloat(unpack('n', self::cborTake($data, $pos, 2))[1]);
        }
        if ($info === 26) {
            return unpack('G', self::cborTake($data, $pos, 4))[1];
        }
        if ($info === 27) {
            return unpack('E', self::cborTake($data, $pos, 8))[1];
        }
        throw new RuntimeException('Reserved CBOR additional information ' . $info);
    }

    private static function halfFloat(int $h): float
    {
        $sign = ($h & 0x8000) ? -1.0 : 1.0;
        $exp = ($h >> 10) & 0x1f;
        $mant = $h & 0x3ff;
        if ($exp === 0) {
            return $sign * $mant * (2 ** -24);
        }
        if ($exp === 31) {
            return $mant === 0 ? $sign * INF : NAN;
        }
        return $sign * ($mant + 1024) * (2 ** ($exp - 25));
    }

    private static function cborByte(string $data, int &$pos): int
    {
        if ($pos >= strlen($data)) {
            throw new RuntimeException('Truncated CBOR data');
        }
        return ord($data[$pos++]);
    }

    private static function cborTake(string $data, int &$pos, int $len): string
    {
        if ($len < 0 || $len > strlen($data) - $pos) {
            throw new RuntimeException('Truncated CBOR data');
        }
        $chunk = substr($data, $pos, $len);
        $pos += $len;
        return $chunk;
    }

    /** Every element needs at least one byte: reject absurd element counts before looping. */
    private static function cborCountGuard(string $data, int $pos, int $count): void
    {
        if ($count < 0 || $count > strlen($data) - $pos) {
            throw new RuntimeException('Truncated CBOR data');
        }
    }
}
