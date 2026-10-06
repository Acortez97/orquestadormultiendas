<?php
// PDO wrapper sencillo (singleton)
class Db
{
    private static $pdo = null;

    public static function init(array $cfg): void
    {
        $port = $cfg['port'] ?? '3306';
        $dsn = "mysql:host={$cfg['host']};port={$port};dbname={$cfg['name']};charset={$cfg['charset']}";
        self::$pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }

    public static function pdo(): PDO
    {
        return self::$pdo;
    }

    /** Ejecuta y devuelve todas las filas */
    public static function all(string $sql, array $params = []): array
    {
        $st = self::$pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    /** Devuelve una sola fila (o null) */
    public static function one(string $sql, array $params = [])
    {
        $st = self::$pdo->prepare($sql);
        $st->execute($params);
        $row = $st->fetch();
        return $row === false ? null : $row;
    }

    /** Ejecuta INSERT/UPDATE/DELETE, devuelve filas afectadas */
    public static function run(string $sql, array $params = []): int
    {
        $st = self::$pdo->prepare($sql);
        $st->execute($params);
        return $st->rowCount();
    }

    /** INSERT y devuelve el id nuevo */
    public static function insert(string $sql, array $params = []): int
    {
        self::run($sql, $params);
        return (int) self::$pdo->lastInsertId();
    }

    public static function begin(): void  { self::$pdo->beginTransaction(); }
    public static function commit(): void { self::$pdo->commit(); }
    public static function rollback(): void { if (self::$pdo->inTransaction()) self::$pdo->rollBack(); }
}
