<?php
declare(strict_types=1);

/**
 * A lightweight index of file names kept in the database so that search,
 * "recent files" and the photo gallery do not need to walk the disk. The
 * filesystem remains the source of truth: listings refresh their folder,
 * every operation updates it, and the cron job rescans periodically.
 */
final class Index
{
    public static function entry(int $ownerId, string $rel, bool $isDir, int $size, int $mtime): array
    {
        $name = Util::baseName($rel);
        return [
            'owner_id' => $ownerId, 'path' => $rel, 'path_hash' => sha1($rel), 'parent_hash' => sha1(Util::dirName($rel)), 'name' => $name,
            'is_dir' => $isDir ? 1 : 0, 'size' => $isDir ? 0 : $size, 'mtime' => $mtime, 'mime' => $isDir ? 'inode/directory' : Util::mime($name),
            'kind' => Util::kind($name, $isDir), 'indexed_at' => fr_now(),
        ];
    }

    /** Replace the index of one folder with what was just listed. */
    public static function syncDir(int $ownerId, string $relDir, array $entries): void
    {
        try {
            $parentHash = sha1($relDir);
            $keep = [];
            foreach (array_chunk($entries, 200) as $chunk) {
                $sql = 'INSERT INTO {{file_index}} (owner_id, path, path_hash, parent_hash, name, is_dir, size, mtime, mime, kind, indexed_at) VALUES ';
                $vals = []; $params = [];
                foreach ($chunk as $e) {
                    $row = self::entry($ownerId, $e['rel'], $e['is_dir'], $e['size'], $e['mtime']);
                    $keep[] = $row['path_hash'];
                    $vals[] = '(?,?,?,?,?,?,?,?,?,?,?)';
                    array_push($params, $row['owner_id'], $row['path'], $row['path_hash'], $row['parent_hash'], $row['name'], $row['is_dir'], $row['size'], $row['mtime'], $row['mime'], $row['kind'], $row['indexed_at']);
                }
                DB::q($sql . implode(',', $vals) . ' ON DUPLICATE KEY UPDATE name = VALUES(name), is_dir = VALUES(is_dir), size = VALUES(size), mtime = VALUES(mtime), mime = VALUES(mime), kind = VALUES(kind), indexed_at = VALUES(indexed_at)', $params);
            }
            // Remove entries that disappeared from this folder (and their subtrees).
            $gone = DB::all('SELECT path, path_hash, is_dir FROM {{file_index}} WHERE owner_id = ? AND parent_hash = ?' . ($keep ? ' AND path_hash NOT IN (' . implode(',', array_fill(0, count($keep), '?')) . ')' : ''), array_merge([$ownerId, $parentHash], $keep));
            foreach ($gone as $g) {
                self::remove($ownerId, (string) $g['path'], (bool) $g['is_dir']);
            }
        } catch (Throwable $e) {
            fr_log('index syncDir failed: ' . $e->getMessage());
        }
    }

    public static function upsert(int $ownerId, string $rel, bool $isDir, int $size, int $mtime): void
    {
        if ($rel === '') return;
        DB::upsert('file_index', self::entry($ownerId, $rel, $isDir, $size, $mtime), ['name', 'is_dir', 'size', 'mtime', 'mime', 'kind', 'indexed_at']);
    }

    public static function remove(int $ownerId, string $rel, bool $isDir): void
    {
        if ($isDir) {
            DB::q('DELETE FROM {{file_index}} WHERE owner_id = ? AND (path_hash = ? OR path LIKE ?)', [$ownerId, sha1($rel), DB::escapeLike($rel) . '/%']);
            DB::q('DELETE FROM {{file_text}} WHERE owner_id = ? AND (path_hash = ? OR path LIKE ?)', [$ownerId, sha1($rel), DB::escapeLike($rel) . '/%']);
        } else {
            DB::delete('file_index', 'owner_id = ? AND path_hash = ?', [$ownerId, sha1($rel)]);
            DB::delete('file_text', 'owner_id = ? AND path_hash = ?', [$ownerId, sha1($rel)]);
        }
    }

    /** Walk the whole home folder of an account and rebuild its index. */
    public static function rescan(array $owner, int $maxEntries = 500000): array
    {
        $home = Users::homeDir($owner);
        $ownerId = (int) $owner['id'];
        if (!is_dir($home)) return ['files' => 0, 'dirs' => 0];
        $files = 0; $dirs = 0; $batch = [];
        $flush = static function () use (&$batch, $ownerId): void {
            if (!$batch) return;
            $sql = 'INSERT INTO {{file_index}} (owner_id, path, path_hash, parent_hash, name, is_dir, size, mtime, mime, kind, indexed_at) VALUES ';
            $vals = []; $params = [];
            foreach ($batch as $row) {
                $vals[] = '(?,?,?,?,?,?,?,?,?,?,?)';
                array_push($params, $row['owner_id'], $row['path'], $row['path_hash'], $row['parent_hash'], $row['name'], $row['is_dir'], $row['size'], $row['mtime'], $row['mime'], $row['kind'], $row['indexed_at']);
            }
            DB::q($sql . implode(',', $vals) . ' ON DUPLICATE KEY UPDATE name = VALUES(name), is_dir = VALUES(is_dir), size = VALUES(size), mtime = VALUES(mtime), mime = VALUES(mime), kind = VALUES(kind), indexed_at = VALUES(indexed_at)', $params);
            $batch = [];
        };
        $started = fr_now();
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($home, FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($it as $f) {
            /** @var SplFileInfo $f */
            $rel = substr(str_replace('\\', '/', $f->getPathname()), strlen($home) + 1);
            if ($rel === false || $rel === '') continue;
            $isDir = $f->isDir();
            $batch[] = self::entry($ownerId, $rel, $isDir, $isDir ? 0 : (int) $f->getSize(), (int) $f->getMTime());
            $isDir ? $dirs++ : $files++;
            if (count($batch) >= 200) $flush();
            if ($files + $dirs >= $maxEntries) break;
        }
        $flush();
        if ($files + $dirs < $maxEntries) {
            DB::delete('file_index', 'owner_id = ? AND indexed_at < ?', [$ownerId, $started]);
        }
        Users::recalcUsage($owner);
        return ['files' => $files, 'dirs' => $dirs];
    }

    /** Name search inside a subtree. */
    public static function searchNames(int $ownerId, string $q, string $within = '', array $o = []): array
    {
        $limit = max(1, min(2000, (int) ($o['limit'] ?? Settings::int('search_max_results'))));
        $where = ['owner_id = ?']; $params = [$ownerId];
        if ($within !== '') { $where[] = 'path LIKE ?'; $params[] = DB::escapeLike($within) . '/%'; }
        $terms = preg_split('/\s+/', trim($q)) ?: [];
        foreach ($terms as $t) {
            if ($t === '') continue;
            $where[] = 'name LIKE ?';
            $params[] = '%' . DB::escapeLike($t) . '%';
        }
        if (!empty($o['kind'])) { $where[] = 'kind = ?'; $params[] = (string) $o['kind']; }
        if (!empty($o['ext'])) { $where[] = 'name LIKE ?'; $params[] = '%.' . DB::escapeLike((string) $o['ext']); }
        if (isset($o['is_dir']) && $o['is_dir'] !== '') { $where[] = 'is_dir = ?'; $params[] = Util::bool($o['is_dir']) ? 1 : 0; }
        if (!empty($o['min_size'])) { $where[] = 'size >= ?'; $params[] = (int) $o['min_size']; }
        if (!empty($o['max_size'])) { $where[] = 'size <= ?'; $params[] = (int) $o['max_size']; }
        if (!empty($o['from'])) { $where[] = 'mtime >= ?'; $params[] = (int) strtotime((string) $o['from']); }
        if (!empty($o['to'])) { $where[] = 'mtime <= ?'; $params[] = (int) strtotime((string) $o['to'] . ' 23:59:59'); }
        return DB::all('SELECT * FROM {{file_index}} WHERE ' . implode(' AND ', $where) . ' ORDER BY is_dir DESC, name LIMIT ' . $limit, $params);
    }

    /** Full-text search inside indexed document contents. */
    public static function searchContent(int $ownerId, string $q, string $within = '', int $limit = 200): array
    {
        $q = trim($q);
        if ($q === '') return [];
        $where = ['t.owner_id = ?']; $params = [$ownerId];
        if ($within !== '') { $where[] = 't.path LIKE ?'; $params[] = DB::escapeLike($within) . '/%'; }
        $bool = implode(' ', array_map(static fn($w) => '+' . preg_replace('/[+\-><()~*"@]+/', '', $w) . '*', array_filter(preg_split('/\s+/', $q) ?: [], static fn($w) => mb_strlen($w) >= 2)));
        if ($bool === '') return [];
        $where[] = 'MATCH(t.content) AGAINST (? IN BOOLEAN MODE)';
        $params[] = $bool;
        try {
            return DB::all('SELECT t.path, t.path_hash, MATCH(t.content) AGAINST (?) AS score, SUBSTRING(t.content, GREATEST(1, LOCATE(?, t.content) - 60), 180) AS snippet, i.size, i.mtime, i.name, i.is_dir, i.kind, i.mime FROM {{file_text}} t LEFT JOIN {{file_index}} i ON i.owner_id = t.owner_id AND i.path_hash = t.path_hash WHERE ' . implode(' AND ', $where) . ' ORDER BY score DESC LIMIT ' . max(1, min(1000, $limit)), array_merge([$q, explode(' ', $q)[0]], $params));
        } catch (Throwable $e) {
            fr_log('fulltext search failed: ' . $e->getMessage());
            return [];
        }
    }

    public static function recent(int $ownerId, int $limit = 50, string $within = ''): array
    {
        $params = [$ownerId];
        $w = 'owner_id = ? AND is_dir = 0';
        if ($within !== '') { $w .= ' AND path LIKE ?'; $params[] = DB::escapeLike($within) . '/%'; }
        return DB::all("SELECT * FROM {{file_index}} WHERE $w ORDER BY mtime DESC LIMIT " . max(1, min(500, $limit)), $params);
    }

    public static function byKind(int $ownerId, array $kinds, int $limit = 2000, int $offset = 0, string $within = ''): array
    {
        $in = implode(',', array_fill(0, count($kinds), '?'));
        $params = array_merge([$ownerId], $kinds);
        $w = "owner_id = ? AND kind IN ($in)";
        if ($within !== '') { $w .= ' AND path LIKE ?'; $params[] = DB::escapeLike($within) . '/%'; }
        return DB::all("SELECT * FROM {{file_index}} WHERE $w ORDER BY mtime DESC LIMIT " . max(1, min(5000, $limit)) . ' OFFSET ' . max(0, $offset), $params);
    }

    public static function stats(int $ownerId): array
    {
        $row = DB::one('SELECT COUNT(*) AS n, SUM(is_dir) AS dirs, SUM(size) AS size FROM {{file_index}} WHERE owner_id = ?', [$ownerId]);
        $kinds = DB::all('SELECT kind, COUNT(*) AS n, SUM(size) AS size FROM {{file_index}} WHERE owner_id = ? AND is_dir = 0 GROUP BY kind ORDER BY size DESC', [$ownerId]);
        return ['entries' => (int) ($row['n'] ?? 0), 'dirs' => (int) ($row['dirs'] ?? 0), 'files' => (int) ($row['n'] ?? 0) - (int) ($row['dirs'] ?? 0), 'size' => (int) ($row['size'] ?? 0), 'kinds' => $kinds];
    }
}
