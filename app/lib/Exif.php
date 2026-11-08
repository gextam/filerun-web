<?php
declare(strict_types=1);

/**
 * A small, dependency-free reader for EXIF (JPEG APP1 and TIFF based files),
 * IPTC (APP13) and the most useful XMP fields. PHP's exif extension is not
 * required. Returns a flat, human-friendly array.
 */
final class Exif
{
    private const TAGS = [
        0x010F => 'Make', 0x0110 => 'Model', 0x0112 => 'Orientation', 0x011A => 'XResolution', 0x011B => 'YResolution', 0x0131 => 'Software', 0x0132 => 'DateTime', 0x013B => 'Artist', 0x8298 => 'Copyright',
        0x829A => 'ExposureTime', 0x829D => 'FNumber', 0x8822 => 'ExposureProgram', 0x8827 => 'ISO', 0x9003 => 'DateTimeOriginal', 0x9004 => 'DateTimeDigitized', 0x9204 => 'ExposureBias', 0x9207 => 'MeteringMode',
        0x9209 => 'Flash', 0x920A => 'FocalLength', 0xA002 => 'Width', 0xA003 => 'Height', 0xA403 => 'WhiteBalance', 0xA405 => 'FocalLength35mm', 0xA432 => 'LensInfo', 0xA433 => 'LensMake', 0xA434 => 'LensModel', 0x9286 => 'UserComment', 0xA420 => 'ImageUniqueID',
        0x8769 => '_ExifIFD', 0x8825 => '_GPSIFD',
    ];
    private const GPS_TAGS = [1 => 'GPSLatitudeRef', 2 => 'GPSLatitude', 3 => 'GPSLongitudeRef', 4 => 'GPSLongitude', 5 => 'GPSAltitudeRef', 6 => 'GPSAltitude', 7 => 'GPSTimeStamp', 29 => 'GPSDateStamp'];
    private const TYPE_SIZES = [1 => 1, 2 => 1, 3 => 2, 4 => 4, 5 => 8, 6 => 1, 7 => 1, 8 => 2, 9 => 4, 10 => 8, 11 => 4, 12 => 8];

    /** Orientation only (fast path for thumbnails). */
    public static function orientation(string $file): ?int
    {
        $data = self::read($file, true);
        return isset($data['Orientation']) ? (int) $data['Orientation'] : null;
    }

    /** Read metadata from a file. Returns [] when there is none. */
    public static function read(string $file, bool $quick = false): array
    {
        $fh = @fopen($file, 'rb');
        if (!$fh) return [];
        $head = fread($fh, 4) ?: '';
        $out = [];
        try {
            if (str_starts_with($head, "\xFF\xD8")) {
                $out = self::readJpeg($fh, $quick);
            } elseif ($head === "II*\0" || $head === "MM\0*" || str_starts_with($head, "II\x2A\x00") || str_starts_with($head, "IIRO") || str_starts_with($head, "IIRS")) {
                fseek($fh, 0);
                $tiff = fread($fh, 4 * 1024 * 1024) ?: '';
                $out = self::parseTiff($tiff);
            } elseif ($head === "\x89PNG") {
                $out = $quick ? [] : self::readPng($fh);
            }
        } catch (Throwable $e) {
            fr_log('exif parse error: ' . $e->getMessage(), 'warn');
        } finally {
            fclose($fh);
        }
        return self::prettify($out);
    }

    private static function readJpeg($fh, bool $quick): array
    {
        fseek($fh, 2);
        $out = [];
        while (!feof($fh)) {
            $marker = fread($fh, 2);
            if ($marker === false || strlen($marker) < 2 || $marker[0] !== "\xFF") break;
            $type = ord($marker[1]);
            if ($type === 0xD8 || ($type >= 0xD0 && $type <= 0xD7)) continue;
            if ($type === 0xDA || $type === 0xD9) break; // start of scan / end
            $lenRaw = fread($fh, 2);
            if ($lenRaw === false || strlen($lenRaw) < 2) break;
            $len = unpack('n', $lenRaw)[1] - 2;
            if ($len < 0) break;
            if ($type === 0xE1 || (!$quick && $type === 0xED)) {
                $seg = $len > 0 ? (string) fread($fh, $len) : '';
                if ($type === 0xE1 && str_starts_with($seg, "Exif\0\0")) {
                    $out = array_merge($out, self::parseTiff(substr($seg, 6)));
                    if ($quick) break;
                } elseif ($type === 0xE1 && str_starts_with($seg, 'http://ns.adobe.com/xap/1.0/')) {
                    $out = array_merge($out, self::parseXmp(substr($seg, 29)));
                } elseif ($type === 0xED && str_starts_with($seg, "Photoshop 3.0\0")) {
                    $out = array_merge($out, self::parseIptc(substr($seg, 14)));
                }
            } else {
                fseek($fh, $len, SEEK_CUR);
            }
        }
        return $out;
    }

    private static function readPng($fh): array
    {
        fseek($fh, 8);
        $out = [];
        while (!feof($fh)) {
            $hdr = fread($fh, 8);
            if ($hdr === false || strlen($hdr) < 8) break;
            $len = unpack('N', substr($hdr, 0, 4))[1];
            $type = substr($hdr, 4, 4);
            if ($type === 'eXIf') {
                $out = array_merge($out, self::parseTiff((string) fread($fh, $len)));
            } elseif ($type === 'iTXt' && $len < 2_000_000) {
                $data = (string) fread($fh, $len);
                if (str_starts_with($data, 'XML:com.adobe.xmp')) $out = array_merge($out, self::parseXmp($data));
            } elseif ($type === 'IDAT' || $type === 'IEND') {
                break;
            } else {
                fseek($fh, $len, SEEK_CUR);
            }
            fseek($fh, 4, SEEK_CUR); // CRC
        }
        return $out;
    }

    /** Parse a TIFF structure (as embedded in JPEG APP1 or a raw TIFF/CR2/NEF/DNG file). */
    private static function parseTiff(string $t): array
    {
        if (strlen($t) < 8) return [];
        $le = substr($t, 0, 2) === 'II';
        if (!$le && substr($t, 0, 2) !== 'MM') return [];
        $u16 = static fn(int $o) => strlen($t) >= $o + 2 ? unpack($le ? 'v' : 'n', $t, $o)[1] : 0;
        $u32 = static fn(int $o) => strlen($t) >= $o + 4 ? unpack($le ? 'V' : 'N', $t, $o)[1] : 0;
        $ifd0 = $u32(4);
        $out = [];
        $readIfd = function (int $offset, array $tagNames, int $depth) use (&$readIfd, &$out, $t, $le, $u16, $u32): void {
            if ($depth > 3 || $offset <= 0 || $offset + 2 > strlen($t)) return;
            $n = $u16($offset);
            if ($n > 500) return;
            for ($i = 0; $i < $n; $i++) {
                $e = $offset + 2 + $i * 12;
                if ($e + 12 > strlen($t)) break;
                $tag = $u16($e); $type = $u16($e + 2); $count = $u32($e + 4);
                $size = (self::TYPE_SIZES[$type] ?? 1) * $count;
                $valOff = $size > 4 ? $u32($e + 8) : $e + 8;
                if ($valOff + $size > strlen($t) || $size > 65536) continue;
                $name = $tagNames[$tag] ?? null;
                if ($name === null) continue;
                if ($name === '_ExifIFD') { $readIfd($u32($e + 8), self::TAGS, $depth + 1); continue; }
                if ($name === '_GPSIFD') { $readIfd($u32($e + 8), self::GPS_TAGS, $depth + 1); continue; }
                $raw = substr($t, $valOff, $size);
                $out[$name] = self::decodeValue($type, $count, $raw, $le);
            }
        };
        $readIfd($ifd0, self::TAGS, 0);
        return $out;
    }

    private static function decodeValue(int $type, int $count, string $raw, bool $le): mixed
    {
        switch ($type) {
            case 2: // ASCII
                return rtrim($raw, "\0 ");
            case 1: case 7: // BYTE / UNDEFINED
                if ($count === 1) return ord($raw[0] ?? "\0");
                $s = rtrim($raw, "\0");
                if (str_starts_with($s, 'ASCII')) return trim(substr($s, 8));
                return preg_match('/^[\x20-\x7e]+$/', $s) ? $s : bin2hex(substr($raw, 0, 16));
            case 3: // SHORT
                $vals = array_values(unpack(($le ? 'v' : 'n') . $count, $raw));
                return $count === 1 ? $vals[0] : $vals;
            case 4: case 9: // LONG / SLONG
                $vals = array_values(unpack(($le ? ($type === 9 ? 'l' : 'V') : ($type === 9 ? 'l' : 'N')) . $count, $raw));
                return $count === 1 ? $vals[0] : $vals;
            case 5: case 10: // RATIONAL / SRATIONAL
                $vals = [];
                for ($i = 0; $i < $count; $i++) {
                    $num = unpack($le ? 'V' : 'N', $raw, $i * 8)[1];
                    $den = unpack($le ? 'V' : 'N', $raw, $i * 8 + 4)[1];
                    if ($type === 10) { if ($num > 0x7fffffff) $num -= 0x100000000; if ($den > 0x7fffffff) $den -= 0x100000000; }
                    $vals[] = ['n' => $num, 'd' => $den];
                }
                return $count === 1 ? $vals[0] : $vals;
            default:
                return bin2hex(substr($raw, 0, 8));
        }
    }

    private static function parseXmp(string $xml): array
    {
        $out = [];
        $grab = static function (string $re) use ($xml): ?string {
            return preg_match($re, $xml, $m) ? html_entity_decode(trim($m[1])) : null;
        };
        if ($v = $grab('#<dc:title>.*?<rdf:li[^>]*>(.*?)</rdf:li>#s')) $out['Title'] = $v;
        if ($v = $grab('#<dc:description>.*?<rdf:li[^>]*>(.*?)</rdf:li>#s')) $out['Description'] = $v;
        if ($v = $grab('#<dc:creator>.*?<rdf:li[^>]*>(.*?)</rdf:li>#s')) $out['Creator'] = $v;
        if ($v = $grab('#xmp:Rating="(\d)"#') ?? $grab('#<xmp:Rating>(\d)</xmp:Rating>#')) $out['Rating'] = (int) $v;
        if (preg_match('#<dc:subject>(.*?)</dc:subject>#s', $xml, $m) && preg_match_all('#<rdf:li[^>]*>(.*?)</rdf:li>#s', $m[1], $mm)) {
            $out['Keywords'] = array_map(static fn($k) => html_entity_decode(trim($k)), $mm[1]);
        }
        if ($v = $grab('#photoshop:City="([^"]*)"#')) $out['City'] = $v;
        if ($v = $grab('#photoshop:Country="([^"]*)"#')) $out['Country'] = $v;
        return $out;
    }

    private static function parseIptc(string $d): array
    {
        $out = [];
        $pos = 0;
        $len = strlen($d);
        while ($pos + 12 <= $len) {
            if (substr($d, $pos, 4) !== '8BIM') { $pos++; continue; }
            $id = unpack('n', $d, $pos + 4)[1];
            $nameLen = ord($d[$pos + 6]);
            $p = $pos + 7 + $nameLen;
            if (($nameLen + 1) % 2) $p++;
            $size = unpack('N', $d, $p)[1];
            $p += 4;
            if ($id === 0x0404) {
                $iptc = substr($d, $p, $size);
                $i = 0;
                $map = [5 => 'Title', 25 => 'Keywords', 80 => 'Creator', 90 => 'City', 101 => 'Country', 105 => 'Headline', 116 => 'Copyright', 120 => 'Description', 55 => 'DateCreated'];
                while ($i + 5 <= strlen($iptc)) {
                    if ($iptc[$i] !== "\x1C") { $i++; continue; }
                    $rec = ord($iptc[$i + 1]); $ds = ord($iptc[$i + 2]);
                    $l = unpack('n', $iptc, $i + 3)[1];
                    $val = substr($iptc, $i + 5, $l);
                    $i += 5 + $l;
                    if ($rec !== 2 || !isset($map[$ds])) continue;
                    $name = $map[$ds];
                    if (!mb_check_encoding($val, 'UTF-8')) $val = mb_convert_encoding($val, 'UTF-8', 'ISO-8859-1');
                    if ($ds === 25) { $out['Keywords'][] = $val; } else { $out[$name] = $out[$name] ?? $val; }
                }
            }
            $pos = $p + $size + ($size % 2);
        }
        return $out;
    }

    /** Turn raw values into readable ones and derive a few composite fields. */
    private static function prettify(array $e): array
    {
        if (!$e) return [];
        $rat = static fn($v) => is_array($v) && isset($v['n'], $v['d']) ? ($v['d'] == 0 ? 0.0 : $v['n'] / $v['d']) : (is_numeric($v) ? (float) $v : null);
        $out = [];
        foreach (['Make', 'Model', 'LensModel', 'LensMake', 'Software', 'Artist', 'Copyright', 'Title', 'Description', 'Creator', 'Headline', 'City', 'Country', 'UserComment', 'ImageUniqueID'] as $k) {
            if (isset($e[$k]) && is_string($e[$k]) && trim($e[$k]) !== '') $out[$k] = trim($e[$k]);
        }
        foreach (['DateTimeOriginal', 'DateTimeDigitized', 'DateTime'] as $k) {
            if (!empty($e[$k]) && is_string($e[$k]) && preg_match('/^(\d{4}):(\d{2}):(\d{2}) (\d{2}:\d{2}:\d{2})/', $e[$k], $m)) {
                $out[$k] = "$m[1]-$m[2]-$m[3] $m[4]";
            }
        }
        if (isset($e['Orientation']) && is_numeric($e['Orientation'])) $out['Orientation'] = (int) $e['Orientation'];
        if (isset($e['Width'], $e['Height']) && is_numeric($e['Width'])) { $out['Width'] = (int) $e['Width']; $out['Height'] = (int) $e['Height']; }
        if (isset($e['ExposureTime']) && ($v = $rat($e['ExposureTime'])) !== null && $v > 0) {
            $out['ExposureTime'] = $v >= 1 ? rtrim(rtrim(number_format($v, 1), '0'), '.') . ' s' : '1/' . (int) round(1 / $v) . ' s';
        }
        if (isset($e['FNumber']) && ($v = $rat($e['FNumber'])) !== null && $v > 0) $out['FNumber'] = 'f/' . rtrim(rtrim(number_format($v, 1), '0'), '.');
        if (isset($e['ISO'])) $out['ISO'] = is_array($e['ISO']) ? (int) $e['ISO'][0] : (int) $e['ISO'];
        if (isset($e['FocalLength']) && ($v = $rat($e['FocalLength'])) !== null && $v > 0) $out['FocalLength'] = rtrim(rtrim(number_format($v, 1), '0'), '.') . ' mm';
        if (isset($e['FocalLength35mm']) && is_numeric($e['FocalLength35mm']) && $e['FocalLength35mm'] > 0) $out['FocalLength35mm'] = (int) $e['FocalLength35mm'] . ' mm';
        if (isset($e['ExposureBias']) && ($v = $rat($e['ExposureBias'])) !== null) $out['ExposureBias'] = ($v > 0 ? '+' : '') . rtrim(rtrim(number_format($v, 2), '0'), '.') . ' EV';
        if (isset($e['Flash']) && is_numeric($e['Flash'])) $out['Flash'] = ((int) $e['Flash'] & 1) ? 'Fired' : 'Did not fire';
        if (isset($e['ExposureProgram']) && is_numeric($e['ExposureProgram'])) {
            $out['ExposureProgram'] = [1 => 'Manual', 2 => 'Program', 3 => 'Aperture priority', 4 => 'Shutter priority', 5 => 'Creative', 6 => 'Action', 7 => 'Portrait', 8 => 'Landscape'][(int) $e['ExposureProgram']] ?? null;
            if ($out['ExposureProgram'] === null) unset($out['ExposureProgram']);
        }
        if (isset($e['WhiteBalance']) && is_numeric($e['WhiteBalance'])) $out['WhiteBalance'] = (int) $e['WhiteBalance'] === 0 ? 'Auto' : 'Manual';
        if (isset($e['MeteringMode']) && is_numeric($e['MeteringMode'])) $out['MeteringMode'] = [1 => 'Average', 2 => 'Center weighted', 3 => 'Spot', 4 => 'Multi-spot', 5 => 'Pattern', 6 => 'Partial'][(int) $e['MeteringMode']] ?? 'Other';
        if (!empty($e['Keywords']) && is_array($e['Keywords'])) $out['Keywords'] = array_values(array_unique(array_filter(array_map('strval', $e['Keywords']))));
        if (isset($e['Rating'])) $out['Rating'] = (int) $e['Rating'];
        // GPS
        if (isset($e['GPSLatitude'], $e['GPSLongitude']) && is_array($e['GPSLatitude']) && is_array($e['GPSLongitude'])) {
            $dms = static function (array $v) use ($rat): ?float {
                if (isset($v['n'])) return $rat($v);
                if (count($v) < 2) return null;
                $d = $rat($v[0]) ?? 0; $m = $rat($v[1]) ?? 0; $s = isset($v[2]) ? ($rat($v[2]) ?? 0) : 0;
                return $d + $m / 60 + $s / 3600;
            };
            $lat = $dms($e['GPSLatitude']); $lon = $dms($e['GPSLongitude']);
            if ($lat !== null && $lon !== null) {
                if (strtoupper((string) ($e['GPSLatitudeRef'] ?? 'N')) === 'S') $lat = -$lat;
                if (strtoupper((string) ($e['GPSLongitudeRef'] ?? 'E')) === 'W') $lon = -$lon;
                $out['GPSLatitude'] = round($lat, 6);
                $out['GPSLongitude'] = round($lon, 6);
                if (isset($e['GPSAltitude'])) {
                    $alt = $rat($e['GPSAltitude']);
                    if ($alt !== null) $out['GPSAltitude'] = round($alt, 1) . ' m';
                }
            }
        }
        if (isset($out['Make'], $out['Model']) && str_starts_with(strtolower($out['Model']), strtolower($out['Make']))) {
            $out['Camera'] = $out['Model'];
        } elseif (isset($out['Make']) || isset($out['Model'])) {
            $out['Camera'] = trim(($out['Make'] ?? '') . ' ' . ($out['Model'] ?? ''));
        }
        return $out;
    }
}
