<?php
declare(strict_types=1);

/**
 * Outgoing e-mail through PHP mail() or a minimal SMTP client (STARTTLS/SSL,
 * AUTH LOGIN/PLAIN). No external dependencies.
 */
final class Mailer
{
    public static ?string $lastError = null;

    public static function send(string $to, string $subject, string $html, ?string $text = null): bool
    {
        $to = trim($to);
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            self::$lastError = 'Invalid recipient';
            return false;
        }
        $transport = (string) Settings::get('mail_transport');
        if ($transport === 'disabled') {
            self::$lastError = 'Mail is disabled';
            return false;
        }
        $fromEmail = (string) Settings::get('mail_from') ?: ('no-reply@' . self::hostname());
        $fromName = (string) Settings::get('mail_from_name') ?: (string) Settings::get('app_name');
        $text = $text ?? trim(html_entity_decode(strip_tags(preg_replace('/<br\s*\/?>|<\/p>|<\/div>|<\/h\d>/i', "\n", $html) ?? $html), ENT_QUOTES, 'UTF-8'));
        $boundary = 'b_' . bin2hex(random_bytes(12));
        $headers = [
            'From: ' . self::encodeHeader($fromName) . ' <' . $fromEmail . '>',
            'Reply-To: ' . $fromEmail,
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
            'X-Mailer: FileRun-Web/' . FR_VERSION,
            'Date: ' . date('r'),
            'Message-ID: <' . bin2hex(random_bytes(10)) . '@' . self::hostname() . '>',
        ];
        $body = "--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($text))
            . "--$boundary\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($html)) . "--$boundary--\r\n";
        try {
            if ($transport === 'smtp' && Settings::get('smtp_host')) {
                return self::smtp($fromEmail, $to, self::encodeHeader($subject), $headers, $body);
            }
            $ok = @mail($to, self::encodeHeader($subject), $body, implode("\r\n", $headers), '-f' . escapeshellarg($fromEmail));
            if (!$ok) self::$lastError = 'mail() returned false';
            return $ok;
        } catch (Throwable $e) {
            self::$lastError = $e->getMessage();
            fr_log('mail failed: ' . $e->getMessage());
            return false;
        }
    }

    private static function hostname(): string
    {
        return (string) ($_SERVER['SERVER_NAME'] ?? ($_SERVER['HTTP_HOST'] ?? (gethostname() ?: 'localhost')));
    }

    private static function encodeHeader(string $s): string
    {
        return preg_match('/[^\x20-\x7e]/', $s) ? '=?UTF-8?B?' . base64_encode($s) . '?=' : $s;
    }

    /** Branded HTML wrapper used for every message. */
    public static function template(string $title, string $bodyHtml, ?string $ctaUrl = null, ?string $ctaLabel = null): string
    {
        $app = Http::escape((string) Settings::get('app_name'));
        $accent = preg_match('/^#[0-9a-fA-F]{3,8}$/', (string) Settings::get('accent_color')) ? (string) Settings::get('accent_color') : '#2563eb';
        $logo = (string) Settings::get('logo_url');
        $footer = Http::escape((string) Settings::get('email_footer'));
        $cta = $ctaUrl ? '<p style="margin:28px 0"><a href="' . Http::escape($ctaUrl) . '" style="background:' . $accent . ';color:#fff;text-decoration:none;padding:12px 22px;border-radius:8px;font-weight:600;display:inline-block">' . Http::escape($ctaLabel ?: 'Open') . '</a></p><p style="color:#6b7280;font-size:12px;word-break:break-all">' . Http::escape($ctaUrl) . '</p>' : '';
        $brand = $logo ? '<img src="' . Http::escape($logo) . '" alt="' . $app . '" style="max-height:40px">' : '<span style="font-size:20px;font-weight:700;color:' . $accent . '">' . $app . '</span>';
        return '<!doctype html><html><body style="margin:0;background:#f3f4f6;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#111827">'
            . '<div style="max-width:560px;margin:32px auto;background:#fff;border-radius:12px;overflow:hidden;border:1px solid #e5e7eb">'
            . '<div style="padding:20px 28px;border-bottom:1px solid #e5e7eb">' . $brand . '</div>'
            . '<div style="padding:28px;font-size:15px;line-height:1.55"><h2 style="margin:0 0 16px;font-size:20px">' . Http::escape($title) . '</h2>' . $bodyHtml . $cta . '</div>'
            . '<div style="padding:16px 28px;background:#f9fafb;color:#6b7280;font-size:12px">' . ($footer ?: $app . ' &middot; ' . Http::escape(fr_public_url('/'))) . '</div>'
            . '</div></body></html>';
    }

    // ------------------------------------------------------------------
    // Minimal SMTP client
    // ------------------------------------------------------------------

    private static function smtp(string $from, string $to, string $subject, array $headers, string $body): bool
    {
        $host = (string) Settings::get('smtp_host');
        $port = (int) Settings::get('smtp_port') ?: 587;
        $security = (string) Settings::get('smtp_security');
        $user = (string) Settings::get('smtp_user');
        $pass = (string) Settings::get('smtp_pass');
        $remote = ($security === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
        $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true]]);
        $fp = @stream_socket_client($remote, $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $ctx);
        if (!$fp) {
            throw new RuntimeException("SMTP connect failed: $errstr ($errno)");
        }
        stream_set_timeout($fp, 30);
        $read = static function () use ($fp): array {
            $lines = [];
            while (($line = fgets($fp, 1024)) !== false) {
                $lines[] = rtrim($line, "\r\n");
                if (strlen($line) < 4 || $line[3] !== '-') break;
            }
            $code = (int) substr($lines[0] ?? '000', 0, 3);
            return [$code, implode("\n", $lines)];
        };
        $cmd = static function (string $c, array $expect) use ($fp, $read): string {
            fwrite($fp, $c . "\r\n");
            [$code, $msg] = $read();
            if (!in_array($code, $expect, true)) {
                throw new RuntimeException('SMTP error after ' . explode(' ', $c)[0] . ': ' . $msg);
            }
            return $msg;
        };
        [$code] = $read();
        if ($code !== 220) throw new RuntimeException('SMTP server not ready');
        $ehlo = static fn() => $cmd('EHLO ' . self::hostname(), [250]);
        $caps = $ehlo();
        if ($security === 'tls') {
            $cmd('STARTTLS', [220]);
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('STARTTLS negotiation failed');
            }
            $caps = $ehlo();
        }
        if ($user !== '') {
            if (stripos($caps, 'AUTH') !== false && stripos($caps, 'PLAIN') !== false) {
                $cmd('AUTH PLAIN ' . base64_encode("\0$user\0$pass"), [235]);
            } else {
                $cmd('AUTH LOGIN', [334]);
                $cmd(base64_encode($user), [334]);
                $cmd(base64_encode($pass), [235]);
            }
        }
        $cmd('MAIL FROM:<' . $from . '>', [250]);
        $cmd('RCPT TO:<' . $to . '>', [250, 251]);
        $cmd('DATA', [354]);
        $data = 'To: ' . $to . "\r\nSubject: " . $subject . "\r\n" . implode("\r\n", $headers) . "\r\n\r\n" . $body;
        $data = preg_replace('/^\./m', '..', $data) ?? $data;
        fwrite($fp, $data . "\r\n.\r\n");
        [$code, $msg] = $read();
        if ($code !== 250) throw new RuntimeException('SMTP rejected the message: ' . $msg);
        fwrite($fp, "QUIT\r\n");
        fclose($fp);
        return true;
    }

    /** Used by the administration panel to test the configuration. */
    public static function test(string $to): array
    {
        $ok = self::send($to, 'Test message from ' . Settings::get('app_name'), self::template('It works', '<p>This is a test message sent from the e-mail settings page.</p>'));
        return ['ok' => $ok, 'error' => $ok ? null : self::$lastError];
    }
}
