<?php
declare(strict_types=1);

/** RFC 6238 time-based one-time passwords and RFC 4648 base32. */
final class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function secret(int $bytes = 20): string
    {
        return self::base32Encode(random_bytes($bytes));
    }

    public static function uri(string $secret, string $account, string $issuer): string
    {
        return 'otpauth://totp/' . rawurlencode($issuer) . ':' . rawurlencode($account)
            . '?secret=' . $secret . '&issuer=' . rawurlencode($issuer) . '&algorithm=SHA1&digits=6&period=30';
    }

    public static function code(string $secret, ?int $time = null, int $period = 30, int $digits = 6): string
    {
        $counter = intdiv($time ?? time(), $period);
        $bin = pack('N*', 0) . pack('N*', $counter);
        $hash = hash_hmac('sha1', $bin, self::base32Decode($secret), true);
        $offset = ord($hash[19]) & 0x0f;
        $value = ((ord($hash[$offset]) & 0x7f) << 24) | ((ord($hash[$offset + 1]) & 0xff) << 16)
            | ((ord($hash[$offset + 2]) & 0xff) << 8) | (ord($hash[$offset + 3]) & 0xff);
        return str_pad((string) ($value % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
    }

    public static function verify(string $secret, string $code, int $window = 1): bool
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if (!preg_match('/^\d{6}$/', $code)) return false;
        $now = time();
        for ($i = -$window; $i <= $window; $i++) {
            if (hash_equals(self::code($secret, $now + $i * 30), $code)) return true;
        }
        return false;
    }

    public static function base32Encode(string $data): string
    {
        $bits = '';
        foreach (str_split($data) as $c) {
            $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
        }
        return $out;
    }

    public static function base32Decode(string $s): string
    {
        $s = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $s) ?? '');
        $bits = '';
        foreach (str_split($s) as $c) {
            $pos = strpos(self::ALPHABET, $c);
            if ($pos === false) continue;
            $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) $out .= chr(bindec($chunk));
        }
        return $out;
    }

    /** Ten one-time recovery codes in the form xxxx-xxxx. Returns [plain[], hashes[]]. */
    public static function recoveryCodes(int $count = 10): array
    {
        $plain = []; $hashes = [];
        for ($i = 0; $i < $count; $i++) {
            $code = substr(bin2hex(random_bytes(4)), 0, 4) . '-' . substr(bin2hex(random_bytes(4)), 0, 4);
            $plain[] = $code;
            $hashes[] = hash('sha256', $code);
        }
        return [$plain, $hashes];
    }
}
