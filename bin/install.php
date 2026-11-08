<?php
/**
 * Non-interactive installer.
 *
 *   php bin/install.php --db-name=filerun --db-user=filerun --db-pass=secret \
 *       --admin-user=admin --admin-pass='Str0ngPass!' --admin-email=you@example.com \
 *       --data-dir=/var/filerun-data --public-url=https://files.example.com [--db-host=] [--db-port=] [--db-prefix=] [--app-name=]
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { exit("CLI only\n"); }
require dirname(__DIR__) . '/app/bootstrap.php';

$opts = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z\-]+)=(.*)$/s', $arg, $m)) {
        $opts[str_replace('-', '_', $m[1])] = $m[2];
    } elseif ($arg === '--force') {
        $opts['force'] = true;
    }
}
if (fr_installed() && empty($opts['force'])) {
    fwrite(STDERR, "Already installed (config.php exists). Use --force to rewrite the configuration.\n");
    exit(1);
}
try {
    $res = Installer::install($opts);
    echo "Installed.\n  config:   {$res['config']}\n  data dir: {$res['data_dir']}\n  admin:    {$res['admin']}\n";
    echo "Add this cron job:\n  " . Installer::cronCommand() . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Install failed: ' . $e->getMessage() . "\n");
    exit(1);
}
