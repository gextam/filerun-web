<?php
declare(strict_types=1);

/** /api/upload/* — resumable chunked uploads and classic multipart uploads. */
final class UploadApi
{
    public static function start(): void
    {
        $dir = Api::ctx(Http::str('p', '/'));
        $rel = Util::normPath(Http::str('relpath', ''));
        if ($rel !== '' && Util::dirName($rel) !== '') {
            $dir = Storage::mkdirs($dir, Util::dirName($rel), Api::actor());
        }
        $name = $rel !== '' ? Util::baseName($rel) : Http::str('name');
        Http::ok(Upload::start($dir, $name, max(0, Http::int('size', 0)), Http::int('mtime', 0) ?: null, Http::bool('overwrite', false), Api::actor(), Api::$link));
    }

    public static function chunk(): void
    {
        Api::requireLinkUnlocked();
        Http::ok(Upload::chunk(Http::str('id'), Http::int('offset', 0), Api::actor(), Api::$link));
    }

    public static function status(): void
    {
        Api::requireLinkUnlocked();
        Http::ok(Upload::status(Http::str('id'), Api::actor(), Api::$link));
    }

    public static function finish(): void
    {
        Api::requireLinkUnlocked();
        Http::ok(Upload::finish(Http::str('id'), Api::actor(), Api::$link));
    }

    public static function cancel(): void
    {
        Upload::cancel(Http::str('id'), Api::actor(), Api::$link);
        Http::ok(null);
    }

    /** multipart/form-data: fields p, overwrite, relpath; file field "file" (or several "files[]"). */
    public static function multipart(): void
    {
        $dir = Api::ctx(Http::str('p', '/'));
        $overwrite = Http::bool('overwrite', false);
        $results = []; $errors = [];
        $files = [];
        if (!empty($_FILES['file'])) $files[] = $_FILES['file'];
        if (!empty($_FILES['files']) && is_array($_FILES['files']['name'])) {
            foreach ($_FILES['files']['name'] as $i => $n) {
                $files[] = ['name' => $n, 'tmp_name' => $_FILES['files']['tmp_name'][$i], 'error' => $_FILES['files']['error'][$i], 'size' => $_FILES['files']['size'][$i]];
            }
        }
        if (!$files) throw new RuntimeException('No file received (the request may exceed the server limit of ' . Util::humanSize(Util::phpUploadLimit()) . ')');
        foreach ($files as $f) {
            try { $results[] = Upload::multipart($dir, $f, $overwrite, Api::actor(), Http::str('relpath', '') ?: null); } catch (Throwable $e) { $errors[] = ['name' => $f['name'] ?? '', 'error' => $e->getMessage()]; }
        }
        Http::ok(['done' => $results, 'errors' => $errors]);
    }

    /** Paste a screenshot: body is a base64 data URL or raw image; returns the item and a link when possible. */
    public static function paste(): void
    {
        $dir = Api::ctx(Http::str('p', '/'));
        $data = (string) Http::input('data', '');
        $name = Util::safeName(Http::str('name', '')) ?: ('Screenshot ' . date('Y-m-d H.i.s') . '.png');
        if (preg_match('#^data:([^;]+);base64,(.+)$#s', $data, $m)) {
            $bin = base64_decode($m[2], true);
            if ($bin === false) throw new RuntimeException('Invalid image data');
        } else {
            throw new RuntimeException('Expected a data URL');
        }
        if (strlen($bin) > 50 * 1024 * 1024) throw new RuntimeException('Image too large');
        $tmp = Upload::tempDir() . '/paste_' . Util::hex(6);
        file_put_contents($tmp, $bin);
        $name = Util::uniqueName($dir->abs, $name);
        $item = Storage::putFromPath($dir->childCtx($name), $tmp, ['actor' => Api::actor(), 'source' => 'upload', 'overwrite' => true]);
        Http::ok($item);
    }
}
