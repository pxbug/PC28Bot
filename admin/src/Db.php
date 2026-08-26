<?php
/**
 * Database wrapper - SQLite (single-file, zero-dependency)
 */
class DB {
    private static ?PDO $pdo = null;

    public static function init(string $path): void {
        if (self::$pdo !== null) return;

        $dir = dirname($path);
        if (!is_dir($dir)) mkdir($dir, 0755, true);

        self::$pdo = new PDO("sqlite:$path", null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        self::$pdo->exec("PRAGMA foreign_keys = ON");

        // Auto-create tables if not exist
        $schemaFile = __DIR__ . '/schema.sql';
        if (is_file($schemaFile)) {
            self::$pdo->exec(file_get_contents($schemaFile));
        }
    }

    public static function pdo(): PDO {
        if (self::$pdo === null) {
            self::init(dirname(__DIR__) . '/data/admin.db');
        }
        return self::$pdo;
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
        $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($data)));
        $sql = "UPDATE $table SET $sets WHERE $where";
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute([...array_values($data), ...$whereArgs]);
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
