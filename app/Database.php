<?php
/**
 * 数据库（PDO 单例 + 便捷方法）
 */
class Database
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }
        $cfg = Config::get('db', []);
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $cfg['host'] ?? '127.0.0.1',
            (int) ($cfg['port'] ?? 3306),
            $cfg['name'] ?? '',
            $cfg['charset'] ?? 'utf8mb4'
        );
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        // 该常量由 pdo_mysql 扩展提供，未安装时不能直接引用，否则会掩盖真实错误
        if (defined('PDO::MYSQL_ATTR_INIT_COMMAND')) {
            $options[PDO::MYSQL_ATTR_INIT_COMMAND] = 'SET NAMES ' . ($cfg['charset'] ?? 'utf8mb4');
        }

        self::$pdo = new PDO($dsn, $cfg['user'] ?? '', $cfg['pass'] ?? '', $options);
        return self::$pdo;
    }

    public static function exec(string $sql, array $params = []): int
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->rowCount();
    }

    public static function all(string $sql, array $params = []): array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        $row = $st->fetch();
        return $row === false ? null : $row;
    }

    public static function value(string $sql, array $params = [])
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        $v = $st->fetchColumn();
        return $v === false ? null : $v;
    }

    public static function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $sql  = 'INSERT INTO `' . $table . '` (`' . implode('`,`', $cols) . '`) VALUES ('
              . implode(',', array_fill(0, count($cols), '?')) . ')';
        self::exec($sql, array_values($data));
        return (int) self::pdo()->lastInsertId();
    }

    public static function update(string $table, array $data, string $where, array $params = []): int
    {
        $set = [];
        foreach (array_keys($data) as $c) {
            $set[] = "`$c`=?";
        }
        $sql = 'UPDATE `' . $table . '` SET ' . implode(',', $set) . ' WHERE ' . $where;
        return self::exec($sql, array_merge(array_values($data), $params));
    }

    public static function begin(): void
    {
        if (!self::pdo()->inTransaction()) {
            self::pdo()->beginTransaction();
        }
    }

    public static function commit(): void
    {
        if (self::pdo()->inTransaction()) {
            self::pdo()->commit();
        }
    }

    public static function rollback(): void
    {
        if (self::pdo()->inTransaction()) {
            self::pdo()->rollBack();
        }
    }

    public static function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}
