<?php
declare(strict_types=1);

/**
 * Extracts the text inside documents so that search can look at contents, not
 * only names. Plain text and code are read directly; PDFs through pdftotext;
 * Office and OpenDocument files by unpacking their XML; scanned PDFs and images
 * through tesseract OCR when the administrator enables it.
 */
final class Indexer
{
    private const TEXT_KINDS = ['text', 'code', 'markdown'];
    private const OOXML = ['docx' => ['word/document.xml', 'word/footnotes.xml', 'word/endnotes.xml'], 'xlsx' => ['xl/sharedStrings.xml'], 'pptx' => null, 'odt' => ['content.xml'], 'ods' => ['content.xml'], 'odp' => ['content.xml']];

    public static function canIndex(string $name, int $size): bool
    {
        if (!Settings::bool('text_index_enabled')) return false;
        if ($size <= 0 || $size > (int) Settings::get('index_max_size')) return false;
        $kind = Util::kind($name);
        $ext = Util::ext($name);
        if (in_array($kind, self::TEXT_KINDS, true)) return true;
        if ($ext === 'pdf') return Util::bin('pdftotext') !== null || Settings::bool('ocr_enabled');
        if (isset(self::OOXML[$ext]) || $ext === 'pptx') return class_exists('ZipArchive');
        if ($ext === 'rtf' || $ext === 'eml' || $ext === 'vcf' || $ext === 'ics') return true;
        if (in_array($kind, ['image'], true)) return Settings::bool('ocr_enabled') && Util::bin('tesseract') !== null && in_array($ext, ['jpg', 'jpeg', 'png', 'tif', 'tiff', 'bmp', 'webp'], true);
        return false;
    }

    /** Index now when cheap, otherwise queue a job for cron. */
    public static function schedule(int $ownerId, string $rel, string $abs): void
    {
        if (!is_file($abs)) return;
        $size = (int) filesize($abs);
        $name = Util::baseName($rel);
        if (!self::canIndex($name, $size)) {
            DB::delete('file_text', 'owner_id = ? AND path_hash = ?', [$ownerId, sha1($rel)]);
            return;
        }
        $kind = Util::kind($name);
        if (in_array($kind, self::TEXT_KINDS, true) && $size < 512 * 1024) {
            self::index($ownerId, $rel, $abs);
            return;
        }
        Jobs::push('index', ['owner_id' => $ownerId, 'path' => $rel]);
    }

    public static function scheduleTree(int $ownerId, string $rel, string $abs): void
    {
        Jobs::push('index_tree', ['owner_id' => $ownerId, 'path' => $rel]);
    }

    public static function index(int $ownerId, string $rel, string $abs): bool
    {
        if (!is_file($abs)) return false;
        $name = Util::baseName($rel);
        $mtime = (int) filemtime($abs);
        $existing = DB::one('SELECT mtime FROM {{file_text}} WHERE owner_id = ? AND path_hash = ?', [$ownerId, sha1($rel)]);
        if ($existing && (int) $existing['mtime'] === $mtime) return true;
        [$text, $source] = self::extract($abs, $name);
        $text = self::clean($text);
        if ($text === '') {
            DB::delete('file_text', 'owner_id = ? AND path_hash = ?', [$ownerId, sha1($rel)]);
            return false;
        }
        DB::upsert('file_text', ['owner_id' => $ownerId, 'path_hash' => sha1($rel), 'path' => $rel, 'content' => mb_substr($text, 0, 2_000_000), 'source' => $source, 'indexed_at' => fr_now(), 'mtime' => $mtime], ['path', 'content', 'source', 'indexed_at', 'mtime']);
        return true;
    }

    /** @return array{0:string,1:string} [text, source] */
    public static function extract(string $abs, string $name): array
    {
        $ext = Util::ext($name);
        $kind = Util::kind($name);
        if (in_array($kind, self::TEXT_KINDS, true) || in_array($ext, ['rtf', 'eml', 'vcf', 'ics'], true)) {
            $data = (string) file_get_contents($abs, false, null, 0, 4 * 1024 * 1024);
            if ($ext === 'rtf') $data = preg_replace('/\\\\[a-z]+-?\d* ?|[{}]/', ' ', $data) ?? $data;
            if (in_array($ext, ['html', 'htm', 'xhtml'], true)) $data = strip_tags(preg_replace('/<(script|style)[^>]*>.*?<\/\1>/is', ' ', $data) ?? $data);
            return [$data, 'text'];
        }
        if ($ext === 'pdf') {
            $text = '';
            if ($bin = Util::bin('pdftotext')) {
                [$code, $out] = Util::run([$bin, '-layout', '-l', '200', '-enc', 'UTF-8', $abs, '-'], 60);
                if ($code === 0) $text = $out;
            }
            if (trim($text) === '' && Settings::bool('ocr_enabled')) {
                return [self::ocrPdf($abs), 'ocr'];
            }
            return [$text, 'pdf'];
        }
        if (isset(self::OOXML[$ext]) || $ext === 'pptx') {
            return [self::fromZipXml($abs, $ext), 'office'];
        }
        if ($kind === 'image' && Settings::bool('ocr_enabled')) {
            return [self::ocrImage($abs), 'ocr'];
        }
        return ['', 'none'];
    }

    private static function fromZipXml(string $abs, string $ext): string
    {
        if (!class_exists('ZipArchive')) return '';
        $zip = new ZipArchive();
        if ($zip->open($abs) !== true) return '';
        $parts = [];
        if ($ext === 'pptx') {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $n = $zip->getNameIndex($i);
                if ($n !== false && preg_match('#^ppt/(slides|notesSlides)/[^/]+\.xml$#', $n)) $parts[] = $n;
            }
            sort($parts, SORT_NATURAL);
        } else {
            $parts = self::OOXML[$ext] ?? [];
        }
        $text = '';
        foreach ($parts as $p) {
            $xml = $zip->getFromName($p);
            if ($xml === false) continue;
            // Paragraph / cell boundaries become line breaks so words do not glue together.
            $xml = preg_replace('#</(w:p|a:p|text:p|text:h|table:table-cell|si|t)>#', "$0\n", $xml) ?? $xml;
            $xml = preg_replace('#<w:tab/>|<text:tab/>#', "\t", $xml) ?? $xml;
            $text .= html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8') . "\n";
            if (strlen($text) > 3_000_000) break;
        }
        $zip->close();
        return $text;
    }

    private static function ocrImage(string $abs): string
    {
        $bin = Util::bin('tesseract');
        if (!$bin) return '';
        [$code, $out] = Util::run([$bin, $abs, 'stdout', '-l', (string) (Settings::get('ocr_languages') ?: 'eng'), '--psm', '3'], 120);
        return $code === 0 ? $out : '';
    }

    private static function ocrPdf(string $abs): string
    {
        $pdftoppm = Util::bin('pdftoppm');
        $tess = Util::bin('tesseract');
        if (!$pdftoppm || !$tess) return '';
        $tmp = fr_data_dir('temp/ocr_' . Util::hex(6));
        @mkdir($tmp, 0750, true);
        [$code] = Util::run([$pdftoppm, '-r', '200', '-gray', '-f', '1', '-l', '5', '-png', $abs, $tmp . '/p'], 120);
        $text = '';
        if ($code === 0) {
            foreach (glob($tmp . '/p*.png') ?: [] as $png) {
                [$c, $out] = Util::run([$tess, $png, 'stdout', '-l', (string) (Settings::get('ocr_languages') ?: 'eng')], 120);
                if ($c === 0) $text .= $out . "\n";
            }
        }
        Util::rmTree($tmp);
        return $text;
    }

    private static function clean(string $s): string
    {
        if ($s === '') return '';
        if (!mb_check_encoding($s, 'UTF-8')) {
            $conv = @mb_convert_encoding($s, 'UTF-8', 'Windows-1252, ISO-8859-1, UTF-8');
            $s = is_string($conv) ? $conv : '';
        }
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', ' ', $s) ?? $s;
        $s = preg_replace('/[ \t]+/u', ' ', $s) ?? $s;
        $s = preg_replace('/\n{3,}/u', "\n\n", $s) ?? $s;
        return trim($s);
    }

    /** Walk a folder and (re)index everything inside it; used by jobs and the admin "rebuild" button. */
    public static function indexTree(int $ownerId, string $rel, int $maxSeconds = 50): int
    {
        $owner = Users::find($ownerId);
        if (!$owner) return 0;
        $home = Users::homeDir($owner);
        $root = $rel === '' ? $home : $home . '/' . $rel;
        if (!is_dir($root)) return 0;
        $n = 0; $start = time();
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS));
        foreach ($it as $f) {
            /** @var SplFileInfo $f */
            if (!$f->isFile()) continue;
            $r = substr($f->getPathname(), strlen($home) + 1);
            Index::upsert($ownerId, $r, false, (int) $f->getSize(), (int) $f->getMTime());
            if (self::canIndex($f->getFilename(), (int) $f->getSize())) {
                self::index($ownerId, $r, $f->getPathname());
                $n++;
            }
            if (time() - $start > $maxSeconds) {
                Jobs::push('index_tree', ['owner_id' => $ownerId, 'path' => $rel]); // continue later
                break;
            }
        }
        return $n;
    }
}
