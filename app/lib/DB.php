<?php
declare(strict_types=1);

/**
 * Thin PDO wrapper for MySQL / MariaDB. Table names are written as {{name}}
 * and expanded with the configured prefix (default "fr_").
 */
final class DB
{
    private static ?PDO $pdo = null;
    private static string $prefix = 'fr_';
    private static int $txDepth = 0;

    public static function connect(?array $cfg = null): PDO
    {
        if (self::$pdo !== null && $cfg === null) {
            return self::$pdo;
        }
        $cfg = $cfg ?? (array) fr_config('db', []);
        self::$prefix = (string) ($cfg['prefix'] ?? 'fr_');
        $host = (string) ($cfg['host'] ?? '127.0.0.1');
        $port = (int) ($cfg['port'] ?? 3306);
        $name = (string) ($cfg['name'] ?? '');
        $dsn = 'mysql:';
        if (!empty($cfg['socket'])) {
            $dsn .= 'unix_socket=' . $cfg['socket'];
        } else {
            $dsn .= 'host=' . $host . ';port=' . $port;
        }
        if ($name !== '') {
            $dsn .= ';dbname=' . $name;
        }
        $dsn .= ';charset=utf8mb4';
        $pdo = new PDO($dsn, (string) ($cfg['user'] ?? ''), (string) ($cfg['pass'] ?? ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
            PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
        ]);
        $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, time_zone = '+00:00', sql_mode = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'");
        return self::$pdo = $pdo;
    }

    public static function pdo(): PDO
    {
        return self::$pdo ?? self::connect();
    }

    public static function prefix(): string
    {
        return self::$prefix;
    }

    public static function table(string $name): string
    {
        return '`' . self::$prefix . $name . '`';
    }

    /** Expand {{table}} placeholders. */
    public static function sql(string $sql): string
    {
        return preg_replace_callback('/\{\{(\w+)\}\}/', static fn($m) => self::table($m[1]), $sql) ?? $sql;
    }

    public static function q(string $sql, array $params = []): PDOStatement
    {
        $stmt = self::pdo()->prepare(self::sql($sql));
        foreach ($params as $k => $v) {
            $key = is_int($k) ? $k + 1 : (str_starts_with($k, ':') ? $k : ':' . $k);
            $type = PDO::PARAM_STR;
            if (is_int($v)) $type = PDO::PARAM_INT;
            elseif (is_bool($v)) { $v = $v ? 1 : 0; $type = PDO::PARAM_INT; }
            elseif ($v === null) $type = PDO::PARAM_NULL;
            elseif (is_float($v)) $v = (string) $v;
            elseif (is_array($v)) $v = Util::json($v);
            $stmt->bindValue($key, $v, $type);
        }
        $stmt->execute();
        return $stmt;
    }

    public static function exec(string $sql): int
    {
        return (int) self::pdo()->exec(self::sql($sql));
    }

    public static function all(string $sql, array $params = []): array
    {
        return self::q($sql, $params)->fetchAll();
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::q($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public static function val(string $sql, array $params = []): mixed
    {
        $v = self::q($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    public static function col(string $sql, array $params = []): array
    {
        return self::q($sql, $params)->fetchAll(PDO::FETCH_COLUMN);
    }

    public static function insert(string $table, array $row, bool $ignore = false): int
    {
        $cols = array_keys($row);
        $sql = ($ignore ? 'INSERT IGNORE INTO ' : 'INSERT INTO ') . self::table($table)
            . ' (`' . implode('`,`', $cols) . '`) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')';
        self::q($sql, array_values($row));
        return (int) self::pdo()->lastInsertId();
    }

    /** INSERT ... ON DUPLICATE KEY UPDATE for the given columns (default: all non-key columns). */
    public static function upsert(string $table, array $row, ?array $updateCols = null): void
    {
        $cols = array_keys($row);
        $updateCols = $updateCols ?? $cols;
        $set = implode(',', array_map(static fn($c) => "`$c`=VALUES(`$c`)", $updateCols));
        $sql = 'INSERT INTO ' . self::table($table) . ' (`' . implode('`,`', $cols) . '`) VALUES ('
            . implode(',', array_fill(0, count($cols), '?')) . ') ON DUPLICATE KEY UPDATE ' . $set;
        self::q($sql, array_values($row));
    }

    public static function update(string $table, array $row, string $where, array $params = []): int
    {
        if (!$row) return 0;
        $set = implode(',', array_map(static fn($c) => "`$c`=?", array_keys($row)));
        $sql = 'UPDATE ' . self::table($table) . ' SET ' . $set . ' WHERE ' . $where;
        return self::q($sql, array_merge(array_values($row), array_values($params)))->rowCount();
    }

    public static function delete(string $table, string $where, array $params = []): int
    {
        return self::q('DELETE FROM ' . self::table($table) . ' WHERE ' . $where, $params)->rowCount();
    }

    public static function lastId(): int
    {
        return (int) self::pdo()->lastInsertId();
    }

    /** Run a callback inside a transaction (nested calls reuse the outer one). */
    public static function tx(callable $fn): mixed
    {
        $pdo = self::pdo();
        if (self::$txDepth === 0) {
            $pdo->beginTransaction();
        }
        self::$txDepth++;
        try {
            $result = $fn();
            self::$txDepth--;
            if (self::$txDepth === 0) {
                $pdo->commit();
            }
            return $result;
        } catch (Throwable $e) {
            self::$txDepth--;
            if (self::$txDepth === 0 && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function escapeLike(string $s): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s);
    }

    public static function tableExists(string $name): bool
    {
        try {
            self::q('SELECT 1 FROM ' . self::table($name) . ' LIMIT 1');
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public static function columnExists(string $table, string $column): bool
    {
        $rows = self::all('SHOW COLUMNS FROM ' . self::table($table) . ' LIKE ?', [$column]);
        return count($rows) > 0;
    }
}
