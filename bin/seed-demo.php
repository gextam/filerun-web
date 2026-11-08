<?php
/**
 * Fill an account with demo content (used for screenshots and for trying the app).
 *   php bin/seed-demo.php <username>
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { exit("CLI only\n"); }
require dirname(__DIR__) . '/app/bootstrap.php';
if (!fr_installed()) { fwrite(STDERR, "Not installed.\n"); exit(1); }
DB::connect();
$u = Users::findByUsername((string) ($argv[1] ?? 'demo'));
if (!$u) { fwrite(STDERR, "Unknown user\n"); exit(1); }
$home = Users::ensureHome($u);
$mk = static function (string $rel, string $content) use ($home): void {
    $abs = $home . '/' . $rel;
    @mkdir(dirname($abs), 0750, true);
    file_put_contents($abs, $content);
};
$img = static function (string $rel, int $w, int $h, array $c1, array $c2, string $label) use ($home): void {
    if (!function_exists('imagecreatetruecolor')) return;
    $im = imagecreatetruecolor($w, $h);
    for ($y = 0; $y < $h; $y++) {
        $t = $y / max(1, $h - 1);
        $col = imagecolorallocate($im, (int) ($c1[0] + ($c2[0] - $c1[0]) * $t), (int) ($c1[1] + ($c2[1] - $c1[1]) * $t), (int) ($c1[2] + ($c2[2] - $c1[2]) * $t));
        imageline($im, 0, $y, $w, $y, $col);
    }
    $white = imagecolorallocate($im, 255, 255, 255);
    imagefilledellipse($im, (int) ($w * 0.72), (int) ($h * 0.3), (int) ($w * 0.18), (int) ($w * 0.18), imagecolorallocatealpha($im, 255, 255, 255, 60));
    imagestring($im, 5, 24, $h - 40, $label, $white);
    $abs = $home . '/' . $rel;
    @mkdir(dirname($abs), 0750, true);
    str_ends_with($rel, '.png') ? imagepng($im, $abs) : imagejpeg($im, $abs, 88);
    imagedestroy($im);
};

$mk('Documents/Project plan.md', "# Project plan\n\n## Goals\n\n- Launch the client portal in **Q1**\n- Migrate the FTP users\n- Train the support team\n\n## Timeline\n\n| Phase | Owner | Due |\n|-------|-------|-----|\n| Discovery | Ana | Jan 15 |\n| Build | Dev team | Feb 28 |\n| Rollout | Ops | Mar 20 |\n\n> Files stay on our own server. No migration needed.\n\n```js\nconsole.log('hello from the plan');\n```\n");
$mk('Documents/Budget 2026.csv', "Item,Q1,Q2,Q3,Q4\nHosting,1200,1200,1300,1300\nLicenses,800,800,800,800\nTraining,0,1500,0,500\nTravel,400,600,900,300\n");
$mk('Documents/Meeting notes.txt', "Meeting notes – kickoff\n\nAttendees: Ana, Ben, Chris\n\n1. Agreed on the folder structure for client deliverables.\n2. Every client gets a shared folder with the Contributor role.\n3. Public links expire after 30 days.\n4. Weekly report every Friday.\n");
$mk('Documents/Report.pdf', "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]/Contents 4 0 R/Resources<</Font<</F1 5 0 R>>>>>>endobj\n4 0 obj<</Length 120>>stream\nBT /F1 28 Tf 60 760 Td (Quarterly report) Tj 0 -40 Td /F1 14 Tf (Revenue grew 12% compared to last quarter.) Tj ET\nendstream\nendobj\n5 0 obj<</Type/Font/Subtype/Type1/BaseFont/Helvetica>>endobj\nxref\n0 6\n0000000000 65535 f \n0000000009 00000 n \n0000000052 00000 n \n0000000101 00000 n \n0000000230 00000 n \n0000000400 00000 n \ntrailer<</Size 6/Root 1 0 R>>\nstartxref\n470\n%%EOF\n");
$mk('Documents/Contract draft.docx', Templates::supports('docx') ? Templates::blank('docx') : '');
$mk('Documents/Invoices/2026-01 Invoice.txt', "Invoice 2026-01\nAmount: 1,200.00 EUR\nStatus: paid\n");
$mk('Documents/Invoices/2026-02 Invoice.txt', "Invoice 2026-02\nAmount: 980.00 EUR\nStatus: open\n");
$img('Photos/Sunset over the bay.jpg', 1600, 1000, [255, 140, 60], [90, 30, 110], 'Sunset over the bay');
$img('Photos/Mountain lake.jpg', 1600, 1000, [60, 120, 200], [20, 60, 40], 'Mountain lake');
$img('Photos/City lights.jpg', 1600, 1000, [20, 20, 50], [230, 200, 80], 'City lights');
$img('Photos/Forest trail.png', 1200, 1200, [30, 90, 40], [170, 220, 120], 'Forest trail');
$img('Design/Logo concept.png', 800, 800, [37, 99, 235], [120, 60, 220], 'Logo concept');
$mk('Design/Logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 200"><rect width="200" height="200" rx="40" fill="#2563eb"/><path d="M50 70a10 10 0 0 1 10-10h30l14 14h46a10 10 0 0 1 10 10v56a10 10 0 0 1-10 10H60a10 10 0 0 1-10-10z" fill="#fff"/></svg>');
$mk('Code/app.js', "import { api } from './api.js';\n\nexport async function listFolder(path = '/') {\n  const res = await api.get('files/list', { p: path });\n  return res.items.filter(i => !i.is_dir);\n}\n\nlistFolder().then(items => console.log(items.length, 'files'));\n");
$mk('Code/styles.css', ":root { --accent: #2563eb; }\n.button { background: var(--accent); color: #fff; border-radius: 8px; padding: 8px 14px; }\n.button:hover { filter: brightness(.9); }\n");
$mk('Code/report.py', "import csv\n\nwith open('Budget 2026.csv') as f:\n    rows = list(csv.DictReader(f))\n\nfor row in rows:\n    total = sum(int(row[q]) for q in ('Q1', 'Q2', 'Q3', 'Q4'))\n    print(f\"{row['Item']}: {total}\")\n");
$mk('Code/config.json', "{\n  \"name\": \"client-portal\",\n  \"version\": \"1.0.0\",\n  \"features\": [\"sharing\", \"webdav\", \"versions\"]\n}\n");
if (class_exists('ZipArchive')) {
    $zip = new ZipArchive();
    $zip->open($home . '/Archive 2025.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('2025/january.txt', "January summary\n");
    $zip->addFromString('2025/february.txt', "February summary\n");
    $zip->addFromString('2025/readme.md', "# 2025 archive\n");
    $zip->close();
}
// Audio and video samples (generated with ffmpeg when it is available) so the Media section has something to show.
if ($ffmpeg = Util::bin('ffmpeg')) {
    @mkdir($home . '/Music', 0750, true);
    @mkdir($home . '/Videos', 0750, true);
    $tracks = [['Music/Morning light.mp3', 'Aurora Fields', 'Daybreak', 'Morning light', 440], ['Music/Night drive.mp3', 'Aurora Fields', 'Daybreak', 'Night drive', 330], ['Music/Harbour.mp3', 'The Quiet Coast', 'Tides', 'Harbour', 262]];
    foreach ($tracks as [$rel, $artist, $album, $title, $freq]) {
        if (is_file($home . '/' . $rel)) continue;
        Util::run([$ffmpeg, '-y', '-loglevel', 'error', '-f', 'lavfi', '-i', 'sine=frequency=' . $freq . ':duration=4', '-metadata', 'artist=' . $artist, '-metadata', 'album=' . $album, '-metadata', 'title=' . $title, '-metadata', 'date=2026', '-codec:a', 'libmp3lame', '-q:a', '6', $home . '/' . $rel], 60);
    }
    if (!is_file($home . '/Videos/Test pattern.mp4')) {
        Util::run([$ffmpeg, '-y', '-loglevel', 'error', '-f', 'lavfi', '-i', 'testsrc=duration=4:size=640x360:rate=12', '-f', 'lavfi', '-i', 'sine=frequency=220:duration=4', '-shortest', '-pix_fmt', 'yuv420p', '-metadata', 'title=Test pattern', $home . '/Videos/Test pattern.mp4'], 90);
    }
}
Index::rescan($u);
Indexer::indexTree((int) $u['id'], '', 120);
echo "Seeded demo content for {$u['username']} in $home\n";
