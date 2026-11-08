<?php
declare(strict_types=1);

/**
 * Zip downloads of several items, looking inside archives without unpacking
 * them, and extracting archives into a folder.
 */
final class Archive
{
    /** Build a zip of the given contexts into a temporary file. Returns its path. */
    public static function zip(array $ctxs, ?array $actor): string
    {
        if (!class_exists('ZipArchive')) throw new RuntimeException('Zip support is not available on this server');
        $tmpDir = fr_data_dir('temp');
        if (!is_dir($tmpDir)) @mkdir($tmpDir, 0750, true);
        $tmp = $tmpDir . '/zip_' . Util::hex(8) . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Cannot create archive');
        $total = 0; $count = 0;
        $limit = 8 * 1024 * 1024 * 1024;
        foreach ($ctxs as $ctx) {
            /** @var Ctx $ctx */
            $ctx->need('download');
            if (!file_exists($ctx->abs)) continue;
            $base = $ctx->rel === '' || ($ctx->root !== 'home' && $ctx->shareRel() === '') ? Util::safeName($ctx->name()) : Util::baseName($ctx->rel);
            if (is_file($ctx->abs)) {
                $zip->addFile($ctx->abs, $base);
                $total += (int) filesize($ctx->abs);
                $count++;
            } else {
                $zip->addEmptyDir($base);
                $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($ctx->abs, FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS), RecursiveIteratorIterator::SELF_FIRST);
                foreach ($it as $f) {
                    /** @var SplFileInfo $f */
                    $rel = $base . '/' . substr($f->getPathname(), strlen($ctx->abs) + 1);
                    if (Storage::isHidden($f->getFilename(), false) && $f->getFilename()[0] === '.') continue;
                    if ($f->isDir()) {
                        $zip->addEmptyDir($rel);
                    } else {
                        $zip->addFile($f->getPathname(), $rel);
                        $total += (int) $f->getSize();
                        $count++;
                    }
                    if ($total > $limit) { $zip->close(); @unlink($tmp); throw new RuntimeException('Selection is too large to zip (8 GB limit)'); }
                }
            }
            Users::assertTraffic($ctx->owner, $total);
        }
        if ($count === 0 && !$zip->numFiles) {
            $zip->addFromString('empty.txt', 'The selected folders were empty.');
        }
        $zip->close();
        foreach ($ctxs as $ctx) {
            Activity::log($ctx->root === 'link' ? 'link_download' : 'zip', ['owner_id' => $ctx->ownerId, 'path' => $ctx->rel, 'is_dir' => is_dir($ctx->abs) ? 1 : 0, 'client' => $ctx->root === 'link' ? 'link' : Activity::$client, 'details' => ['zip' => true, 'items' => count($ctxs)]]);
        }
        return $tmp;
    }

    /** List the entries of a zip / tar / tgz without extracting. */
    public static function listEntries(string $abs, string $name, int $max = 5000): array
    {
        $ext = Util::ext($name);
        $entries = [];
        if ($ext === 'zip' && class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            if ($zip->open($abs) !== true) throw new RuntimeException('Archive cannot be opened');
            for ($i = 0; $i < min($zip->numFiles, $max); $i++) {
                $st = $zip->statIndex($i);
                if (!$st) continue;
                $entries[] = ['name' => $st['name'], 'size' => (int) $st['size'], 'compressed' => (int) $st['comp_size'], 'mtime' => (int) $st['mtime'], 'is_dir' => str_ends_with($st['name'], '/')];
            }
            $zip->close();
            return $entries;
        }
        if (in_array($ext, ['tar', 'gz', 'tgz', 'bz2'], true)) {
            try {
                $p = new PharData($abs);
                $it = new RecursiveIteratorIterator($p, RecursiveIteratorIterator::SELF_FIRST);
                $prefix = 'phar://' . $abs . '/';
                foreach ($it as $f) {
                    /** @var PharFileInfo $f */
                    $entries[] = ['name' => substr($f->getPathname(), strlen($prefix)), 'size' => $f->isDir() ? 0 : (int) $f->getSize(), 'compressed' => $f->isDir() ? 0 : (int) $f->getCompressedSize(), 'mtime' => (int) $f->getMTime(), 'is_dir' => $f->isDir()];
                    if (count($entries) >= $max) break;
                }
                return $entries;
            } catch (Throwable $e) {
                throw new RuntimeException('Archive cannot be read: ' . $e->getMessage());
            }
        }
        if (in_array($ext, ['7z', 'rar'], true)) {
            $bin = Util::bin($ext === '7z' ? '7z' : 'unrar');
            if ($bin) {
                [$code, $out] = Util::run($ext === '7z' ? [$bin, 'l', '-slt', $abs] : [$bin, 'lt', $abs], 30);
                if ($code === 0) {
                    foreach (preg_split('/\r?\n\r?\n/', $out) ?: [] as $block) {
                        if (preg_match('/^\s*(?:Path|Name)\s*[:=]\s*(.+)$/m', $block, $m)) {
                            $size = preg_match('/^\s*Size\s*[:=]\s*(\d+)/m', $block, $s) ? (int) $s[1] : 0;
                            $entries[] = ['name' => trim($m[1]), 'size' => $size, 'compressed' => 0, 'mtime' => 0, 'is_dir' => (bool) preg_match('/Folder\s*[:=]\s*\+|Type\s*[:=]\s*Directory/m', $block)];
                        }
                    }
                    return array_slice($entries, 1, $max);
                }
            }
        }
        throw new RuntimeException('This archive format cannot be listed on this server');
    }

    /** Extract an archive into the destination folder (into a sub folder named after the archive). */
    public static function extract(Ctx $archive, Ctx $destDir, ?array $actor): array
    {
        $archive->need('read');
        $destDir->need('create');
        $name = Util::baseName($archive->rel);
        $ext = Util::ext($name);
        [$base] = Util::splitExt($name);
        if ($ext === 'gz' && str_ends_with(strtolower($base), '.tar')) $base = substr($base, 0, -4);
        $folderName = Util::uniqueName($destDir->abs, Util::safeName($base) ?: 'extracted');
        $target = $destDir->abs . '/' . $folderName;
        if (!@mkdir($target, 0750)) throw new RuntimeException('Cannot create destination folder');
        $total = 0;
        $quota = (int) $destDir->owner['quota_bytes'];
        $free = $quota > 0 ? $quota - (int) $destDir->owner['used_bytes'] : PHP_INT_MAX;
        try {
            if ($ext === 'zip') {
                $zip = new ZipArchive();
                if ($zip->open($archive->abs) !== true) throw new RuntimeException('Archive cannot be opened');
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $st = $zip->statIndex($i);
                    if (!$st) continue;
                    $entry = str_replace('\\', '/', $st['name']);
                    $safe = Util::normPath($entry);
                    if ($safe === '' || str_contains($entry, "\0")) continue;
                    $dest = $target . '/' . $safe;
                    if (str_ends_with($entry, '/')) { @mkdir($dest, 0750, true); continue; }
                    @mkdir(dirname($dest), 0750, true);
                    $total += (int) $st['size'];
                    if ($total > $free) throw new RuntimeException('Not enough storage quota to extract this archive');
                    $in = $zip->getStream($st['name']);
                    if (!$in) continue;
                    $out = fopen($dest, 'wb');
                    if ($out) { stream_copy_to_stream($in, $out); fclose($out); }
                    fclose($in);
                    @touch($dest, (int) $st['mtime']);
                }
                $zip->close();
            } elseif (in_array($ext, ['tar', 'gz', 'tgz', 'bz2'], true)) {
                $p = new PharData($archive->abs);
                foreach (new RecursiveIteratorIterator($p) as $f) {
                    $total += (int) $f->getSize();
                    if ($total > $free) throw new RuntimeException('Not enough storage quota to extract this archive');
                }
                $p->extractTo($target, null, true);
            } else {
                throw new RuntimeException('This archive format cannot be extracted on this server');
            }
        } catch (Throwable $e) {
            Util::rmTree($target);
            throw $e;
        }
        $info = Util::treeSize($target);
        Users::addUsage($destDir->ownerId, $info['size']);
        $newRel = Util::joinPath($destDir->rel, $folderName);
        Index::upsert($destDir->ownerId, $newRel, true, 0, time());
        Indexer::scheduleTree($destDir->ownerId, $newRel, $target);
        Activity::log('upload', ['owner_id' => $destDir->ownerId, 'path' => $newRel, 'is_dir' => 1, 'details' => ['extracted_from' => $archive->rel, 'files' => $info['files'], 'size' => $info['size']]]);
        return Storage::item($destDir->childCtx($folderName));
    }
}
