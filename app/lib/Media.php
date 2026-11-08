<?php
declare(strict_types=1);

/**
 * Media information for audio and video files: artist, album, title, genre,
 * year, track, duration, dimensions. Uses ffprobe when available and falls
 * back to a small built-in ID3v2 / ID3v1 / FLAC tag reader. The result is
 * stored in file_meta.exif next to photo metadata and drives the Media
 * section of the sidebar (Audio by artist, Photos/Videos by year).
 */
final class Media
{
    public const CATEGORIES = [
        'documents' => ['doc', 'sheet', 'slide', 'pdf', 'text', 'markdown', 'ebook', 'code'],
        'photos' => ['image', 'raw'],
        'videos' => ['video'],
        'audio' => ['audio'],
    ];

    public const DOCUMENT_SUBTYPES = [
        'office' => ['label' => 'Office documents', 'kinds' => ['doc']],
        'spreadsheets' => ['label' => 'Spreadsheets', 'kinds' => ['sheet']],
        'presentations' => ['label' => 'Presentations', 'kinds' => ['slide']],
        'pdf' => ['label' => 'PDF', 'kinds' => ['pdf']],
        'text' => ['label' => 'Text & notes', 'kinds' => ['text', 'markdown']],
        'code' => ['label' => 'Code', 'kinds' => ['code']],
        'ebooks' => ['label' => 'E-books', 'kinds' => ['ebook']],
    ];

    /** Read tags/duration from an audio or video file. Returns [] when nothing is known. */
    public static function probe(string $abs): array
    {
        if (!is_file($abs)) return [];
        $out = [];
        $ffprobe = Util::bin('ffprobe');
        if ($ffprobe) {
            [$code, $json] = Util::run([$ffprobe, '-v', 'quiet', '-print_format', 'json', '-show_format', '-show_streams', $abs], 30);
            if ($code === 0) {
                $d = json_decode($json, true);
                if (is_array($d)) $out = self::fromFfprobe($d);
            }
        }
        if (!$out) {
            $ext = Util::ext($abs);
            if ($ext === 'mp3') $out = self::id3($abs);
            elseif ($ext === 'flac') $out = self::flac($abs);
        }
        return array_filter($out, static fn($v) => $v !== null && $v !== '');
    }

    private static function fromFfprobe(array $d): array
    {
        $out = [];
        $fmt = $d['format'] ?? [];
        $tags = [];
        foreach (($fmt['tags'] ?? []) as $k => $v) $tags[strtolower((string) $k)] = $v;
        foreach (($d['streams'] ?? []) as $s) {
            foreach (($s['tags'] ?? []) as $k => $v) { $lk = strtolower((string) $k); if (!isset($tags[$lk])) $tags[$lk] = $v; }
        }
        $pick = static fn(array $keys) => (static function () use ($keys, $tags) { foreach ($keys as $k) if (isset($tags[$k]) && trim((string) $tags[$k]) !== '') return trim((string) $tags[$k]); return null; })();
        $out['Title'] = $pick(['title']);
        $out['Artist'] = $pick(['artist', 'album_artist', 'albumartist', 'performer', 'composer']);
        $out['Album'] = $pick(['album']);
        $out['Genre'] = $pick(['genre']);
        $year = $pick(['date', 'year', 'creation_time']);
        if ($year && preg_match('/(\d{4})/', $year, $m)) $out['Year'] = $m[1];
        $track = $pick(['track']);
        if ($track) $out['Track'] = explode('/', $track)[0];
        if (isset($fmt['duration']) && is_numeric($fmt['duration'])) {
            $sec = (float) $fmt['duration'];
            $out['DurationSeconds'] = round($sec, 1);
            $out['Duration'] = self::fmtDuration($sec);
        }
        if (isset($fmt['bit_rate']) && is_numeric($fmt['bit_rate'])) $out['Bitrate'] = (int) round(((int) $fmt['bit_rate']) / 1000) . ' kbps';
        foreach (($d['streams'] ?? []) as $s) {
            if (($s['codec_type'] ?? '') === 'video' && !isset($out['Width']) && !empty($s['width'])) {
                $out['Width'] = (int) $s['width'];
                $out['Height'] = (int) $s['height'];
                $out['Codec'] = (string) ($s['codec_name'] ?? '');
                if (!empty($s['r_frame_rate']) && preg_match('#^(\d+)/(\d+)$#', (string) $s['r_frame_rate'], $m) && (int) $m[2] > 0) {
                    $fps = (int) $m[1] / (int) $m[2];
                    if ($fps > 1 && $fps < 240) $out['FrameRate'] = round($fps, 2) . ' fps';
                }
            }
            if (($s['codec_type'] ?? '') === 'audio' && !isset($out['AudioCodec'])) {
                $out['AudioCodec'] = (string) ($s['codec_name'] ?? '');
                if (!empty($s['sample_rate'])) $out['SampleRate'] = ((int) $s['sample_rate']) . ' Hz';
                if (!empty($s['channels'])) $out['Channels'] = (int) $s['channels'];
            }
        }
        return $out;
    }

    public static function fmtDuration(float $sec): string
    {
        $sec = (int) round($sec);
        $h = intdiv($sec, 3600); $m = intdiv($sec % 3600, 60); $s = $sec % 60;
        return $h > 0 ? sprintf('%d:%02d:%02d', $h, $m, $s) : sprintf('%d:%02d', $m, $s);
    }

    // ------------------------------------------------------------------ ID3

    private static function id3(string $abs): array
    {
        $fh = @fopen($abs, 'rb');
        if (!$fh) return [];
        $out = [];
        try {
            $head = (string) fread($fh, 10);
            if (strlen($head) === 10 && substr($head, 0, 3) === 'ID3') {
                $ver = ord($head[3]);
                $flags = ord($head[5]);
                $size = (ord($head[6]) << 21) | (ord($head[7]) << 14) | (ord($head[8]) << 7) | ord($head[9]);
                $data = (string) fread($fh, min($size, 2 * 1024 * 1024));
                $pos = 0;
                if ($flags & 0x40) { // extended header
                    $ehSize = $ver === 4 ? ((ord($data[0]) << 21) | (ord($data[1]) << 14) | (ord($data[2]) << 7) | ord($data[3])) : unpack('N', $data)[1];
                    $pos += $ehSize + ($ver === 4 ? 0 : 4);
                }
                $map = ['TIT2' => 'Title', 'TPE1' => 'Artist', 'TPE2' => 'AlbumArtist', 'TALB' => 'Album', 'TCON' => 'Genre', 'TYER' => 'Year', 'TDRC' => 'Year', 'TRCK' => 'Track', 'TT2' => 'Title', 'TP1' => 'Artist', 'TAL' => 'Album', 'TCO' => 'Genre', 'TYE' => 'Year', 'TRK' => 'Track'];
                while ($pos + 10 <= strlen($data)) {
                    if ($ver === 2) {
                        $id = substr($data, $pos, 3);
                        $fsize = (ord($data[$pos + 3]) << 16) | (ord($data[$pos + 4]) << 8) | ord($data[$pos + 5]);
                        $hdr = 6;
                    } else {
                        $id = substr($data, $pos, 4);
                        $fsize = $ver === 4 ? ((ord($data[$pos + 4]) << 21) | (ord($data[$pos + 5]) << 14) | (ord($data[$pos + 6]) << 7) | ord($data[$pos + 7])) : unpack('N', substr($data, $pos + 4, 4))[1];
                        $hdr = 10;
                    }
                    if ($id === "\0\0\0\0" || $id === "\0\0\0" || $fsize <= 0 || $pos + $hdr + $fsize > strlen($data)) break;
                    if (isset($map[$id])) {
                        $raw = substr($data, $pos + $hdr, $fsize);
                        $text = self::id3Text($raw);
                        if ($text !== '' && !isset($out[$map[$id]])) $out[$map[$id]] = $text;
                    }
                    $pos += $hdr + $fsize;
                }
            }
            if (!isset($out['Title']) || !isset($out['Artist'])) {
                // ID3v1 at the end of the file
                fseek($fh, -128, SEEK_END);
                $tag = (string) fread($fh, 128);
                if (strlen($tag) === 128 && substr($tag, 0, 3) === 'TAG') {
                    $clean = static fn(string $s) => trim(rtrim($s, "\0"));
                    $out['Title'] = $out['Title'] ?? $clean(substr($tag, 3, 30));
                    $out['Artist'] = $out['Artist'] ?? $clean(substr($tag, 33, 30));
                    $out['Album'] = $out['Album'] ?? $clean(substr($tag, 63, 30));
                    $out['Year'] = $out['Year'] ?? $clean(substr($tag, 93, 4));
                }
            }
        } finally {
            fclose($fh);
        }
        if (isset($out['Year']) && preg_match('/(\d{4})/', (string) $out['Year'], $m)) $out['Year'] = $m[1];
        if (isset($out['Track'])) $out['Track'] = explode('/', (string) $out['Track'])[0];
        if (isset($out['Genre']) && preg_match('/^\((\d+)\)$/', (string) $out['Genre'], $m)) unset($out['Genre']);
        if (!isset($out['Artist']) && isset($out['AlbumArtist'])) $out['Artist'] = $out['AlbumArtist'];
        unset($out['AlbumArtist']);
        return array_map(static fn($v) => is_string($v) ? trim($v) : $v, $out);
    }

    private static function id3Text(string $raw): string
    {
        if ($raw === '') return '';
        $enc = ord($raw[0]);
        $body = substr($raw, 1);
        switch ($enc) {
            case 1: $text = @mb_convert_encoding($body, 'UTF-8', 'UTF-16'); break;
            case 2: $text = @mb_convert_encoding($body, 'UTF-8', 'UTF-16BE'); break;
            case 3: $text = $body; break;
            default: $text = @mb_convert_encoding($body, 'UTF-8', 'ISO-8859-1');
        }
        $text = is_string($text) ? $text : '';
        $text = str_replace("\0", '', $text);
        return trim($text);
    }

    private static function flac(string $abs): array
    {
        $fh = @fopen($abs, 'rb');
        if (!$fh) return [];
        $out = [];
        try {
            if (fread($fh, 4) !== 'fLaC') return [];
            while (!feof($fh)) {
                $hdr = (string) fread($fh, 4);
                if (strlen($hdr) < 4) break;
                $last = (ord($hdr[0]) & 0x80) !== 0;
                $type = ord($hdr[0]) & 0x7f;
                $len = (ord($hdr[1]) << 16) | (ord($hdr[2]) << 8) | ord($hdr[3]);
                if ($type === 4 && $len < 4 * 1024 * 1024) {
                    $block = (string) fread($fh, $len);
                    $p = 0;
                    $vl = unpack('V', substr($block, $p, 4))[1]; $p += 4 + $vl;
                    $n = unpack('V', substr($block, $p, 4))[1]; $p += 4;
                    for ($i = 0; $i < $n && $p + 4 <= strlen($block); $i++) {
                        $cl = unpack('V', substr($block, $p, 4))[1]; $p += 4;
                        $c = substr($block, $p, $cl); $p += $cl;
                        if (!str_contains($c, '=')) continue;
                        [$k, $v] = explode('=', $c, 2);
                        $k = strtoupper($k);
                        $m = ['TITLE' => 'Title', 'ARTIST' => 'Artist', 'ALBUM' => 'Album', 'GENRE' => 'Genre', 'DATE' => 'Year', 'TRACKNUMBER' => 'Track'];
                        if (isset($m[$k]) && !isset($out[$m[$k]])) $out[$m[$k]] = trim($v);
                    }
                } elseif ($type === 0 && $len >= 18) {
                    $si = (string) fread($fh, $len);
                    $rate = (ord($si[10]) << 12) | (ord($si[11]) << 4) | (ord($si[12]) >> 4);
                    $samples = ((ord($si[13]) & 0x0f) << 32) | (ord($si[14]) << 24) | (ord($si[15]) << 16) | (ord($si[16]) << 8) | ord($si[17]);
                    if ($rate > 0 && $samples > 0) { $out['DurationSeconds'] = round($samples / $rate, 1); $out['Duration'] = self::fmtDuration($samples / $rate); }
                } else {
                    fseek($fh, $len, SEEK_CUR);
                }
                if ($last) break;
            }
        } finally {
            fclose($fh);
        }
        if (isset($out['Year']) && preg_match('/(\d{4})/', (string) $out['Year'], $m)) $out['Year'] = $m[1];
        return $out;
    }

    // ------------------------------------------------------------------ Media tree & listings

    /** Counts per category, document subtype, year and artist for one owner. */
    public static function tree(int $ownerId): array
    {
        $byKind = [];
        foreach (DB::all('SELECT kind, COUNT(*) AS n FROM {{file_index}} WHERE owner_id = ? AND is_dir = 0 GROUP BY kind', [$ownerId]) as $r) $byKind[$r['kind']] = (int) $r['n'];
        $count = static function (array $kinds) use ($byKind): int { $n = 0; foreach ($kinds as $k) $n += $byKind[$k] ?? 0; return $n; };
        $tree = [];
        $docChildren = [];
        foreach (self::DOCUMENT_SUBTYPES as $id => $def) {
            $n = $count($def['kinds']);
            if ($n > 0) $docChildren[] = ['id' => $id, 'label' => $def['label'], 'count' => $n];
        }
        $tree['documents'] = ['count' => $count(self::CATEGORIES['documents']), 'children' => $docChildren];
        $tree['photos'] = ['count' => $count(self::CATEGORIES['photos']), 'children' => self::years($ownerId, self::CATEGORIES['photos'], true)];
        $tree['videos'] = ['count' => $count(self::CATEGORIES['videos']), 'children' => self::years($ownerId, self::CATEGORIES['videos'], false)];
        $artists = self::artists($ownerId);
        $tree['audio'] = ['count' => $count(self::CATEGORIES['audio']), 'children' => $artists ?: self::years($ownerId, self::CATEGORIES['audio'], false), 'by' => $artists ? 'artist' : 'year'];
        return $tree;
    }

    private static function yearExpr(bool $photo): string
    {
        return $photo
            ? "COALESCE(NULLIF(LEFT(JSON_UNQUOTE(JSON_EXTRACT(m.exif, '$.DateTimeOriginal')), 4), 'null'), FROM_UNIXTIME(i.mtime, '%Y'))"
            : "FROM_UNIXTIME(i.mtime, '%Y')";
    }

    private static function years(int $ownerId, array $kinds, bool $photo): array
    {
        $in = implode(',', array_fill(0, count($kinds), '?'));
        try {
            $rows = DB::all('SELECT ' . self::yearExpr($photo) . ' AS y, COUNT(*) AS n FROM {{file_index}} i ' . ($photo ? 'LEFT JOIN {{file_meta}} m ON m.owner_id = i.owner_id AND m.path_hash = i.path_hash ' : '') . "WHERE i.owner_id = ? AND i.is_dir = 0 AND i.kind IN ($in) GROUP BY y ORDER BY y DESC", array_merge([$ownerId], $kinds));
        } catch (Throwable $e) {
            $rows = DB::all("SELECT FROM_UNIXTIME(i.mtime, '%Y') AS y, COUNT(*) AS n FROM {{file_index}} i WHERE i.owner_id = ? AND i.is_dir = 0 AND i.kind IN ($in) GROUP BY y ORDER BY y DESC", array_merge([$ownerId], $kinds));
        }
        return array_values(array_filter(array_map(static fn($r) => ['id' => (string) $r['y'], 'label' => (string) $r['y'], 'count' => (int) $r['n']], $rows), static fn($r) => $r['id'] !== ''));
    }

    private static function artists(int $ownerId): array
    {
        try {
            $rows = DB::all("SELECT JSON_UNQUOTE(JSON_EXTRACT(m.exif, '$.Artist')) AS a, COUNT(*) AS n FROM {{file_index}} i JOIN {{file_meta}} m ON m.owner_id = i.owner_id AND m.path_hash = i.path_hash WHERE i.owner_id = ? AND i.kind = 'audio' AND JSON_EXTRACT(m.exif, '$.Artist') IS NOT NULL GROUP BY a ORDER BY a LIMIT 300", [$ownerId]);
        } catch (Throwable $e) {
            return [];
        }
        return array_values(array_filter(array_map(static fn($r) => ['id' => 'artist:' . $r['a'], 'label' => (string) $r['a'], 'count' => (int) $r['n']], $rows), static fn($r) => $r['label'] !== '' && $r['label'] !== 'null'));
    }

    /** Index rows of one media category / sub group. */
    public static function listRows(int $ownerId, string $cat, string $sub, int $limit, int $offset, string $within = ''): array
    {
        $kinds = self::CATEGORIES[$cat] ?? null;
        if ($kinds === null) throw new RuntimeException('Unknown media category');
        $where = ['i.owner_id = ?', 'i.is_dir = 0']; $params = [$ownerId];
        $join = '';
        if ($cat === 'documents' && $sub !== '' && isset(self::DOCUMENT_SUBTYPES[$sub])) $kinds = self::DOCUMENT_SUBTYPES[$sub]['kinds'];
        $where[] = 'i.kind IN (' . implode(',', array_fill(0, count($kinds), '?')) . ')';
        foreach ($kinds as $k) $params[] = $k;
        if ($within !== '') { $where[] = 'i.path LIKE ?'; $params[] = DB::escapeLike($within) . '/%'; }
        if ($cat !== 'documents' && $sub !== '') {
            if (str_starts_with($sub, 'artist:')) {
                $join = 'JOIN {{file_meta}} m ON m.owner_id = i.owner_id AND m.path_hash = i.path_hash';
                $where[] = "JSON_UNQUOTE(JSON_EXTRACT(m.exif, '$.Artist')) = ?";
                $params[] = substr($sub, 7);
            } elseif (preg_match('/^\d{4}$/', $sub)) {
                if ($cat === 'photos') {
                    $join = 'LEFT JOIN {{file_meta}} m ON m.owner_id = i.owner_id AND m.path_hash = i.path_hash';
                    $where[] = self::yearExpr(true) . ' = ?';
                } else {
                    $where[] = "FROM_UNIXTIME(i.mtime, '%Y') = ?";
                }
                $params[] = $sub;
            }
        }
        $sql = 'SELECT i.* FROM {{file_index}} i ' . $join . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY i.mtime DESC LIMIT ' . max(1, min(5000, $limit)) . ' OFFSET ' . max(0, $offset);
        try {
            return DB::all($sql, $params);
        } catch (Throwable $e) {
            if ($join !== '') {
                // JSON functions unavailable: fall back to plain year filtering.
                $where = array_filter($where, static fn($w) => !str_contains($w, 'JSON_'));
                return DB::all('SELECT i.* FROM {{file_index}} i WHERE ' . implode(' AND ', $where) . ' ORDER BY i.mtime DESC LIMIT ' . max(1, min(5000, $limit)), array_values(array_filter($params, static fn($p) => !str_starts_with((string) $p, 'artist:'))));
            }
            throw $e;
        }
    }

    public static function categoryLabel(string $cat): string
    {
        return ['documents' => 'Documents', 'photos' => 'Photos', 'videos' => 'Videos', 'audio' => 'Audio'][$cat] ?? $cat;
    }
}
