<?php
/**
 * Maintenance entry point. Run every minute:
 *   * * * * * php /path/to/filerun-web/app/cron.php >/dev/null 2>&1
 *
 * Optional argument: jobs | maintenance | rescan <username> | reindex <username> | stats
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}
require __DIR__ . '/bootstrap.php';
if (!fr_installed()) {
    fwrite(STDERR, "Not installed yet.\n");
    exit(1);
}
DB::connect();
Activity::$client = 'cron';
$task = $argv[1] ?? 'maintenance';
$lock = fopen(fr_data_dir('temp') . '/cron.lock', 'c');
if ($lock && !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0); // previous run still going
}
switch ($task) {
    case 'jobs':
        echo 'processed ' . Jobs::run(50, 500) . " jobs, pending " . Jobs::pending() . "\n";
        break;
    case 'rescan':
    case 'reindex':
        $u = Users::findByUsername((string) ($argv[2] ?? ''));
        if (!$u) { fwrite(STDERR, "unknown user\n"); exit(1); }
        if ($task === 'rescan') {
            print_r(Index::rescan($u));
        } else {
            echo 'indexed ' . Indexer::indexTree((int) $u['id'], '', 3600) . " files\n";
        }
        break;
    case 'stats':
        echo Util::json(['jobs_pending' => Jobs::pending(), 'users' => DB::val('SELECT COUNT(*) FROM {{users}}'), 'files' => DB::val('SELECT COUNT(*) FROM {{file_index}}'), 'last_run' => Settings::get('cron_last_run')], true) . "\n";
        break;
    default:
        $r = Installer::runMaintenance(50);
        if (in_array('-v', $argv, true)) echo Util::json($r, true) . "\n";
}
if ($lock) {
    flock($lock, LOCK_UN);
    fclose($lock);
}
