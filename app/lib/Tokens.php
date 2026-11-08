<?php
declare(strict_types=1);

/**
 * Compact signed tokens (HMAC-SHA256) for short-lived capabilities such as a
 * one-time download URL handed to an office editor or an external viewer.
 * The payload is JSON; nothing is stored server side.
 */
final class Tokens
{
    private static function key(): string
    {
        $secret = (string) fr_config('secret', '');
        if ($secret === '') {
            throw new RuntimeException('config.php has no "secret"');
        }
        return hash('sha256', 'tokens|' . $secret, true);
    }

    private static function b64(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    private static function unb64(string $s): string|false
    {
        return base64_decode(strtr($s, '-_', '+/'), true);
    }

    /** Create a token valid for $ttl seconds. */
    public static function sign(array $payload, int $ttl): string
    {
        $payload['exp'] = time() + max(1, $ttl);
        $payload['jti'] = $payload['jti'] ?? bin2hex(random_bytes(6));
        $body = self::b64(Util::json($payload));
        return $body . '.' . self::b64(hash_hmac('sha256', $body, self::key(), true));
    }

    /** Verify and decode; returns null when invalid or expired. */
    public static function verify(?string $token): ?array
    {
        if (!$token || !str_contains($token, '.')) return null;
        [$body, $sig] = explode('.', $token, 2);
        $raw = self::unb64($sig);
        if ($raw === false || !hash_equals(hash_hmac('sha256', $body, self::key(), true), $raw)) return null;
        $json = self::unb64($body);
        if ($json === false) return null;
        $data = json_decode($json, true);
        if (!is_array($data) || ($data['exp'] ?? 0) < time()) return null;
        return $data;
    }

    /** Token that lets whoever holds it fetch one file (used for office editors / external viewers). */
    public static function fileAccess(Ctx $ctx, ?array $user, int $ttl = 3600, bool $write = false): string
    {
        return self::sign([
            't' => 'file',
            'p' => $ctx->vpath,
            'u' => $user['id'] ?? null,
            'l' => $ctx->link['hash'] ?? null,
            'w' => $write ? 1 : 0,
        ], $ttl);
    }

    /** Resolve a file-access token back to a context. */
    public static function fileCtx(string $token): ?Ctx
    {
        $d = self::verify($token);
        if (!$d || ($d['t'] ?? '') !== 'file') return null;
        $user = !empty($d['u']) ? Users::find((int) $d['u']) : null;
        $link = !empty($d['l']) ? Links::byHash((string) $d['l']) : null;
        if (!$user && !$link) return null;
        try {
            return Paths::resolveExisting((string) $d['p'], $user, $link);
        } catch (Throwable) {
            return null;
        }
    }
}
