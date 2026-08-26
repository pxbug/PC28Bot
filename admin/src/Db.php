<?php
/**
 * Database wrapper - MySQL优先，SQLite备用，零配置
 */
class DB {
    private static $pdo = null;
    private static $driver = 'mysql'; // or 'sqlite'

    public static function init($path = null): void {
        if (self::$pdo !== null) return;

        $config = [];

        // Support three config formats:
        // 1. src/config.json  (JSON)
        // 2. ../config.php   (JSON inside .php file, common on Windows hosts)
        // 3. ../config.php   (PHP array return)
        $jsonConfig = __DIR__ . '/config.json';
        $phpConfig = __DIR__ . '/../config.php';

        if (file_exists($jsonConfig)) {
            $raw = file_get_contents($jsonConfig);
            $config = json_decode($raw, true) ?: [];
        } elseif (file_exists($phpConfig)) {
            $raw = file_get_contents($phpConfig);
            $raw = trim($raw);
            // Detect if it's JSON or PHP array
            if ($raw !== '' && $raw[0] === '{') {
                $config = json_decode($raw, true) ?: [];
            } else {
                $phpConfigData = require $phpConfig;
                if (is_array($phpConfigData)) {
                    $config = [
                        'host'     => $phpConfigData['host']     ?? null,
                        'dbname'   => $phpConfigData['database'] ?? null,
                        'user'     => $phpConfigData['username'] ?? null,
                        'pass'     => $phpConfigData['password'] ?? null,
                    ];
                }
            }
        }

        // MySQL first
        if (!empty($config['host'])) {
            self::$driver = 'mysql';
            $host = $config['host'];
            $dbname = $config['dbname'] ?? 'pc28_admin';
            $user = $config['user'] ?? 'root';
            $pass = $config['pass'] ?? '';

            self::$pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
            self::$pdo->exec("SET NAMES utf8mb4");

            $schemaFile = __DIR__ . '/schema.mysql.sql';
        } else {
            // SQLite fallback
            self::$driver = 'sqlite';
            if ($path === null) $path = __DIR__ . '/../data/admin.db';

            $dir = dirname($path);
            if (!is_dir($dir)) mkdir($dir, 0755, true);

            self::$pdo = new PDO("sqlite:$path", null, null, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
            self::$pdo->exec("PRAGMA foreign_keys = ON");
            $schemaFile = __DIR__ . '/schema.sql';
        }

        if (is_file($schemaFile)) {
            self::$pdo->exec(file_get_contents($schemaFile));
        }
    }

    public static function pdo(): PDO {
        if (self::$pdo === null) self::init();
        return self::$pdo;
    }

    public static function isMysql(): bool {
        return self::$driver === 'mysql';
    }

    public static function now(): string {
        return self::isMysql() ? 'UNIX_TIMESTAMP()' : "strftime('%s','now')";
    }

    public static function fetch(string $sql, array $args = []): ?array {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($args);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function fetchAll(string $sql, array $args = []): array {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($args);
        return $stmt->fetchAll();
    }

    public static function exec(string $sql, array $args = []): int {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($args);
        return $stmt->rowCount();
    }

    public static function insert(string $table, array $data): int {
        $keys = array_keys($data);
        $fields = implode(', ', $keys);
        $placeholders = implode(', ', array_fill(0, count($keys), '?'));
        $sql = "INSERT INTO $table ($fields) VALUES ($placeholders)";
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute(array_values($data));
        return (int) self::pdo()->lastInsertId();
    }

    public static function update(string $table, array $data, string $where, array $whereArgs = []): int {
        $sets = implode(', ', array_map(function($k) { return "$k = ?"; }, array_keys($data)));
        $sql = "UPDATE $table SET $sets WHERE $where";
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute(array_merge(array_values($data), $whereArgs));
        return $stmt->rowCount();
    }

    public static function delete(string $table, string $where, array $whereArgs = []): int {
        $sql = "DELETE FROM $table WHERE $where";
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($whereArgs);
        return $stmt->rowCount();
    }

    public static function count(string $sql, array $args = []): int {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($args);
        return (int) $stmt->fetchColumn();
    }

    public static function sum(string $sql, array $args = []): float {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($args);
        $val = $stmt->fetchColumn();
        return $val !== null ? (float) $val : 0.0;
    }
}
