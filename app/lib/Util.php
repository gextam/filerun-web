<?php
declare(strict_types=1);

/**
 * Small, dependency-free helpers used all over the application.
 */
final class Util
{
    /** @var array<string,string> extension => MIME type */
    private static array $mimes = [
        'txt' => 'text/plain', 'md' => 'text/markdown', 'markdown' => 'text/markdown', 'csv' => 'text/csv', 'tsv' => 'text/tab-separated-values',
        'log' => 'text/plain', 'ini' => 'text/plain', 'conf' => 'text/plain', 'cfg' => 'text/plain', 'nfo' => 'text/plain', 'srt' => 'text/plain', 'vtt' => 'text/vtt',
        'html' => 'text/html', 'htm' => 'text/html', 'xhtml' => 'application/xhtml+xml', 'css' => 'text/css', 'scss' => 'text/x-scss', 'less' => 'text/x-less',
        'js' => 'text/javascript', 'mjs' => 'text/javascript', 'ts' => 'text/typescript', 'jsx' => 'text/jsx', 'tsx' => 'text/tsx', 'json' => 'application/json', 'jsonl' => 'application/json',
        'xml' => 'application/xml', 'xsl' => 'application/xml', 'xsd' => 'application/xml', 'svg' => 'image/svg+xml', 'yml' => 'text/yaml', 'yaml' => 'text/yaml', 'toml' => 'text/plain',
        'php' => 'text/x-php', 'py' => 'text/x-python', 'rb' => 'text/x-ruby', 'go' => 'text/x-go', 'rs' => 'text/x-rust', 'java' => 'text/x-java', 'kt' => 'text/x-kotlin', 'swift' => 'text/x-swift',
        'c' => 'text/x-c', 'h' => 'text/x-c', 'cpp' => 'text/x-c++', 'hpp' => 'text/x-c++', 'cs' => 'text/x-csharp', 'sh' => 'text/x-sh', 'bash' => 'text/x-sh', 'bat' => 'text/plain', 'ps1' => 'text/plain',
        'sql' => 'text/x-sql', 'lua' => 'text/x-lua', 'pl' => 'text/x-perl', 'r' => 'text/x-r', 'dart' => 'text/x-dart', 'vue' => 'text/x-vue', 'tex' => 'text/x-tex', 'dockerfile' => 'text/plain', 'env' => 'text/plain',
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'jpe' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp', 'avif' => 'image/avif', 'bmp' => 'image/bmp',
        'ico' => 'image/x-icon', 'tif' => 'image/tiff', 'tiff' => 'image/tiff', 'heic' => 'image/heic', 'heif' => 'image/heif', 'psd' => 'image/vnd.adobe.photoshop', 'ai' => 'application/postscript', 'eps' => 'application/postscript',
        'cr2' => 'image/x-canon-cr2', 'cr3' => 'image/x-canon-cr3', 'nef' => 'image/x-nikon-nef', 'arw' => 'image/x-sony-arw', 'dng' => 'image/x-adobe-dng', 'raf' => 'image/x-fuji-raf', 'orf' => 'image/x-olympus-orf', 'rw2' => 'image/x-panasonic-rw2',
        'mp4' => 'video/mp4', 'm4v' => 'video/mp4', 'mov' => 'video/quicktime', 'webm' => 'video/webm', 'mkv' => 'video/x-matroska', 'avi' => 'video/x-msvideo', 'wmv' => 'video/x-ms-wmv', 'flv' => 'video/x-flv',
        'mpg' => 'video/mpeg', 'mpeg' => 'video/mpeg', '3gp' => 'video/3gpp', 'ogv' => 'video/ogg', 'ts' => 'video/mp2t', 'mts' => 'video/mp2t',
        'mp3' => 'audio/mpeg', 'wav' => 'audio/wav', 'flac' => 'audio/flac', 'aac' => 'audio/aac', 'm4a' => 'audio/mp4', 'ogg' => 'audio/ogg', 'oga' => 'audio/ogg', 'opus' => 'audio/opus', 'wma' => 'audio/x-ms-wma', 'aif' => 'audio/aiff', 'aiff' => 'audio/aiff', 'mid' => 'audio/midi', 'midi' => 'audio/midi',
        'pdf' => 'application/pdf', 'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'dot' => 'application/msword', 'dotx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.template',
        'xls' => 'application/vnd.ms-excel', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'xlsm' => 'application/vnd.ms-excel.sheet.macroEnabled.12',
        'ppt' => 'application/vnd.ms-powerpoint', 'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation', 'pps' => 'application/vnd.ms-powerpoint', 'ppsx' => 'application/vnd.openxmlformats-officedocument.presentationml.slideshow',
        'odt' => 'application/vnd.oasis.opendocument.text', 'ods' => 'application/vnd.oasis.opendocument.spreadsheet', 'odp' => 'application/vnd.oasis.opendocument.presentation', 'odg' => 'application/vnd.oasis.opendocument.graphics', 'rtf' => 'application/rtf',
        'epub' => 'application/epub+zip', 'mobi' => 'application/x-mobipocket-ebook', 'azw3' => 'application/vnd.amazon.ebook',
        'zip' => 'application/zip', 'rar' => 'application/vnd.rar', '7z' => 'application/x-7z-compressed', 'tar' => 'application/x-tar', 'gz' => 'application/gzip', 'tgz' => 'application/gzip', 'bz2' => 'application/x-bzip2', 'xz' => 'application/x-xz', 'zst' => 'application/zstd',
        'iso' => 'application/x-iso9660-image', 'dmg' => 'application/x-apple-diskimage', 'exe' => 'application/vnd.microsoft.portable-executable', 'msi' => 'application/x-msi', 'apk' => 'application/vnd.android.package-archive', 'deb' => 'application/vnd.debian.binary-package', 'rpm' => 'application/x-rpm',
        'ttf' => 'font/ttf', 'otf' => 'font/otf', 'woff' => 'font/woff', 'woff2' => 'font/woff2',
        'stl' => 'model/stl', 'obj' => 'model/obj', 'gltf' => 'model/gltf+json', 'glb' => 'model/gltf-binary', 'fbx' => 'application/octet-stream', '3ds' => 'application/x-3ds', 'dxf' => 'image/vnd.dxf', 'dwg' => 'image/vnd.dwg', 'step' => 'application/step', 'stp' => 'application/step',
        'sqlite' => 'application/vnd.sqlite3', 'db' => 'application/octet-stream', 'ics' => 'text/calendar', 'vcf' => 'text/vcard', 'eml' => 'message/rfc822', 'wasm' => 'application/wasm', 'bin' => 'application/octet-stream',
    ];

    public static function ext(string $name): string
    {
        $pos = strrpos($name, '.');
        if ($pos === false || $pos === 0) {
            return '';
        }
        return strtolower(substr($name, $pos + 1));
    }

    public static function mime(string $name, ?string $absPath = null): string
    {
        $ext = self::ext($name);
        if ($ext !== '' && isset(self::$mimes[$ext])) {
            return self::$mimes[$ext];
        }
        if ($absPath !== null && is_file($absPath) && function_exists('finfo_open')) {
            $f = finfo_open(FILEINFO_MIME_TYPE);
            if ($f) {
                $m = finfo_file($f, $absPath);
                finfo_close($f);
                if (is_string($m) && $m !== '') {
                    return $m;
                }
            }
        }
        return 'application/octet-stream';
    }

    /**
     * Coarse kind of a file, used by the UI to pick icons, viewers and editors.
     * One of: folder, image, raw, video, audio, pdf, text, code, markdown, doc, sheet,
     * slide, archive, font, ebook, model, font, other.
     */
    public static function kind(string $name, bool $isDir = false): string
    {
        if ($isDir) {
            return 'folder';
        }
        $ext = self::ext($name);
        static $map = null;
        if ($map === null) {
            $map = [];
            $groups = [
                'image' => 'jpg jpeg jpe png gif webp avif bmp ico tif tiff heic heif svg psd',
                'raw' => 'cr2 cr3 nef arw dng raf orf rw2',
                'video' => 'mp4 m4v mov webm mkv avi wmv flv mpg mpeg 3gp ogv mts',
                'audio' => 'mp3 wav flac aac m4a ogg oga opus wma aif aiff mid midi',
                'pdf' => 'pdf',
                'markdown' => 'md markdown',
                'text' => 'txt log ini conf cfg nfo srt vtt csv tsv env toml',
                'code' => 'html htm xhtml css scss less js mjs ts jsx tsx json jsonl xml xsl xsd yml yaml php py rb go rs java kt swift c h cpp hpp cs sh bash bat ps1 sql lua pl r dart vue tex dockerfile',
                'doc' => 'doc docx dot dotx odt rtf pages',
                'sheet' => 'xls xlsx xlsm ods numbers',
                'slide' => 'ppt pptx pps ppsx odp key',
                'archive' => 'zip rar 7z tar gz tgz bz2 xz zst iso dmg',
                'font' => 'ttf otf woff woff2',
                'ebook' => 'epub mobi azw3',
                'model' => 'stl obj gltf glb fbx 3ds dxf dwg step stp',
                'exe' => 'exe msi apk deb rpm',
            ];
            foreach ($groups as $kind => $exts) {
                foreach (explode(' ', $exts) as $e) {
                    $map[$e] = $kind;
                }
            }
        }
        return $map[$ext] ?? 'other';
    }

    public static function humanSize(int|float $bytes, int $decimals = 1): string
    {
        $bytes = (float) $bytes;
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return ($i === 0 ? (string) (int) $bytes : number_format($bytes, $decimals)) . ' ' . $units[$i];
    }

    /** Parse "2M", "512k", "1.5G" into bytes. */
    public static function parseSize(string|int|null $v): int
    {
        if ($v === null || $v === '') {
            return 0;
        }
        if (is_int($v)) {
            return $v;
        }
        $v = trim($v);
        if (preg_match('/^([0-9.]+)\s*([kmgtp]?)b?$/i', $v, $m)) {
            $n = (float) $m[1];
            $mult = ['' => 1, 'k' => 1024, 'm' => 1024 ** 2, 'g' => 1024 ** 3, 't' => 1024 ** 4, 'p' => 1024 ** 5][strtolower($m[2])];
            return (int) round($n * $mult);
        }
        return (int) $v;
    }

    /** Effective PHP upload limit in bytes (the smaller of upload_max_filesize and post_max_size). */
    public static function phpUploadLimit(): int
    {
        $u = self::parseSize((string) ini_get('upload_max_filesize'));
        $p = self::parseSize((string) ini_get('post_max_size'));
        $vals = array_filter([$u, $p], static fn($x) => $x > 0);
        return $vals ? min($vals) : 0;
    }

    public static function token(int $bytes = 32): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    public static function hex(int $bytes = 16): string
    {
        return bin2hex(random_bytes($bytes));
    }

    public static function uuid(): string
    {
        $d = random_bytes(16);
        $d[6] = chr((ord($d[6]) & 0x0f) | 0x40);
        $d[8] = chr((ord($d[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
    }

    public static function randomPassword(int $len = 14): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#%&*';
        $out = '';
        $max = strlen($alphabet) - 1;
        for ($i = 0; $i < $len; $i++) {
            $out .= $alphabet[random_int(0, $max)];
        }
        return $out;
    }

    /**
     * Sanitize a single file or folder name. Returns '' when the name is unusable.
     */
    public static function safeName(string $name): string
    {
        $name = str_replace(["\0", '/', '\\'], '', $name);
        $name = preg_replace('/[\x00-\x1f\x7f]/u', '', $name) ?? '';
        $name = trim($name);
        $name = rtrim($name, '. ');
        if ($name === '' || $name === '.' || $name === '..') {
            return '';
        }
        if (mb_strlen($name) > 255) {
            $ext = self::ext($name);
            $name = mb_substr($name, 0, 255 - ($ext ? strlen($ext) + 1 : 0)) . ($ext ? '.' . $ext : '');
        }
        return $name;
    }

    /**
     * Normalize a relative path: forward slashes, no empty / "." / ".." segments,
     * no leading or trailing slash. Returns '' for the root.
     */
    public static function normPath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $path = str_replace("\0", '', $path);
        $parts = [];
        foreach (explode('/', $path) as $seg) {
            $seg = trim($seg);
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = rtrim($seg, '. ') === '' ? $seg : $seg;
        }
        return implode('/', $parts);
    }

    public static function joinPath(string $a, string $b): string
    {
        $a = self::normPath($a);
        $b = self::normPath($b);
        if ($a === '') return $b;
        if ($b === '') return $a;
        return $a . '/' . $b;
    }

    public static function baseName(string $path): string
    {
        $path = self::normPath($path);
        $pos = strrpos($path, '/');
        return $pos === false ? $path : substr($path, $pos + 1);
    }

    public static function dirName(string $path): string
    {
        $path = self::normPath($path);
        $pos = strrpos($path, '/');
        return $pos === false ? '' : substr($path, 0, $pos);
    }

    /** True when $child equals $parent or is inside it. */
    public static function pathWithin(string $child, string $parent): bool
    {
        $child = self::normPath($child);
        $parent = self::normPath($parent);
        if ($parent === '') return true;
        return $child === $parent || str_starts_with($child, $parent . '/');
    }

    /** Split "name.ext" into [name, ext-with-dot]. */
    public static function splitExt(string $name): array
    {
        $ext = self::ext($name);
        if ($ext === '') {
            return [$name, ''];
        }
        return [substr($name, 0, -(strlen($ext) + 1)), '.' . $ext];
    }

    /** Produce "name (2).ext" style unique names. */
    public static function uniqueName(string $dir, string $name): string
    {
        if (!file_exists($dir . '/' . $name)) {
            return $name;
        }
        [$base, $ext] = self::splitExt($name);
        $base = preg_replace('/ \((\d+)\)$/', '', $base) ?? $base;
        for ($i = 2; $i < 10000; $i++) {
            $candidate = $base . ' (' . $i . ')' . $ext;
            if (!file_exists($dir . '/' . $candidate)) {
                return $candidate;
            }
        }
        return $base . ' ' . self::hex(4) . $ext;
    }

    public static function json(mixed $v, bool $pretty = false): string
    {
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;
        if ($pretty) $flags |= JSON_PRETTY_PRINT;
        return json_encode($v, $flags) ?: 'null';
    }

    /** Decode JSON leniently: returns $default for invalid/empty input. */
    public static function jsonDecode(?string $s, mixed $default = []): mixed
    {
        if ($s === null || $s === '') return $default;
        $v = json_decode($s, true);
        return $v === null && $s !== 'null' ? $default : $v;
    }

    public static function bool(mixed $v): bool
    {
        if (is_bool($v)) return $v;
        if (is_int($v) || is_float($v)) return $v != 0;
        $v = strtolower(trim((string) $v));
        return in_array($v, ['1', 'true', 'yes', 'on', 'y'], true);
    }

    /** Does $ip match a rule: exact IP, wildcard (10.0.*), CIDR (10.0.0.0/8) or range (a-b)? */
    public static function ipMatches(string $ip, string $rule): bool
    {
        $rule = trim($rule);
        if ($rule === '' || $rule === '*') return $rule === '*';
        if ($ip === $rule) return true;
        if (str_contains($rule, '/')) {
            return self::ipInCidr($ip, $rule);
        }
        if (str_contains($rule, '-')) {
            [$a, $b] = array_map('trim', explode('-', $rule, 2));
            $ipN = inet_pton($ip); $aN = inet_pton($a); $bN = inet_pton($b);
            if ($ipN === false || $aN === false || $bN === false || strlen($ipN) !== strlen($aN) || strlen($aN) !== strlen($bN)) return false;
            return strcmp($ipN, $aN) >= 0 && strcmp($ipN, $bN) <= 0;
        }
        if (str_contains($rule, '*')) {
            $re = '/^' . str_replace('\*', '[0-9a-f:.]*', preg_quote($rule, '/')) . '$/i';
            return (bool) preg_match($re, $ip);
        }
        return false;
    }

    public static function ipInCidr(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = array_pad(explode('/', $cidr, 2), 2, null);
        $ipN = @inet_pton($ip);
        $subN = @inet_pton(trim($subnet));
        if ($ipN === false || $subN === false || strlen($ipN) !== strlen($subN)) return false;
        $bits = $bits === null ? strlen($ipN) * 8 : (int) $bits;
        $bytes = intdiv($bits, 8);
        $rem = $bits % 8;
        if ($bytes > 0 && substr($ipN, 0, $bytes) !== substr($subN, 0, $bytes)) return false;
        if ($rem === 0) return true;
        $mask = (0xff << (8 - $rem)) & 0xff;
        return (ord($ipN[$bytes]) & $mask) === (ord($subN[$bytes]) & $mask);
    }

    /** Check an IP against a newline/comma separated list of rules. Empty list = allow all. */
    public static function ipAllowed(string $ip, ?string $rules): bool
    {
        $rules = trim((string) $rules);
        if ($rules === '') return true;
        foreach (preg_split('/[\s,;]+/', $rules) ?: [] as $rule) {
            if ($rule !== '' && self::ipMatches($ip, $rule)) return true;
        }
        return false;
    }

    /**
     * Run an external program without a shell. Returns [exitCode, stdout, stderr].
     * @param string[] $cmd
     */
    public static function run(array $cmd, int $timeoutSec = 60, ?string $stdin = null, ?string $cwd = null): array
    {
        if (!function_exists('proc_open')) {
            return [127, '', 'proc_open disabled'];
        }
        $spec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = @proc_open($cmd, $spec, $pipes, $cwd, null, ['bypass_shell' => true]);
        if (!is_resource($proc)) {
            return [127, '', 'failed to start ' . ($cmd[0] ?? '')];
        }
        if ($stdin !== null) {
            fwrite($pipes[0], $stdin);
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $out = ''; $err = '';
        $start = time();
        while (true) {
            $out .= (string) stream_get_contents($pipes[1]);
            $err .= (string) stream_get_contents($pipes[2]);
            $status = proc_get_status($proc);
            if (!$status['running']) {
                $out .= (string) stream_get_contents($pipes[1]);
                $err .= (string) stream_get_contents($pipes[2]);
                $code = $status['exitcode'];
                break;
            }
            if (time() - $start > $timeoutSec) {
                proc_terminate($proc, 9);
                $code = 124;
                $err .= "\ntimeout";
                break;
            }
            usleep(20000);
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
        return [$code, $out, $err];
    }

    /** Locate a binary in PATH (or configured override). */
    public static function bin(string $name): ?string
    {
        $override = fr_config('binaries.' . $name);
        if ($override) {
            return is_executable((string) $override) ? (string) $override : null;
        }
        static $cache = [];
        if (array_key_exists($name, $cache)) return $cache[$name];
        $paths = explode(PATH_SEPARATOR, (string) getenv('PATH'));
        $paths = array_merge($paths, ['/usr/local/bin', '/usr/bin', '/bin', '/opt/homebrew/bin']);
        foreach ($paths as $p) {
            $full = rtrim($p, '/') . '/' . $name;
            if (is_executable($full)) {
                return $cache[$name] = $full;
            }
            if (PHP_OS_FAMILY === 'Windows' && is_file($full . '.exe')) {
                return $cache[$name] = $full . '.exe';
            }
        }
        return $cache[$name] = null;
    }

    public static function startsWithAny(string $s, array $prefixes): bool
    {
        foreach ($prefixes as $p) {
            if ($p !== '' && str_starts_with($s, $p)) return true;
        }
        return false;
    }

    /** Case-insensitive natural sort comparator for names. */
    public static function natCmp(string $a, string $b): int
    {
        return strnatcasecmp($a, $b);
    }

    public static function clientName(?string $ua): string
    {
        $ua = (string) $ua;
        if ($ua === '') return 'Unknown';
        $patterns = [
            '/Microsoft-WebDAV-MiniRedir/i' => 'Windows Explorer (WebDAV)', '/WebDAVFS/i' => 'macOS Finder (WebDAV)', '/davfs2/i' => 'davfs2', '/rclone/i' => 'rclone', '/Cyberduck/i' => 'Cyberduck', '/Mountain Duck/i' => 'Mountain Duck', '/WinSCP/i' => 'WinSCP', '/GoodSync/i' => 'GoodSync', '/gvfs/i' => 'GNOME Files', '/curl/i' => 'curl',
            '/Edg\//i' => 'Edge', '/OPR\//i' => 'Opera', '/Chrome\//i' => 'Chrome', '/Firefox\//i' => 'Firefox', '/Safari\//i' => 'Safari',
        ];
        $browser = 'Browser';
        foreach ($patterns as $re => $name) {
            if (preg_match($re, $ua)) { $browser = $name; break; }
        }
        $os = '';
        foreach (['/Windows/i' => 'Windows', '/Android/i' => 'Android', '/iPhone|iPad/i' => 'iOS', '/Mac OS X|Macintosh/i' => 'macOS', '/Linux/i' => 'Linux', '/CrOS/i' => 'ChromeOS'] as $re => $name) {
            if (preg_match($re, $ua)) { $os = $name; break; }
        }
        return $os ? "$browser on $os" : $browser;
    }

    public static function truncate(string $s, int $len): string
    {
        return mb_strlen($s) > $len ? mb_substr($s, 0, $len - 1) . '…' : $s;
    }

    /** Recursively delete a directory (used for trash purge and temp cleanup). */
    public static function rmTree(string $dir): bool
    {
        if (is_link($dir) || is_file($dir)) {
            return @unlink($dir);
        }
        if (!is_dir($dir)) {
            return true;
        }
        $ok = true;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            /** @var SplFileInfo $f */
            $p = $f->getPathname();
            if ($f->isDir() && !$f->isLink()) {
                $ok = @rmdir($p) && $ok;
            } else {
                $ok = @unlink($p) && $ok;
            }
        }
        return @rmdir($dir) && $ok;
    }

    /** Recursively copy a directory tree. */
    public static function copyTree(string $src, string $dst): bool
    {
        if (is_file($src)) {
            return copy($src, $dst);
        }
        if (!is_dir($dst) && !@mkdir($dst, 0750, true)) {
            return false;
        }
        $ok = true;
        foreach (new DirectoryIterator($src) as $f) {
            if ($f->isDot()) continue;
            $s = $f->getPathname();
            $d = $dst . '/' . $f->getFilename();
            if ($f->isDir() && !$f->isLink()) {
                $ok = self::copyTree($s, $d) && $ok;
            } elseif (!$f->isLink()) {
                $ok = copy($s, $d) && $ok;
                @touch($d, $f->getMTime());
            }
        }
        return $ok;
    }

    /** Size of a directory tree in bytes with file count. */
    public static function treeSize(string $dir, int $limit = 2000000): array
    {
        $size = 0; $files = 0; $dirs = 0;
        if (is_file($dir)) {
            return ['size' => (int) filesize($dir), 'files' => 1, 'dirs' => 0];
        }
        if (!is_dir($dir)) {
            return ['size' => 0, 'files' => 0, 'dirs' => 0];
        }
        try {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
            foreach ($it as $f) {
                if ($f->isDir()) { $dirs++; } else { $files++; $size += $f->getSize(); }
                if ($files + $dirs > $limit) break;
            }
        } catch (Throwable) {
        }
        return ['size' => $size, 'files' => $files, 'dirs' => $dirs];
    }

    public static function dateIso(?int $ts): ?string
    {
        return $ts === null ? null : gmdate('c', $ts);
    }

    /** Short, user-facing representation of a count. */
    public static function plural(int $n, string $one, string $many): string
    {
        return $n . ' ' . ($n === 1 ? $one : $many);
    }
}
