<?php
declare(strict_types=1);

/**
 * WebDAV access to the user's home folder (RFC 4918, compliance classes 1 and 2).
 *
 * Routed from index.php for /dav and /dav/<path> as WebDav::handle($subPath),
 * where $subPath is the request path with FR_BASE_PATH and "/dav" already
 * removed and still percent-encoded; "/" is the root of the signed-in user's home.
 *
 * - Authentication is HTTP Basic only (account password or an app password with
 *   the "webdav" scope) through Auth::authenticateBasic(). OPTIONS is answered
 *   without credentials because several clients probe before sending any.
 * - Every file operation goes through Storage, so permissions, quotas, versions,
 *   trash, the search index, notifications and the activity log behave exactly
 *   as they do for the web interface.
 * - Locks are exclusive write locks kept in {{dav_locks}} (token, depth, owner,
 *   expiry). Locks taken in the web interface (Meta::assertUnlocked) are
 *   honoured as well; both answer 423 Locked.
 * - Client quirks that are deliberately supported: Depth: infinity is served as
 *   depth 1, LOCK on a missing file creates it (Windows, macOS), PROPPATCH is
 *   acknowledged but not stored, chunked PUT bodies (macOS Finder) are spooled
 *   to learn their size, "._*" and ".DS_Store" files are stored like any other.
 */

/** An HTTP error to be sent as-is: status, plain text message, extra headers. */
final class WebDavError extends RuntimeException
{
    /** @param array<string,string> $headers */
    public function __construct(public readonly int $status, string $message = '', public readonly array $headers = [])
    {
        parent::__construct($message, $status);
    }
}

final class WebDav
{
    private const ALLOW = 'OPTIONS, GET, HEAD, PUT, DELETE, PROPFIND, PROPPATCH, MKCOL, COPY, MOVE, LOCK, UNLOCK';
    private const LOCK_TIMEOUT_DEFAULT = 3600;
    private const LOCK_TIMEOUT_MAX = 86400;
    private const MAX_XML_BODY = 1048576;

    private const REASONS = [
        200 => 'OK', 201 => 'Created', 204 => 'No Content', 207 => 'Multi-Status',
        400 => 'Bad Request', 401 => 'Unauthorized', 403 => 'Forbidden', 404 => 'Not Found', 405 => 'Method Not Allowed',
        409 => 'Conflict', 412 => 'Precondition Failed', 413 => 'Payload Too Large', 415 => 'Unsupported Media Type', 423 => 'Locked',
        500 => 'Internal Server Error', 502 => 'Bad Gateway', 507 => 'Insufficient Storage',
    ];

    /** The authenticated user row (also the owner of everything served here). */
    private static array $user = [];
    private static string $home = '';
    private static string $method = 'GET';
    /** @var array<int,array> active lock rows per owner, loaded once per request */
    private static array $lockCache = [];
    private static ?array $ifTokens = null;

    // ------------------------------------------------------------------
    // Entry point
    // ------------------------------------------------------------------

    public static function handle(string $path): never
    {
        @set_time_limit(0);
        self::$method = Http::method();
        try {
            if (!Settings::bool('webdav_enabled')) {
                throw new WebDavError(403, 'WebDAV access is disabled on this server');
            }
            if (self::$method === 'OPTIONS') {
                self::respond(200, '', ['DAV' => '1, 2', 'MS-Author-Via' => 'DAV', 'Allow' => self::ALLOW, 'Accept-Ranges' => 'bytes']);
            }
            self::$user = self::authenticate();
            self::$home = Users::ensureHome(self::$user);
            $ctx = Paths::resolve(self::vpathFrom($path), self::$user);
            switch (self::$method) {
                case 'PROPFIND': self::propfind($ctx);
                case 'PROPPATCH': self::proppatch($ctx);
                case 'GET':
                case 'HEAD': self::get($ctx);
                case 'PUT': self::put($ctx);
                case 'MKCOL': self::mkcol($ctx);
                case 'DELETE': self::delete($ctx);
                case 'COPY': self::copyOrMove($ctx, false);
                case 'MOVE': self::copyOrMove($ctx, true);
                case 'LOCK': self::lock($ctx);
                case 'UNLOCK': self::unlock($ctx);
                default: throw new WebDavError(405, 'Method not allowed', ['Allow' => self::ALLOW]);
            }
        } catch (WebDavError $e) {
            self::respond($e->status, $e->getMessage(), $e->headers);
        } catch (NotFoundException $e) {
            self::respond(404, $e->getMessage());
        } catch (PermissionException $e) {
            self::respond(stripos($e->getMessage(), 'locked') !== false ? 423 : 403, $e->getMessage());
        } catch (PDOException $e) {
            fr_log('webdav ' . self::$method . ' ' . $path . ': database error: ' . $e->getMessage());
            self::respond(500, 'Database error');
        } catch (RuntimeException $e) {
            $status = self::statusForRuntime($e);
            if ($status >= 500) {
                fr_log('webdav ' . self::$method . ' ' . $path . ': ' . $e->getMessage());
            }
            self::respond($status, $e->getMessage());
        } catch (Throwable $e) {
            fr_log('webdav ' . self::$method . ' ' . $path . ': ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            self::respond(500, 'Internal server error');
        }
    }

    // ------------------------------------------------------------------
    // Authentication and paths
    // ------------------------------------------------------------------

    private static function authenticate(): array
    {
        $header = trim((string) (Http::header('Authorization') ?? ''));
        if (preg_match('/^Basic\s+([A-Za-z0-9+\/=]+)$/i', $header, $m)) {
            $decoded = base64_decode($m[1], true);
            if ($decoded !== false && str_contains($decoded, ':')) {
                [$username, $password] = explode(':', $decoded, 2);
                $user = Auth::authenticateBasic($username, $password, 'webdav');
                if ($user !== null) {
                    // Optional hook: lets Activity::log() attribute Storage's entries to this user (see integration notes).
                    if (method_exists(Auth::class, 'adopt')) {
                        Auth::adopt($user, 'basic');
                    }
                    return $user;
                }
            }
        }
        throw new WebDavError(401, 'Authentication required', ['WWW-Authenticate' => 'Basic realm="' . self::realm() . '"']);
    }

    private static function realm(): string
    {
        $name = trim((string) Settings::get('app_name'));
        return str_replace(['"', '\\', "\r", "\n"], '', $name !== '' ? $name : 'WebDAV');
    }

    /** Virtual path ("/Photos/x.jpg") from a still percent-encoded request sub path. */
    private static function vpathFrom(string $raw): string
    {
        return self::vpathFromDecoded(rawurldecode($raw));
    }

    private static function vpathFromDecoded(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        if (str_contains($path, "\0")) {
            throw new WebDavError(400, 'Invalid path');
        }
        foreach (explode('/', $path) as $segment) {
            if (trim($segment) === '..') {
                throw new WebDavError(400, 'Invalid path');
            }
        }
        return '/' . Util::normPath($path);
    }

    private static function parentCtx(Ctx $ctx): Ctx
    {
        if ($ctx->rel === '') {
            throw new WebDavError(403, 'The root collection cannot be changed');
        }
        return Paths::resolve('/' . Util::dirName($ctx->rel), self::$user);
    }

    private static function absOf(string $rel): string
    {
        return $rel === '' ? self::$home : self::$home . '/' . $rel;
    }

    /** Request href of an item: FR_BASE_PATH/dav/<encoded segments>, collections with a trailing slash. */
    private static function hrefFor(string $rel, bool $isDir): string
    {
        $href = FR_BASE_PATH . '/dav/';
        if ($rel !== '') {
            $href .= implode('/', array_map('rawurlencode', explode('/', $rel)));
            if ($isDir) {
                $href .= '/';
            }
        }
        return $href;
    }

    /** Destination header (absolute URL or absolute path) to a virtual path inside the DAV root. */
    private static function parseDestination(?string $header): string
    {
        $dest = trim((string) $header);
        if ($dest === '') {
            throw new WebDavError(400, 'Destination header missing');
        }
        if (preg_match('#^[a-z][a-z0-9+.\-]*://#i', $dest)) {
            $dest = parse_url($dest, PHP_URL_PATH);
            if (!is_string($dest)) {
                throw new WebDavError(400, 'Invalid Destination header');
            }
        } else {
            $dest = explode('?', $dest, 2)[0];
        }
        $dest = rawurldecode($dest);
        if (FR_BASE_PATH !== '' && ($dest === FR_BASE_PATH || str_starts_with($dest, FR_BASE_PATH . '/'))) {
            $dest = substr($dest, strlen(FR_BASE_PATH));
        }
        if (preg_match('#^/index\.php(/.*)?$#', $dest, $m)) {
            $dest = $m[1] ?? '/';
        }
        if ($dest !== '/dav' && !str_starts_with($dest, '/dav/')) {
            throw new WebDavError(502, 'Destination is outside the WebDAV root');
        }
        return self::vpathFromDecoded(substr($dest, 4));
    }

    // ------------------------------------------------------------------
    // Request bodies and headers
    // ------------------------------------------------------------------

    /** Small (XML) request bodies. */
    private static function body(): string
    {
        $in = fopen('php://input', 'rb');
        if ($in === false) {
            return '';
        }
        $data = (string) stream_get_contents($in, self::MAX_XML_BODY + 1);
        fclose($in);
        if (strlen($data) > self::MAX_XML_BODY) {
            throw new WebDavError(413, 'Request body too large');
        }
        return $data;
    }

    private static function hasBody(): bool
    {
        if (stripos((string) (Http::header('Transfer-Encoding') ?? ''), 'chunked') !== false) {
            return true;
        }
        return (int) (Http::header('Content-Length') ?? 0) > 0;
    }

    /** [stream, size] of a PUT body. Bodies without a usable Content-Length are spooled to disk to learn their size. */
    private static function requestBody(): array
    {
        $in = fopen('php://input', 'rb');
        if ($in === false) {
            throw new WebDavError(500, 'The request body cannot be read');
        }
        $length = trim((string) (Http::header('Content-Length') ?? ''));
        $chunked = stripos((string) (Http::header('Transfer-Encoding') ?? ''), 'chunked') !== false;
        if (!$chunked && preg_match('/^\d+$/', $length)) {
            return [$in, (int) $length];
        }
        $tmpPath = Upload::tempDir() . '/dav_' . Util::hex(8) . '.part';
        $tmp = @fopen($tmpPath, 'w+b');
        if ($tmp === false) {
            throw new WebDavError(500, 'The upload cannot be spooled');
        }
        register_shutdown_function(static function () use ($tmpPath): void {
            @unlink($tmpPath);
        });
        stream_copy_to_stream($in, $tmp);
        fclose($in);
        $stat = fstat($tmp);
        rewind($tmp);
        return [$tmp, $stat === false ? 0 : (int) $stat['size']];
    }

    /** PROPFIND depth as [0|1, noroot]; "infinity" is served as 1. */
    private static function depth(): array
    {
        $h = strtolower(trim((string) (Http::header('Depth') ?? 'infinity')));
        return [str_starts_with($h, '0') ? 0 : 1, str_contains($h, 'noroot')];
    }

    /** Lock tokens presented in the If header (resource tags, which are URLs, are skipped). */
    private static function ifTokens(): array
    {
        if (self::$ifTokens === null) {
            $tokens = [];
            $raw = (string) (Http::header('If') ?? '');
            if ($raw !== '' && preg_match_all('/<([^<>]+)>/', $raw, $m)) {
                foreach ($m[1] as $t) {
                    if (!preg_match('#^(https?:)?/#i', $t)) {
                        $tokens[] = $t;
                    }
                }
            }
            self::$ifTokens = $tokens;
        }
        return self::$ifTokens;
    }

    /** Timeout header: "Second-600", "Infinite", or a list of both. Default 1 hour, at most 1 day. */
    private static function parseTimeout(?string $header): int
    {
        foreach (explode(',', (string) $header) as $part) {
            $part = trim($part);
            if (preg_match('/^Second-(\d+)$/i', $part, $m)) {
                return max(1, min(self::LOCK_TIMEOUT_MAX, (int) $m[1]));
            }
            if (strcasecmp($part, 'Infinite') === 0) {
                return self::LOCK_TIMEOUT_MAX;
            }
        }
        return self::LOCK_TIMEOUT_DEFAULT;
    }

    /** Same formula as Http::streamFile() so that PROPFIND, GET and PUT agree. */
    private static function etag(string $abs, int $size, int $mtime): string
    {
        return '"' . md5($abs . $size . $mtime) . '"';
    }

    private static function etagListed(string $etag, string $header): bool
    {
        foreach (explode(',', $header) as $t) {
            $t = trim($t);
            if (str_starts_with($t, 'W/')) {
                $t = substr($t, 2);
            }
            if ($t === $etag) {
                return true;
            }
        }
        return false;
    }

    /** If-Match / If-None-Match on PUT. */
    private static function checkPreconditions(Ctx $ctx, bool $exists): void
    {
        $etag = $exists ? self::etag($ctx->abs, (int) @filesize($ctx->abs), (int) @filemtime($ctx->abs)) : '';
        $ifMatch = Http::header('If-Match');
        if ($ifMatch !== null) {
            if (!$exists) {
                throw new WebDavError(412, 'The resource does not exist');
            }
            if (trim($ifMatch) !== '*' && !self::etagListed($etag, $ifMatch)) {
                throw new WebDavError(412, 'Entity tag mismatch');
            }
        }
        $ifNoneMatch = Http::header('If-None-Match');
        if ($ifNoneMatch !== null && $exists && (trim($ifNoneMatch) === '*' || self::etagListed($etag, $ifNoneMatch))) {
            throw new WebDavError(412, 'The resource exists already');
        }
    }

    // ------------------------------------------------------------------
    // XML helpers
    // ------------------------------------------------------------------

    /** Parse an XML body; null when empty. DTDs are refused (no entity expansion, no XXE). */
    private static function parseXml(string $body): ?DOMDocument
    {
        $body = trim($body);
        if ($body === '') {
            return null;
        }
        if (preg_match('/<!(DOCTYPE|ENTITY)/i', $body)) {
            throw new WebDavError(400, 'XML documents with a DTD are not accepted');
        }
        $previous = libxml_use_internal_errors(true);
        $doc = new DOMDocument('1.0', 'utf-8');
        $ok = $doc->loadXML($body, LIBXML_NONET | LIBXML_NOBLANKS);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$ok || $doc->documentElement === null) {
            throw new WebDavError(400, 'Malformed XML body');
        }
        return $doc;
    }

    /** Child elements of a node (text nodes skipped). @return DOMElement[] */
    private static function elements(?DOMNode $node): array
    {
        $out = [];
        if ($node !== null) {
            foreach ($node->childNodes as $child) {
                if ($child instanceof DOMElement) {
                    $out[] = $child;
                }
            }
        }
        return $out;
    }

    /** [namespace, local name] of a property element; a missing namespace is read as DAV: for sloppy clients. */
    private static function propName(DOMElement $el): array
    {
        $ns = (string) $el->namespaceURI;
        return [$ns === '' ? 'DAV:' : $ns, (string) $el->localName];
    }

    private static function xmlEscape(string $s): string
    {
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s) ?? $s;
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function multistatus(string $inner): string
    {
        return '<?xml version="1.0" encoding="utf-8"?>' . "\n" . '<D:multistatus xmlns:D="DAV:">' . $inner . '</D:multistatus>';
    }

    /** One <D:response> with propstat blocks given as [[status, inner prop XML], ...]. */
    private static function responseXml(string $href, array $blocks): string
    {
        $x = '<D:response><D:href>' . self::xmlEscape($href) . '</D:href>';
        foreach ($blocks as [$status, $inner]) {
            $x .= '<D:propstat><D:prop>' . $inner . '</D:prop><D:status>HTTP/1.1 ' . $status . ' ' . self::REASONS[$status] . '</D:status></D:propstat>';
        }
        return $x . '</D:response>';
    }

    /** An empty property element in its own namespace (404 propstat blocks, PROPPATCH echoes). */
    private static function emptyProp(string $ns, string $name): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_.\-]*$/', $name)) {
            return '';
        }
        if ($ns === 'DAV:') {
            return '<D:' . $name . '/>';
        }
        if ($ns === '') {
            return '<' . $name . ' xmlns=""/>';
        }
        return '<x:' . $name . ' xmlns:x="' . self::xmlEscape($ns) . '"/>';
    }

    // ------------------------------------------------------------------
    // Responses
    // ------------------------------------------------------------------

    private static function startResponse(int $status, array $headers): void
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (headers_sent()) {
            return;
        }
        header('HTTP/1.1 ' . $status . ' ' . (self::REASONS[$status] ?? 'Unknown'), true, $status);
        foreach ($headers as $name => $value) {
            header($name . ': ' . $value);
        }
    }

    /** Send a complete response and stop. */
    private static function respond(int $status, string $body = '', array $headers = []): never
    {
        if ($body !== '' && !isset($headers['Content-Type'])) {
            $headers['Content-Type'] = 'text/plain; charset=utf-8';
        }
        if ($status === 405 && !isset($headers['Allow'])) {
            $headers['Allow'] = self::ALLOW;
        }
        if ($status !== 204) {
            $headers['Content-Length'] = (string) strlen($body);
        }
        self::startResponse($status, $headers);
        if ($body !== '' && self::$method !== 'HEAD') {
            echo $body;
        }
        exit;
    }

    private static function respondXml(int $status, string $xml, array $headers = []): never
    {
        self::respond($status, $xml, $headers + ['Content-Type' => 'application/xml; charset=utf-8']);
    }

    /** HTTP status for the validation errors Storage raises. */
    private static function statusForRuntime(RuntimeException $e): int
    {
        $m = strtolower($e->getMessage());
        return match (true) {
            str_contains($m, 'quota') => 507,
            str_contains($m, 'larger than'), str_contains($m, 'too large') => 413,
            str_contains($m, 'locked') => 423,
            str_contains($m, 'not found') => 404,
            str_contains($m, 'exists'), str_contains($m, 'into itself'), str_contains($m, 'not a folder'), str_contains($m, 'blocks the folder') => 409,
            str_contains($m, 'not allowed'), str_contains($m, 'only these file types'), str_contains($m, 'traffic'), str_contains($m, 'cannot be') => 403,
            str_contains($m, 'failed'), str_contains($m, 'could not'), str_contains($m, 'cannot write'), str_contains($m, 'cannot create') => 500,
            default => 400,
        };
    }

    // ------------------------------------------------------------------
    // PROPFIND / PROPPATCH
    // ------------------------------------------------------------------

    /** Stat-based description of the item behind a context, shaped like a Storage::listDir() entry. */
    private static function describe(Ctx $ctx): ?array
    {
        $stat = @stat($ctx->abs);
        if ($stat === false) {
            return null;
        }
        $isDir = is_dir($ctx->abs);
        $name = $ctx->rel === '' ? $ctx->name() : Util::baseName($ctx->rel);
        return [
            'name' => $name, 'rel' => $ctx->rel, 'abs' => $ctx->abs, 'is_dir' => $isDir,
            'size' => $isDir ? 0 : (int) $stat['size'], 'mtime' => (int) $stat['mtime'], 'ctime' => (int) $stat['ctime'],
            'mime' => $isDir ? 'httpd/unix-directory' : Util::mime($name),
        ];
    }

    /** Requested properties of a PROPFIND body: ['mode' => allprop|propname|prop, 'props' => [[ns, name], ...]]. */
    private static function parsePropfind(string $body): array
    {
        $doc = self::parseXml($body);
        if ($doc === null) {
            return ['mode' => 'allprop', 'props' => []];
        }
        $root = $doc->documentElement;
        if ($root->localName !== 'propfind') {
            throw new WebDavError(400, 'Expected a propfind element');
        }
        $mode = 'allprop';
        $props = [];
        foreach (self::elements($root) as $child) {
            switch ($child->localName) {
                case 'propname':
                    $mode = 'propname';
                    break;
                case 'prop':
                    $mode = 'prop';
                    foreach (self::elements($child) as $p) {
                        $props[] = self::propName($p);
                    }
                    break;
                case 'allprop':
                    $mode = 'allprop';
                    break;
            }
        }
        return ['mode' => $mode, 'props' => $props];
    }

    /** Live properties of an entry (name, rel, abs, is_dir, size, mtime, ctime, mime) as complete elements keyed by name. */
    private static function liveProps(Ctx $ctx, array $e): array
    {
        $isDir = (bool) $e['is_dir'];
        $name = (string) $e['name'];
        $p = [];
        $p['displayname'] = '<D:displayname>' . self::xmlEscape($name) . '</D:displayname>';
        if (!$isDir) {
            $p['getcontentlength'] = '<D:getcontentlength>' . (int) $e['size'] . '</D:getcontentlength>';
        }
        $p['getlastmodified'] = '<D:getlastmodified>' . gmdate('D, d M Y H:i:s', (int) $e['mtime']) . ' GMT</D:getlastmodified>';
        $p['creationdate'] = '<D:creationdate>' . gmdate('Y-m-d\TH:i:s\Z', (int) $e['ctime']) . '</D:creationdate>';
        $p['resourcetype'] = $isDir ? '<D:resourcetype><D:collection/></D:resourcetype>' : '<D:resourcetype/>';
        $p['getcontenttype'] = '<D:getcontenttype>' . self::xmlEscape($isDir ? 'httpd/unix-directory' : (string) $e['mime']) . '</D:getcontenttype>';
        $p['getetag'] = '<D:getetag>' . self::xmlEscape(self::etag((string) $e['abs'], (int) $e['size'], (int) $e['mtime'])) . '</D:getetag>';
        $p['supportedlock'] = '<D:supportedlock><D:lockentry><D:lockscope><D:exclusive/></D:lockscope><D:locktype><D:write/></D:locktype></D:lockentry></D:supportedlock>';
        $p['lockdiscovery'] = '<D:lockdiscovery>' . self::activeLocksXml(self::locksFor($ctx->ownerId, (string) $e['rel'], false)) . '</D:lockdiscovery>';
        if ($isDir) {
            $quota = (int) ($ctx->owner['quota_bytes'] ?? 0);
            $used = max(0, (int) ($ctx->owner['used_bytes'] ?? 0));
            if ($quota > 0) {
                $p['quota-available-bytes'] = '<D:quota-available-bytes>' . max(0, $quota - $used) . '</D:quota-available-bytes>';
            }
            $p['quota-used-bytes'] = '<D:quota-used-bytes>' . $used . '</D:quota-used-bytes>';
        }
        $p['iscollection'] = '<D:iscollection>' . ($isDir ? '1' : '0') . '</D:iscollection>';
        $p['ishidden'] = '<D:ishidden>' . (str_starts_with($name, '.') ? '1' : '0') . '</D:ishidden>';
        return $p;
    }

    /** The <D:response> of one entry for the requested property set. */
    private static function propfindEntry(Ctx $ctx, array $e, array $req): string
    {
        $href = self::hrefFor((string) $e['rel'], (bool) $e['is_dir']);
        $all = self::liveProps($ctx, $e);
        if ($req['mode'] === 'propname') {
            $names = '';
            foreach (array_keys($all) as $name) {
                $names .= '<D:' . $name . '/>';
            }
            return self::responseXml($href, [[200, $names]]);
        }
        if ($req['mode'] === 'allprop') {
            return self::responseXml($href, [[200, implode('', $all)]]);
        }
        $found = '';
        $missing = '';
        foreach ($req['props'] as [$ns, $name]) {
            if ($ns === 'DAV:' && isset($all[$name])) {
                $found .= $all[$name];
            } else {
                $missing .= self::emptyProp($ns, $name);
            }
        }
        $blocks = [];
        if ($found !== '' || $missing === '') {
            $blocks[] = [200, $found];
        }
        if ($missing !== '') {
            $blocks[] = [404, $missing];
        }
        return self::responseXml($href, $blocks);
    }

    private static function propfind(Ctx $ctx): never
    {
        $self = self::describe($ctx);
        if ($self === null) {
            throw new NotFoundException('Not found');
        }
        if (!$ctx->can('read')) {
            throw new PermissionException('You are not allowed to view this folder');
        }
        [$depth, $noRoot] = self::depth();
        $req = self::parsePropfind(self::body());
        $children = [];
        if ($depth > 0 && $self['is_dir']) {
            // Everything is listed, dot files included: a sync client that cannot see a file it uploaded would delete it locally.
            foreach (Storage::listDir($ctx, ['with_meta' => false, 'show_hidden' => true])['items'] as $e) {
                $e['abs'] = $ctx->abs . '/' . $e['name'];
                $children[] = $e;
            }
        }
        self::ownerLocks($ctx->ownerId); // all database work happens before the first byte goes out
        // Streamed: a folder with tens of thousands of entries would otherwise need the whole document in memory.
        self::startResponse(207, ['Content-Type' => 'application/xml; charset=utf-8', 'Cache-Control' => 'private, no-cache']);
        echo '<?xml version="1.0" encoding="utf-8"?>' . "\n" . '<D:multistatus xmlns:D="DAV:">';
        if (!$noRoot) {
            echo self::propfindEntry($ctx, $self, $req);
        }
        $n = 0;
        foreach ($children as $e) {
            echo self::propfindEntry($ctx, $e, $req);
            if (++$n % 200 === 0) {
                flush();
            }
        }
        echo '</D:multistatus>';
        exit;
    }

    private static function proppatch(Ctx $ctx): never
    {
        if (!file_exists($ctx->abs)) {
            throw new NotFoundException('Not found');
        }
        self::assertUnlocked($ctx, false);
        $doc = self::parseXml(self::body());
        $echo = '';
        if ($doc !== null) {
            // <D:propertyupdate><D:set|D:remove><D:prop><ns:name>...</ns:name></D:prop></D:set></D:propertyupdate>
            foreach (self::elements($doc->documentElement) as $operation) {
                foreach (self::elements($operation) as $prop) {
                    foreach (self::elements($prop) as $p) {
                        [$ns, $name] = self::propName($p);
                        $echo .= self::emptyProp($ns, $name);
                    }
                }
            }
        }
        // Acknowledged but not stored: Windows and macOS insist on setting attributes and time stamps.
        $href = self::hrefFor($ctx->rel, is_dir($ctx->abs));
        self::respondXml(207, self::multistatus(self::responseXml($href, [[200, $echo]])));
    }

    // ------------------------------------------------------------------
    // GET / HEAD / PUT / MKCOL / DELETE
    // ------------------------------------------------------------------

    private static function get(Ctx $ctx): never
    {
        if (!file_exists($ctx->abs)) {
            throw new NotFoundException('Not found');
        }
        if (!is_dir($ctx->abs)) {
            Storage::download($ctx, false, self::$user); // Range, ETag and HEAD are handled there; exits
        }
        if (!$ctx->can('read')) {
            throw new PermissionException('You are not allowed to view this folder');
        }
        self::respond(200, self::indexHtml($ctx), [
            'Content-Type' => 'text/html; charset=utf-8',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'",
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-cache',
        ]);
    }

    /** Minimal HTML listing for browsers that open a collection URL. */
    private static function indexHtml(Ctx $ctx): string
    {
        $items = Storage::listDir($ctx, ['with_meta' => false, 'show_hidden' => true])['items'];
        $title = Http::escape($ctx->rel === '' ? $ctx->name() : Util::baseName($ctx->rel));
        $rows = '';
        if ($ctx->rel !== '') {
            $rows .= '<tr><td><a href="' . Http::escape(self::hrefFor(Util::dirName($ctx->rel), true)) . '">../</a></td><td></td><td></td></tr>';
        }
        foreach ($items as $e) {
            $rows .= '<tr><td><a href="' . Http::escape(self::hrefFor((string) $e['rel'], (bool) $e['is_dir'])) . '">' . Http::escape((string) $e['name']) . ($e['is_dir'] ? '/' : '') . '</a></td>'
                . '<td>' . ($e['is_dir'] ? '' : Util::humanSize((int) $e['size'])) . '</td><td>' . gmdate('Y-m-d H:i', (int) $e['mtime']) . ' UTC</td></tr>';
        }
        return '<!DOCTYPE html><html><head><meta charset="utf-8"><title>' . $title . '</title>'
            . '<style>body{font:14px system-ui,sans-serif;margin:2em;color:#222}td{padding:3px 18px 3px 0;white-space:nowrap}</style></head>'
            . '<body><h1>' . $title . '</h1><table>' . $rows . '</table></body></html>';
    }

    private static function put(Ctx $ctx): never
    {
        if ($ctx->rel === '' || is_dir($ctx->abs)) {
            throw new WebDavError(405, 'PUT is not allowed on a collection', ['Allow' => 'OPTIONS, GET, HEAD, DELETE, PROPFIND, PROPPATCH, COPY, MOVE, LOCK, UNLOCK']);
        }
        if (Http::header('Content-Range') !== null) {
            throw new WebDavError(400, 'Partial PUT is not supported');
        }
        $parent = self::parentCtx($ctx);
        if (!is_dir($parent->abs)) {
            throw new WebDavError(409, 'The parent collection does not exist');
        }
        $exists = is_file($ctx->abs);
        self::checkPreconditions($ctx, $exists);
        self::assertUnlocked($ctx, false);
        $ctx->need($exists ? 'edit' : 'create'); // refuse before the body is read
        [$stream, $size] = self::requestBody();
        try {
            Storage::putStream($ctx, $stream, $size, ['actor' => self::$user, 'source' => 'webdav']);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
        clearstatcache(true, $ctx->abs);
        $mtime = (int) @filemtime($ctx->abs);
        self::respond($exists ? 204 : 201, '', [
            'ETag' => self::etag($ctx->abs, (int) @filesize($ctx->abs), $mtime),
            'Last-Modified' => gmdate('D, d M Y H:i:s', $mtime) . ' GMT',
        ]);
    }

    private static function mkcol(Ctx $ctx): never
    {
        if (self::hasBody()) {
            throw new WebDavError(415, 'MKCOL with a request body is not supported');
        }
        if ($ctx->rel === '' || file_exists($ctx->abs)) {
            throw new WebDavError(405, 'A resource with this name exists already', ['Allow' => self::ALLOW]);
        }
        $parent = self::parentCtx($ctx);
        if (!is_dir($parent->abs)) {
            throw new WebDavError(409, 'The parent collection does not exist');
        }
        self::assertUnlocked($ctx, false);
        Storage::mkdir($parent, Util::baseName($ctx->rel), self::$user);
        self::respond(201);
    }

    private static function delete(Ctx $ctx): never
    {
        if (!file_exists($ctx->abs)) {
            throw new NotFoundException('Not found');
        }
        if ($ctx->rel === '') {
            throw new WebDavError(403, 'The root collection cannot be deleted');
        }
        $isDir = is_dir($ctx->abs);
        self::assertUnlocked($ctx, $isDir);
        $tokens = self::tokensUnder($ctx);
        Storage::delete($ctx, self::$user);
        self::forgetLocks($tokens);
        self::respond(204);
    }

    // ------------------------------------------------------------------
    // COPY / MOVE
    // ------------------------------------------------------------------

    private static function copyOrMove(Ctx $src, bool $move): never
    {
        $verb = $move ? 'moved' : 'copied';
        if (!file_exists($src->abs)) {
            throw new NotFoundException('Not found');
        }
        if ($src->rel === '') {
            throw new WebDavError(403, 'The root collection cannot be ' . $verb);
        }
        $dst = Paths::resolve(self::parseDestination(Http::header('Destination')), self::$user);
        if ($dst->rel === '') {
            throw new WebDavError(403, 'The root collection cannot be replaced');
        }
        $srcIsDir = is_dir($src->abs);
        $overwrite = strtoupper(trim((string) (Http::header('Overwrite') ?? 'T'))) !== 'F';
        $dstExists = file_exists($dst->abs);
        // On case-insensitive file systems "Report.txt" and "report.txt" are the same item.
        $sameItem = $dstExists && realpath($dst->abs) === realpath($src->abs);
        $sameParent = Util::dirName($src->rel) === Util::dirName($dst->rel);
        $dstName = Util::baseName($dst->rel);
        if ($dst->rel === $src->rel || ($sameItem && (!$sameParent || $dstName === Util::baseName($src->rel)))) {
            throw new WebDavError(403, 'Source and destination are the same resource');
        }
        $replacing = $dstExists && !$sameItem;
        if ($replacing && !$overwrite) {
            throw new WebDavError(412, 'The destination exists and Overwrite is F');
        }
        $dstParent = Paths::resolve('/' . Util::dirName($dst->rel), self::$user);
        if (!is_dir($dstParent->abs)) {
            throw new WebDavError(409, 'The destination parent collection does not exist');
        }
        if ($srcIsDir && !$sameItem && Util::pathWithin($dst->rel, $src->rel)) {
            throw new WebDavError(409, 'A collection cannot be ' . $verb . ' into itself');
        }
        if ($move) {
            self::assertUnlocked($src, $srcIsDir);
        }
        self::assertUnlocked($dst, $replacing && is_dir($dst->abs));

        $actor = self::$user;
        $srcTokens = $move ? self::tokensUnder($src) : [];
        if ($replacing) {
            Storage::delete($dst, $actor); // Overwrite: T replaces the destination (it lands in the trash when enabled)
            clearstatcache();
        }
        if ($move && $sameParent) {
            Storage::rename($src, $dstName, $actor);
        } elseif ($move) {
            self::renameTo($dstParent, Storage::move($src, $dstParent, $actor, 'rename'), $dstName);
        } elseif ($srcIsDir && trim((string) (Http::header('Depth') ?? 'infinity')) === '0') {
            Storage::mkdir($dstParent, $dstName, $actor); // COPY of a collection with Depth: 0 creates the collection only
        } else {
            self::renameTo($dstParent, Storage::copy($src, $dstParent, $actor, 'rename'), $dstName);
        }
        self::forgetLocks($srcTokens); // a lock never travels with a moved resource (RFC 4918)
        self::respond($replacing ? 204 : 201);
    }

    /**
     * Give a freshly moved / copied item its final name when it differs. The move or
     * copy used the "rename" conflict policy, so a clash with an unrelated item that
     * carries the source name only produced a temporary "(2)" name, never a loss.
     */
    private static function renameTo(Ctx $parent, ?array $item, string $name): void
    {
        if ($item !== null && (string) $item['name'] !== $name) {
            Storage::rename($parent->childCtx((string) $item['name']), $name, self::$user);
        }
    }

    // ------------------------------------------------------------------
    // Locks
    // ------------------------------------------------------------------

    /** Active locks of an owner, loaded once per request; expired rows are purged now and then. */
    private static function ownerLocks(int $ownerId): array
    {
        if (!isset(self::$lockCache[$ownerId])) {
            if (random_int(1, 10) === 1) {
                DB::delete('dav_locks', 'expires_at <= ?', [fr_now()]);
            }
            self::$lockCache[$ownerId] = DB::all('SELECT * FROM {{dav_locks}} WHERE owner_id = ? AND expires_at > ?', [$ownerId, fr_now()]);
        }
        return self::$lockCache[$ownerId];
    }

    /**
     * Locks that apply to a path: on the item itself, on an ancestor locked with
     * Depth: infinity and, when $deep, on anything below (collection operations).
     */
    private static function locksFor(int $ownerId, string $rel, bool $deep): array
    {
        $out = [];
        foreach (self::ownerLocks($ownerId) as $lock) {
            $path = (string) $lock['path'];
            $isAncestor = $path === '' || str_starts_with($rel, $path . '/');
            $isBelow = $rel === '' || str_starts_with($path, $rel . '/');
            if ($path === $rel || ($isAncestor && (int) $lock['depth'] > 0) || ($deep && $isBelow)) {
                $out[] = $lock;
            }
        }
        return $out;
    }

    /** Tokens of the locks on an item or anywhere below it (not those inherited from above). */
    private static function tokensUnder(Ctx $ctx): array
    {
        $tokens = [];
        foreach (self::ownerLocks($ctx->ownerId) as $lock) {
            $path = (string) $lock['path'];
            if ($path === $ctx->rel || $ctx->rel === '' || str_starts_with($path, $ctx->rel . '/')) {
                $tokens[] = (string) $lock['token'];
            }
        }
        return $tokens;
    }

    private static function forgetLocks(array $tokens): void
    {
        foreach ($tokens as $token) {
            DB::delete('dav_locks', 'token = ?', [$token]);
        }
        self::$lockCache = [];
    }

    /** 423 unless every lock that applies to the item is ours and its token is presented in the If header. */
    private static function assertUnlocked(Ctx $ctx, bool $deep): void
    {
        $uid = (int) self::$user['id'];
        $tokens = self::ifTokens();
        foreach (self::locksFor($ctx->ownerId, $ctx->rel, $deep) as $lock) {
            if ((int) $lock['user_id'] !== $uid || !in_array((string) $lock['token'], $tokens, true)) {
                $path = (string) $lock['path'];
                throw new WebDavError(423, '"' . ($path === '' ? '/' : Util::baseName($path)) . '" is locked');
            }
        }
        try {
            Meta::assertUnlocked($ctx->ownerId, $ctx->rel, self::$user, $deep); // locks taken in the web interface
        } catch (PermissionException $e) {
            throw new WebDavError(423, $e->getMessage());
        }
    }

    private static function activeLocksXml(array $locks): string
    {
        $xml = '';
        foreach ($locks as $lock) {
            $path = (string) $lock['path'];
            $remaining = max(0, (int) strtotime((string) $lock['expires_at']) - time());
            $xml .= '<D:activelock><D:locktype><D:write/></D:locktype>'
                . '<D:lockscope><D:' . ($lock['scope'] === 'shared' ? 'shared' : 'exclusive') . '/></D:lockscope>'
                . '<D:depth>' . ((int) $lock['depth'] > 0 ? 'infinity' : '0') . '</D:depth>'
                . '<D:owner>' . self::ownerXml((string) ($lock['owner_info'] ?? '')) . '</D:owner>'
                . '<D:timeout>Second-' . $remaining . '</D:timeout>'
                . '<D:locktoken><D:href>' . self::xmlEscape((string) $lock['token']) . '</D:href></D:locktoken>'
                . '<D:lockroot><D:href>' . self::xmlEscape(self::hrefFor($path, is_dir(self::absOf($path)))) . '</D:href></D:lockroot>'
                . '</D:activelock>';
        }
        return $xml;
    }

    /** Owner XML as sent by the client, re-emitted only while it is still well formed on its own. */
    private static function ownerXml(string $info): string
    {
        if ($info === '') {
            return '';
        }
        $previous = libxml_use_internal_errors(true);
        $doc = new DOMDocument();
        $ok = $doc->loadXML('<D:owner xmlns:D="DAV:">' . $info . '</D:owner>', LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        return $ok ? $info : self::xmlEscape($info); // not well formed any more: keep it as literal text
    }

    /** Inner XML of <D:owner> in a LOCK body, made self-contained (C14N keeps the namespace declarations). */
    private static function ownerInfo(DOMDocument $doc): string
    {
        foreach (self::elements($doc->documentElement) as $child) {
            if ($child->localName === 'owner') {
                $xml = '';
                foreach ($child->childNodes as $node) {
                    $xml .= $node instanceof DOMElement ? (string) $node->C14N() : self::xmlEscape($node->textContent);
                }
                return mb_substr(trim($xml), 0, 2000);
            }
        }
        return '';
    }

    private static function lockBody(array $lock): string
    {
        return '<?xml version="1.0" encoding="utf-8"?>' . "\n" . '<D:prop xmlns:D="DAV:"><D:lockdiscovery>' . self::activeLocksXml([$lock]) . '</D:lockdiscovery></D:prop>';
    }

    private static function lock(Ctx $ctx): never
    {
        if (!$ctx->can('lock')) {
            throw new PermissionException('You are not allowed to lock files here');
        }
        $uid = (int) self::$user['id'];
        $timeout = self::parseTimeout(Http::header('Timeout'));
        $expires = gmdate('Y-m-d H:i:s', time() + $timeout);
        $doc = self::parseXml(self::body());

        if ($doc === null) {
            // Refresh: no body, the token comes in the If header (a few clients use Lock-Token instead).
            $tokens = self::ifTokens();
            $tokens[] = trim((string) (Http::header('Lock-Token') ?? ''), " \t<>");
            foreach (self::locksFor($ctx->ownerId, $ctx->rel, false) as $lock) {
                if (!in_array((string) $lock['token'], $tokens, true)) {
                    continue;
                }
                if ((int) $lock['user_id'] !== $uid) {
                    throw new WebDavError(403, 'This lock belongs to another user');
                }
                DB::update('dav_locks', ['expires_at' => $expires], 'token = ?', [$lock['token']]);
                $lock['expires_at'] = $expires;
                self::respondXml(200, self::lockBody($lock), ['Lock-Token' => '<' . $lock['token'] . '>']);
            }
            throw new WebDavError(412, 'No lock to refresh');
        }

        if ($doc->documentElement->localName !== 'lockinfo') {
            throw new WebDavError(400, 'Expected a lockinfo element');
        }
        $scope = $doc->getElementsByTagNameNS('DAV:', 'shared')->length > 0 ? 'shared' : 'exclusive';
        $exists = file_exists($ctx->abs);
        $isDir = $exists && is_dir($ctx->abs);
        $depth = $isDir && trim((string) (Http::header('Depth') ?? 'infinity')) !== '0' ? 1 : 0;

        // Conflicts: locks on this item, above it (infinite depth) or, for a collection lock, below it.
        $own = null;
        $tokens = self::ifTokens();
        foreach (self::locksFor($ctx->ownerId, $ctx->rel, $depth > 0) as $lock) {
            $mine = (int) $lock['user_id'] === $uid;
            if ($mine && (string) $lock['path'] === $ctx->rel) {
                $own = $lock; // the same user locking the same item again gets the existing lock back (stale lock after a crash)
                continue;
            }
            if ($mine && in_array((string) $lock['token'], $tokens, true)) {
                continue;
            }
            throw new WebDavError(423, '"' . Util::baseName((string) $lock['path']) . '" is locked');
        }
        try {
            Meta::assertUnlocked($ctx->ownerId, $ctx->rel, self::$user, $depth > 0);
        } catch (PermissionException $e) {
            throw new WebDavError(423, $e->getMessage());
        }

        $created = false;
        if (!$exists) {
            // Windows and macOS lock a name before they create the file: create an empty one.
            $parent = self::parentCtx($ctx);
            if (!is_dir($parent->abs)) {
                throw new WebDavError(409, 'The parent collection does not exist');
            }
            $empty = fopen('php://temp', 'w+b');
            if ($empty === false) {
                throw new WebDavError(500, 'Temporary stream unavailable');
            }
            try {
                Storage::putStream($ctx, $empty, 0, ['actor' => self::$user, 'source' => 'webdav', 'notify' => false]);
            } finally {
                fclose($empty);
            }
            $created = true;
        }

        if ($own !== null) {
            DB::update('dav_locks', ['expires_at' => $expires, 'depth' => $depth], 'token = ?', [$own['token']]);
            $lock = ['expires_at' => $expires, 'depth' => $depth] + $own;
        } else {
            $lock = [
                'token' => 'opaquelocktoken:' . Util::uuid(), 'owner_id' => $ctx->ownerId, 'path_hash' => sha1($ctx->rel), 'path' => $ctx->rel,
                'user_id' => $uid, 'depth' => $depth, 'scope' => $scope, 'owner_info' => self::ownerInfo($doc), 'expires_at' => $expires, 'created_at' => fr_now(),
            ];
            DB::insert('dav_locks', $lock);
            Activity::log('lock', ['user' => self::$user, 'owner_id' => $ctx->ownerId, 'path' => $ctx->rel, 'is_dir' => $isDir ? 1 : 0, 'details' => ['token' => $lock['token'], 'timeout' => $timeout, 'depth' => $depth]]);
        }
        self::$lockCache = [];
        self::respondXml($created ? 201 : 200, self::lockBody($lock), ['Lock-Token' => '<' . $lock['token'] . '>']);
    }

    private static function unlock(Ctx $ctx): never
    {
        $token = trim((string) (Http::header('Lock-Token') ?? ''), " \t<>");
        if ($token === '') {
            throw new WebDavError(400, 'Lock-Token header missing');
        }
        $lock = DB::one('SELECT * FROM {{dav_locks}} WHERE token = ? AND owner_id = ?', [$token, $ctx->ownerId]);
        if ($lock === null) {
            throw new WebDavError(409, 'No such lock');
        }
        if ((int) $lock['user_id'] !== (int) self::$user['id'] && !Users::isSuper(self::$user)) {
            throw new WebDavError(403, 'This lock belongs to another user');
        }
        DB::delete('dav_locks', 'token = ?', [$token]);
        self::$lockCache = [];
        $path = (string) $lock['path'];
        Activity::log('unlock', ['user' => self::$user, 'owner_id' => $ctx->ownerId, 'path' => $path, 'is_dir' => is_dir(self::absOf($path)) ? 1 : 0, 'details' => ['token' => $token]]);
        self::respond(204);
    }
}
