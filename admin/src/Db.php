<?php
namespace App;

use PDO;
use PDOException;
use Exception;

/**
 * 数据库封装（PDO 单例）
 */
class Db
{
    private static ?PDO $pdo = null;
    private static ?array $config = null;

    public static function init(array $config): void
    {
        self::$config = $config;
    }

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            if (self::$config === null) {
                $config = require __DIR__ . '/../config.php';
                self::$config = $config['db'];
            }
            $c = self::$config;
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $c['host'], $c['port'], $c['database'], $c['charset']
            );
            try {
                self::$pdo = new PDO($dsn, $c['username'], $c['password'], [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]);
            } catch (PDOException $e) {
                throw new Exception('数据库连接失败：' . $e->getMessage());
            }
        }
        return self::$pdo;
    }

    public static function query(string $sql, array $params = []): \PDOStatement
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public static function fetch(string $sql, array $params = []): ?array
    {
        $row = self::query($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public static function fetchAll(string $sql, array $params = []): array
    {
        return self::query($sql, $params)->fetchAll();
    }

    public static function execute(string $sql, array $params = []): int
    {
        return self::query($sql, $params)->rowCount();
    }

    public static function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $placeholders = array_map(fn($c) => ':' . $c, $cols);
        $sql = sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s)',
            $table,
            implode(',', array_map(fn($c) => "`$c`", $cols)),
            implode(',', $placeholders)
        );
        self::query($sql, $data);
        return (int)self::pdo()->lastInsertId();
    }

    public static function update(string $table, array $data, string $where, array $params = []): int
    {
        $set = [];
        foreach ($data as $k => $v) {
            $set[] = "`$k` = :$k";
        }
        $sql = sprintf('UPDATE `%s` SET %s WHERE %s', $table, implode(',', $set), $where);
        return self::query($sql, array_merge($data, $params))->rowCount();
    }

    public static function begin(): void
    {
        self::pdo()->beginTransaction();
    }

    public static function commit(): void
    {
        self::pdo()->commit();
    }

    public static function rollback(): void
    {
        if (self::pdo()->inTransaction()) {
            self::pdo()->rollBack();
        }
    }
}