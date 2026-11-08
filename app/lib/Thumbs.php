<?php
declare(strict_types=1);

/**
 * Thumbnails and preview renditions. Uses GD for common images and falls
 * back to the command line tools found on the server (GraphicsMagick /
 * ImageMagick "convert", ffmpeg, pdftoppm, ghostscript). Every external
 * program is started directly with an argument array; nothing goes through a shell.
 */
final class Thumbs
{
    public const SIZES = ['small' => 256, 'medium' => 640, 'large' => 1600];

    private const GD_EXT = ['jpg', 'jpeg', 'jpe', 'png', 'gif', 'webp', 'bmp', 'avif'];
    private const CONVERT_EXT = ['tif', 'tiff', 'psd', 'ico', 'heic', 'heif', 'eps', 'ai', 'svg', 'cr2', 'nef', 'arw', 'dng', 'raf', 'orf', 'rw2', 'cr3'];
    private const VIDEO_EXT = ['mp4', 'm4v', 'mov', 'webm', 'mkv', 'avi', 'wmv', 'flv', 'mpg', 'mpeg', '3gp', 'ogv', 'mts'];
    private const AUDIO_EXT = ['mp3', 'flac', 'm4a', 'ogg', 'opus', 'wma', 'aac', 'wav'];
    private const OFFICE_EXT = ['docx', 'xlsx', 'pptx', 'odt', 'ods', 'odp', 'odg'];
    private const FONT_EXT = ['ttf', 'otf'];

    /** Can a thumbnail be produced for this file name on this server? */
    public static function supports(string $name): bool
    {
        if (!Settings::bool('thumbnails_enabled')) return false;
        $ext = Util::ext($name);
        if (in_array($ext, self::GD_EXT, true) || $ext === 'svg') return true;
        if (in_array($ext, self::CONVERT_EXT, true)) return Util::bin('gm') !== null || Util::bin('convert') !== null;
        if (in_array($ext, self::VIDEO_EXT, true)) return Settings::bool('video_thumbnails') && Util::bin('ffmpeg') !== null;
        if (in_array($ext, self::AUDIO_EXT, true)) return Util::bin('ffmpeg') !== null;
        if ($ext === 'pdf') return Settings::bool('pdf_thumbnails') && (Util::bin('pdftoppm') !== null || Util::bin('gs') !== null || Util::bin('convert') !== null);
        if (in_array($ext, self::OFFICE_EXT, true)) return class_exists('ZipArchive');
        if (in_array($ext, self::FONT_EXT, true)) return function_exists('imagettftext');
        return false;
    }

    private static function cacheDir(int $ownerId): string
    {
        $d = fr_data_dir('thumbs/' . $ownerId);
        if (!is_dir($d)) @mkdir($d, 0750, true);
        return $d;
    }

    public static function cachePath(int $ownerId, string $rel, int $mtime, string $size): string
    {
        return self::cacheDir($ownerId) . '/' . sha1($rel) . '_' . $mtime . '_' . $size . '.jpg';
    }

    /** Drop cached renditions of an item (and of everything inside a folder: by rescanning hash prefixes is impossible, so folders rely on mtime-keyed names). */
    public static function forget(int $ownerId, string $rel, bool $isDir): void
    {
        $dir = fr_data_dir('thumbs/' . $ownerId);
        if (!is_dir($dir)) return;
        foreach (glob($dir . '/' . sha1($rel) . '_*') ?: [] as $f) {
            @unlink($f);
        }
    }

    /**
     * Return the absolute path of a JPEG rendition (or the SVG itself), generating it when needed.
     * Returns null when nothing can be produced.
     */
    public static function get(string $abs, int $ownerId, string $rel, string $size = 'small'): ?array
    {
        if (!is_file($abs)) return null;
        $px = self::SIZES[$size] ?? self::SIZES['small'];
        $ext = Util::ext($rel);
        if ($ext === 'svg') {
            return ['path' => $abs, 'mime' => 'image/svg+xml'];
        }
        $maxBytes = (int) Settings::get('preview_max_size');
        if ($maxBytes > 0 && filesize($abs) > $maxBytes && !in_array($ext, self::VIDEO_EXT, true)) return null;
        $mtime = (int) filemtime($abs);
        $cache = self::cachePath($ownerId, $rel, $mtime, $size);
        if (is_file($cache) && filesize($cache) > 0) {
            @touch($cache);
            return ['path' => $cache, 'mime' => 'image/jpeg'];
        }
        $ok = false;
        try {
            if (in_array($ext, self::GD_EXT, true)) {
                $ok = self::fromImage($abs, $cache, $px) || self::viaConvert($abs, $cache, $px);
            } elseif (in_array($ext, self::CONVERT_EXT, true)) {
                $ok = self::viaConvert($abs, $cache, $px);
            } elseif (in_array($ext, self::VIDEO_EXT, true)) {
                $ok = self::fromVideo($abs, $cache, $px);
            } elseif (in_array($ext, self::AUDIO_EXT, true)) {
                $ok = self::fromAudio($abs, $cache, $px);
            } elseif ($ext === 'pdf') {
                $ok = self::fromPdf($abs, $cache, $px);
            } elseif (in_array($ext, self::OFFICE_EXT, true)) {
                $ok = self::fromOffice($abs, $cache, $px);
            } elseif (in_array($ext, self::FONT_EXT, true)) {
                $ok = self::fromFont($abs, $cache, $px);
            }
        } catch (Throwable $e) {
            fr_log('thumbnail failed for ' . $rel . ': ' . $e->getMessage(), 'warn');
        }
        if (!$ok) {
            @unlink($cache);
            // Remember the failure for a while so that we do not retry on every listing.
            @file_put_contents($cache . '.fail', (string) time());
            return null;
        }
        return ['path' => $cache, 'mime' => 'image/jpeg'];
    }

    public static function recentlyFailed(int $ownerId, string $rel, int $mtime, string $size): bool
    {
        $f = self::cachePath($ownerId, $rel, $mtime, $size) . '.fail';
        return is_file($f) && time() - (int) file_get_contents($f) < 3600;
    }

    // ------------------------------------------------------------------
    // Generators
    // ------------------------------------------------------------------

    /** GD: resize with EXIF orientation applied; returns false when GD cannot handle it. */
    private static function fromImage(string $src, string $dst, int $px): bool
    {
        if (!function_exists('imagecreatefromstring')) return false;
        $info = @getimagesize($src);
        if (!$info) return false;
        [$w, $h] = $info;
        if ($w * $h > 40_000_000) return false; // leave huge images to the command line tools
        $data = file_get_contents($src);
        if ($data === false) return false;
        $im = @imagecreatefromstring($data);
        unset($data);
        if (!$im) return false;
        $orientation = 1;
        if (in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_TIFF_II, IMAGETYPE_TIFF_MM], true)) {
            $orientation = (int) (Exif::orientation($src) ?: 1);
        }
        if ($orientation >= 2 && $orientation <= 8) {
            $im = self::orient($im, $orientation);
            $w = imagesx($im); $h = imagesy($im);
        }
        $scale = min(1, $px / max($w, $h));
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));
        $out = imagecreatetruecolor($nw, $nh);
        $white = imagecolorallocate($out, 255, 255, 255);
        imagefill($out, 0, 0, $white);
        imagecopyresampled($out, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($im);
        $ok = imagejpeg($out, $dst, 84);
        imagedestroy($out);
        return $ok;
    }

    private static function orient(GdImage $im, int $o): GdImage
    {
        switch ($o) {
            case 2: imageflip($im, IMG_FLIP_HORIZONTAL); return $im;
            case 3: return imagerotate($im, 180, 0) ?: $im;
            case 4: imageflip($im, IMG_FLIP_VERTICAL); return $im;
            case 5: imageflip($im, IMG_FLIP_VERTICAL); return imagerotate($im, -90, 0) ?: $im;
            case 6: return imagerotate($im, -90, 0) ?: $im;
            case 7: imageflip($im, IMG_FLIP_HORIZONTAL); return imagerotate($im, -90, 0) ?: $im;
            case 8: return imagerotate($im, 90, 0) ?: $im;
        }
        return $im;
    }

    /** GraphicsMagick or ImageMagick. */
    private static function viaConvert(string $src, string $dst, int $px, int $page = 0): bool
    {
        $gm = Util::bin('gm');
        $convert = Util::bin('convert');
        if (!$gm && !$convert) return false;
        $geometry = $px . 'x' . $px . '>';
        $input = $src . '[' . $page . ']';
        if ($gm) {
            $cmd = [$gm, 'convert', '-size', ($px * 2) . 'x' . ($px * 2), $input, '-auto-orient', '-thumbnail', $geometry, '-background', 'white', '-flatten', '-quality', '84', '+profile', '*', 'jpg:' . $dst];
        } else {
            $cmd = [$convert, '-limit', 'memory', '512MiB', $input, '-auto-orient', '-thumbnail', $geometry, '-background', 'white', '-flatten', '-quality', '84', '-strip', 'jpg:' . $dst];
        }
        [$code] = Util::run($cmd, 60);
        if (($code !== 0 || !is_file($dst) || filesize($dst) === 0) && $gm && $convert) {
            [$code] = Util::run([$convert, $input, '-auto-orient', '-thumbnail', $geometry, '-background', 'white', '-flatten', '-quality', '84', 'jpg:' . $dst], 60);
        }
        return $code === 0 && is_file($dst) && filesize($dst) > 0;
    }

    private static function fromVideo(string $src, string $dst, int $px): bool
    {
        $ffmpeg = Util::bin('ffmpeg');
        if (!$ffmpeg) return false;
        foreach (['00:00:03', '00:00:00.5'] as $ss) {
            [$code] = Util::run([$ffmpeg, '-y', '-loglevel', 'error', '-ss', $ss, '-i', $src, '-frames:v', '1', '-vf', "scale='min($px,iw)':-2", '-q:v', '4', $dst], 60);
            if ($code === 0 && is_file($dst) && filesize($dst) > 0) return true;
        }
        return false;
    }

    private static function fromAudio(string $src, string $dst, int $px): bool
    {
        $ffmpeg = Util::bin('ffmpeg');
        if (!$ffmpeg) return false;
        [$code] = Util::run([$ffmpeg, '-y', '-loglevel', 'error', '-i', $src, '-an', '-vcodec', 'mjpeg', '-frames:v', '1', '-vf', "scale='min($px,iw)':-2", $dst], 30);
        return $code === 0 && is_file($dst) && filesize($dst) > 0;
    }

    private static function fromPdf(string $src, string $dst, int $px): bool
    {
        $pdftoppm = Util::bin('pdftoppm');
        if ($pdftoppm) {
            $prefix = $dst . '.tmp';
            [$code] = Util::run([$pdftoppm, '-jpeg', '-f', '1', '-l', '1', '-scale-to', (string) $px, '-singlefile', $src, $prefix], 60);
            if ($code === 0 && is_file($prefix . '.jpg')) {
                rename($prefix . '.jpg', $dst);
                return true;
            }
        }
        $gs = Util::bin('gs');
        if ($gs) {
            [$code] = Util::run([$gs, '-q', '-dNOPAUSE', '-dBATCH', '-dSAFER', '-sDEVICE=jpeg', '-dFirstPage=1', '-dLastPage=1', '-r60', '-dJPEGQ=84', '-sOutputFile=' . $dst, $src], 60);
            if ($code === 0 && is_file($dst) && filesize($dst) > 0) return true;
        }
        return self::viaConvert($src, $dst, $px, 0);
    }

    /** Office files usually embed a thumbnail of the first page. */
    private static function fromOffice(string $src, string $dst, int $px): bool
    {
        if (!class_exists('ZipArchive')) return false;
        $zip = new ZipArchive();
        if ($zip->open($src) !== true) return false;
        $candidates = ['docProps/thumbnail.jpeg', 'docProps/thumbnail.jpg', 'docProps/thumbnail.png', 'Thumbnails/thumbnail.png', 'Thumbnails/thumbnail.jpg'];
        $data = false;
        foreach ($candidates as $c) {
            $data = $zip->getFromName($c);
            if ($data !== false) break;
        }
        $zip->close();
        if ($data === false || !function_exists('imagecreatefromstring')) return false;
        $im = @imagecreatefromstring($data);
        if (!$im) return false;
        $w = imagesx($im); $h = imagesy($im);
        $scale = min(1, $px / max($w, $h));
        $out = imagecreatetruecolor(max(1, (int) ($w * $scale)), max(1, (int) ($h * $scale)));
        imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));
        imagecopyresampled($out, $im, 0, 0, 0, 0, imagesx($out), imagesy($out), $w, $h);
        $ok = imagejpeg($out, $dst, 84);
        imagedestroy($im); imagedestroy($out);
        return $ok;
    }

    /** Render a specimen with the font itself. */
    private static function fromFont(string $src, string $dst, int $px): bool
    {
        if (!function_exists('imagettftext')) return false;
        $w = $px; $h = (int) round($px * 0.66);
        $im = imagecreatetruecolor($w, $h);
        imagefill($im, 0, 0, imagecolorallocate($im, 255, 255, 255));
        $black = imagecolorallocate($im, 30, 30, 30);
        $gray = imagecolorallocate($im, 120, 120, 120);
        $size = $px * 0.28;
        @imagettftext($im, $size, 0, (int) ($px * 0.08), (int) ($h * 0.55), $black, $src, 'Aa');
        @imagettftext($im, $px * 0.07, 0, (int) ($px * 0.08), (int) ($h * 0.85), $gray, $src, 'The quick brown fox');
        $ok = imagejpeg($im, $dst, 84);
        imagedestroy($im);
        return $ok;
    }

    /** Cron: delete renditions that have not been used for a long time. */
    public static function purgeOld(int $days = 60): int
    {
        $root = fr_data_dir('thumbs');
        if (!is_dir($root)) return 0;
        $n = 0;
        $cutoff = time() - $days * 86400;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile() && $f->getMTime() < $cutoff) { @unlink($f->getPathname()); $n++; }
        }
        return $n;
    }

    /**
     * Simple server-side image operations for the image editor: rotate, flip,
     * crop, resize. Writes the result to $dst (same format when possible).
     */
    public static function edit(string $src, string $dst, array $ops): bool
    {
        if (!function_exists('imagecreatefromstring')) throw new RuntimeException('GD is not available');
        $info = @getimagesize($src);
        if (!$info) throw new RuntimeException('Not an editable image');
        $im = @imagecreatefromstring((string) file_get_contents($src));
        if (!$im) throw new RuntimeException('Image could not be decoded');
        imagesavealpha($im, true);
        foreach ($ops as $op) {
            $t = $op['type'] ?? '';
            if ($t === 'rotate') {
                $deg = (float) ($op['degrees'] ?? 90);
                $bg = imagecolorallocatealpha($im, 0, 0, 0, 127);
                $im = imagerotate($im, -$deg, $bg) ?: $im;
                imagesavealpha($im, true);
            } elseif ($t === 'flip') {
                imageflip($im, ($op['direction'] ?? 'h') === 'v' ? IMG_FLIP_VERTICAL : IMG_FLIP_HORIZONTAL);
            } elseif ($t === 'crop') {
                $x = max(0, (int) ($op['x'] ?? 0)); $y = max(0, (int) ($op['y'] ?? 0));
                $w = min(imagesx($im) - $x, max(1, (int) ($op['width'] ?? imagesx($im))));
                $h = min(imagesy($im) - $y, max(1, (int) ($op['height'] ?? imagesy($im))));
                $c = imagecrop($im, ['x' => $x, 'y' => $y, 'width' => $w, 'height' => $h]);
                if ($c) { imagedestroy($im); $im = $c; imagesavealpha($im, true); }
            } elseif ($t === 'resize') {
                $w = max(1, (int) ($op['width'] ?? imagesx($im))); $h = max(1, (int) ($op['height'] ?? imagesy($im)));
                $out = imagecreatetruecolor($w, $h);
                imagealphablending($out, false); imagesavealpha($out, true);
                imagecopyresampled($out, $im, 0, 0, 0, 0, $w, $h, imagesx($im), imagesy($im));
                imagedestroy($im); $im = $out;
            }
        }
        $ok = match ($info[2]) {
            IMAGETYPE_PNG => imagepng($im, $dst, 6),
            IMAGETYPE_GIF => imagegif($im, $dst),
            IMAGETYPE_WEBP => function_exists('imagewebp') ? imagewebp($im, $dst, 88) : imagejpeg($im, $dst, 90),
            default => imagejpeg($im, $dst, 92),
        };
        imagedestroy($im);
        return $ok;
    }
}
