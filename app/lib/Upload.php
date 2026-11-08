<?php
declare(strict_types=1);

/**
 * Resumable uploads. The browser streams the file in raw chunks
 * (application/octet-stream) which are appended to a temporary file, so PHP's
 * upload_max_filesize does not limit the size of a file, only the size of one
 * chunk. Interrupted transfers resume from the last byte received.
 */
final class Upload
{
    public static function tempDir(): string
    {
        $d = fr_data_dir('temp/uploads');
        if (!is_dir($d)) @mkdir($d, 0750, true);
        return $d;
    }

    /** Largest chunk the server accepts (below post_max_size). */
    public static function chunkSize(): int
    {
        $post = Util::parseSize((string) ini_get('post_max_size'));
        $mem = Util::parseSize((string) ini_get('memory_limit'));
        $limit = 8 * 1024 * 1024;
        if ($post > 0) $limit = min($limit, (int) ($post * 0.9));
        if ($mem > 0) $limit = min($limit, (int) ($mem / 4));
        return max(256 * 1024, $limit);
    }

    /** Begin an upload into folder $dir. Returns the session description. */
    public static function start(Ctx $dir, string $name, int $size, ?int $mtime, bool $overwrite, ?array $actor, ?array $link = null): array
    {
        $dir->need('create');
        if (!is_dir($dir->abs)) throw new NotFoundException('Folder not found');
        $name = Util::safeName($name);
        if ($name === '') throw new RuntimeException('Invalid file name');
        Users::assertFileAllowed($dir->owner, $name);
        $max = Users::maxUpload($dir->owner);
        if ($max > 0 && $size > $max) throw new RuntimeException('File is larger than the allowed maximum of ' . Util::humanSize($max));
        $exists = is_file($dir->abs . '/' . $name);
        if ($exists) {
            if (!$overwrite) {
                $name = Util::uniqueName($dir->abs, $name);
                $exists = false;
            } else {
                $dir->childCtx($name)->need('edit');
                Meta::assertUnlocked($dir->ownerId, Util::joinPath($dir->rel, $name), $actor);
            }
        }
        Users::assertQuota($dir->owner, $size - ($exists ? (int) filesize($dir->abs . '/' . $name) : 0));
        $id = bin2hex(random_bytes(16));
        DB::insert('uploads', [
            'id' => $id, 'user_id' => $actor['id'] ?? null, 'link_id' => $link['id'] ?? null, 'owner_id' => $dir->ownerId, 'path' => $dir->vpath,
            'name' => $name, 'size' => $size, 'received' => 0, 'mtime' => $mtime, 'overwrite' => $overwrite ? 1 : 0, 'created_at' => fr_now(), 'updated_at' => fr_now(),
        ]);
        touch(self::tempDir() . '/' . $id . '.part');
        return ['upload_id' => $id, 'name' => $name, 'received' => 0, 'chunk_size' => self::chunkSize()];
    }

    public static function find(string $id, ?array $actor, ?array $link): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $id)) throw new RuntimeException('Invalid upload id');
        $u = DB::one('SELECT * FROM {{uploads}} WHERE id = ?', [$id]);
        if (!$u) throw new NotFoundException('Upload session not found or expired');
        if ($actor && (int) $u['user_id'] !== (int) $actor['id']) throw new PermissionException('Not your upload');
        if (!$actor && (int) ($u['link_id'] ?? 0) !== (int) ($link['id'] ?? -1)) throw new PermissionException('Not your upload');
        return $u;
    }

    /** Append the raw request body at $offset. */
    public static function chunk(string $id, int $offset, ?array $actor, ?array $link): array
    {
        $u = self::find($id, $actor, $link);
        $part = self::tempDir() . '/' . $id . '.part';
        $current = is_file($part) ? (int) filesize($part) : 0;
        if ($offset !== $current) {
            // Client and server disagree: tell the client where to resume from.
            return ['upload_id' => $id, 'received' => $current, 'resume' => true];
        }
        $in = fopen('php://input', 'rb');
        $out = fopen($part, 'ab');
        if (!$in || !$out) throw new RuntimeException('Cannot write upload');
        $written = 0;
        while (!feof($in)) {
            $buf = fread($in, 1024 * 1024);
            if ($buf === false || $buf === '') break;
            $written += fwrite($out, $buf);
            if ($current + $written > (int) $u['size'] + 1024) { fclose($out); fclose($in); throw new RuntimeException('More data than announced'); }
        }
        fclose($out); fclose($in);
        $received = $current + $written;
        DB::update('uploads', ['received' => $received, 'updated_at' => fr_now()], 'id = ?', [$id]);
        return ['upload_id' => $id, 'received' => $received];
    }

    /** Status (for resuming after a page reload / network error). */
    public static function status(string $id, ?array $actor, ?array $link): array
    {
        $u = self::find($id, $actor, $link);
        $part = self::tempDir() . '/' . $id . '.part';
        return ['upload_id' => $id, 'received' => is_file($part) ? (int) filesize($part) : 0, 'size' => (int) $u['size'], 'name' => $u['name']];
    }

    /** All bytes received: move the file into place. */
    public static function finish(string $id, ?array $actor, ?array $link): array
    {
        $u = self::find($id, $actor, $link);
        $part = self::tempDir() . '/' . $id . '.part';
        $received = is_file($part) ? (int) filesize($part) : 0;
        if ($received !== (int) $u['size']) {
            throw new RuntimeException('Upload incomplete: ' . $received . ' of ' . $u['size'] . ' bytes received');
        }
        $dir = Paths::resolve((string) $u['path'], $actor, $link);
        $target = $dir->childCtx((string) $u['name']);
        try {
            $item = Storage::putFromPath($target, $part, ['actor' => $actor, 'source' => 'upload', 'mtime' => $u['mtime'] ? (int) $u['mtime'] : null, 'overwrite' => (bool) $u['overwrite'] || !is_file($target->abs)]);
        } finally {
            @unlink($part);
            DB::delete('uploads', 'id = ?', [$id]);
        }
        return $item;
    }

    public static function cancel(string $id, ?array $actor, ?array $link): void
    {
        try { self::find($id, $actor, $link); } catch (NotFoundException) { return; }
        @unlink(self::tempDir() . '/' . $id . '.part');
        DB::delete('uploads', 'id = ?', [$id]);
    }

    /** Classic multipart upload (small files, API clients, file requests without JS). */
    public static function multipart(Ctx $dir, array $file, bool $overwrite, ?array $actor, ?string $relPath = null): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $msgs = [UPLOAD_ERR_INI_SIZE => 'File exceeds the server upload limit (' . Util::humanSize(Util::phpUploadLimit()) . ')', UPLOAD_ERR_FORM_SIZE => 'File too large', UPLOAD_ERR_PARTIAL => 'Upload interrupted', UPLOAD_ERR_NO_FILE => 'No file received', UPLOAD_ERR_NO_TMP_DIR => 'Server temp folder missing', UPLOAD_ERR_CANT_WRITE => 'Server cannot write the file', UPLOAD_ERR_EXTENSION => 'Upload blocked by a server extension'];
            throw new RuntimeException($msgs[$file['error']] ?? 'Upload failed');
        }
        if ($relPath) {
            $sub = Util::dirName(Util::normPath($relPath));
            if ($sub !== '') $dir = Storage::mkdirs($dir, $sub, $actor);
        }
        $name = Util::safeName((string) ($file['name'] ?? 'file'));
        if ($name === '') throw new RuntimeException('Invalid file name');
        if (is_file($dir->abs . '/' . $name) && !$overwrite) $name = Util::uniqueName($dir->abs, $name);
        $target = $dir->childCtx($name);
        $tmp = (string) $file['tmp_name'];
        // Move out of PHP's temp dir first so that rename() stays on the same filesystem as the data dir.
        $staging = self::tempDir() . '/mp_' . Util::hex(8);
        if (!@move_uploaded_file($tmp, $staging) && !@rename($tmp, $staging)) throw new RuntimeException('Cannot store the uploaded file');
        return Storage::putFromPath($target, $staging, ['actor' => $actor, 'source' => 'upload', 'overwrite' => true]);
    }

    /** Cron: forget uploads nobody touched for a day. */
    public static function purgeStale(): int
    {
        $n = 0;
        foreach (DB::all('SELECT id FROM {{uploads}} WHERE updated_at < ?', [gmdate('Y-m-d H:i:s', time() - 86400)]) as $u) {
            @unlink(self::tempDir() . '/' . $u['id'] . '.part');
            DB::delete('uploads', 'id = ?', [$u['id']]);
            $n++;
        }
        foreach (glob(self::tempDir() . '/*') ?: [] as $f) {
            if (is_file($f) && filemtime($f) < time() - 2 * 86400) @unlink($f);
        }
        foreach (glob(fr_data_dir('temp') . '/zip_*.zip') ?: [] as $f) {
            if (filemtime($f) < time() - 3600) @unlink($f);
        }
        return $n;
    }
}
