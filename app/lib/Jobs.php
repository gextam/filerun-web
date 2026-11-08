<?php
declare(strict_types=1);

/**
 * A tiny database-backed job queue for work that should not block a request:
 * content indexing, OCR, recursive rescans. Processed by cron.php and, when no
 * cron is installed, opportunistically at the end of web requests.
 */
final class Jobs
{
    public static function push(string $type, array $payload, int $delaySeconds = 0): void
    {
        try {
            // Avoid piling up identical pending jobs.
            $json = Util::json($payload);
            $dup = DB::val('SELECT id FROM {{jobs}} WHERE type = ? AND payload = ? AND locked_at IS NULL LIMIT 1', [$type, $json]);
            if ($dup) return;
            DB::insert('jobs', ['type' => $type, 'payload' => $json, 'run_after' => gmdate('Y-m-d H:i:s', time() + $delaySeconds), 'created_at' => fr_now()]);
        } catch (Throwable $e) {
            fr_log('job push failed: ' . $e->getMessage());
        }
    }

    public static function pending(): int
    {
        return (int) DB::val('SELECT COUNT(*) FROM {{jobs}} WHERE locked_at IS NULL OR locked_at < ?', [gmdate('Y-m-d H:i:s', time() - 900)]);
    }

    /** Run jobs for up to $maxSeconds. Returns how many were processed. */
    public static function run(int $maxSeconds = 50, int $maxJobs = 200): int
    {
        $start = time();
        $done = 0;
        while (time() - $start < $maxSeconds && $done < $maxJobs) {
            $job = DB::one('SELECT * FROM {{jobs}} WHERE run_after <= ? AND (locked_at IS NULL OR locked_at < ?) ORDER BY id LIMIT 1', [fr_now(), gmdate('Y-m-d H:i:s', time() - 900)]);
            if (!$job) break;
            $claimed = DB::update('jobs', ['locked_at' => fr_now(), 'attempts' => (int) $job['attempts'] + 1], 'id = ? AND (locked_at IS NULL OR locked_at = ?)', [(int) $job['id'], $job['locked_at']]);
            if (!$claimed) continue;
            try {
                self::handle($job['type'], (array) Util::jsonDecode($job['payload'], []), max(5, $maxSeconds - (time() - $start)));
                DB::delete('jobs', 'id = ?', [(int) $job['id']]);
            } catch (Throwable $e) {
                fr_log('job ' . $job['type'] . ' failed: ' . $e->getMessage());
                if ((int) $job['attempts'] >= 3) {
                    DB::delete('jobs', 'id = ?', [(int) $job['id']]);
                } else {
                    DB::update('jobs', ['locked_at' => null, 'run_after' => gmdate('Y-m-d H:i:s', time() + 300)], 'id = ?', [(int) $job['id']]);
                }
            }
            $done++;
        }
        return $done;
    }

    private static function handle(string $type, array $p, int $budget): void
    {
        switch ($type) {
            case 'index':
                $owner = Users::find((int) ($p['owner_id'] ?? 0));
                if (!$owner) return;
                $abs = Users::homeDir($owner) . '/' . $p['path'];
                Indexer::index((int) $owner['id'], (string) $p['path'], $abs);
                break;
            case 'index_tree':
                Indexer::indexTree((int) ($p['owner_id'] ?? 0), (string) ($p['path'] ?? ''), min(50, $budget));
                break;
            case 'rescan':
                $owner = Users::find((int) ($p['owner_id'] ?? 0));
                if ($owner) Index::rescan($owner);
                break;
            case 'recalc_usage':
                $owner = Users::find((int) ($p['owner_id'] ?? 0));
                if ($owner) Users::recalcUsage($owner);
                break;
            case 'mail':
                Mailer::send((string) ($p['to'] ?? ''), (string) ($p['subject'] ?? ''), (string) ($p['html'] ?? ''));
                break;
        }
    }

    /** Called at the end of web requests when cron does not seem to run. */
    public static function opportunistic(): void
    {
        try {
            $last = (string) Settings::get('cron_last_run', '');
            if ($last !== '' && time() - strtotime($last) < 600) return; // cron is alive
            if (random_int(1, 5) !== 1) return;
            if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
            self::run(4, 3);
        } catch (Throwable) {
        }
    }
}
