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
        // Misma hora en PHP y MySQL (NOW/CURDATE vs date()). El desfase lo calcula PHP porque el
        // hosting puede no tener cargadas las tablas de zonas horarias de MySQL.
        date_default_timezone_set($cfg['zona_horaria'] ?? 'America/Mexico_City');
        self::$pdo->exec("SET time_zone = '" . date('P') . "'");
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
    public static function rollbackSiAbierta(): void { if (self::$pdo && self::$pdo->inTransaction()) self::$pdo->rollBack(); }

    /**
     * Siguiente folio de la tienda para (tipo, serie). Debe llamarse DENTRO de una transaccion:
     * bloquea la fila del consecutivo (SELECT ... FOR UPDATE) para que dos ventas simultaneas
     * no obtengan el mismo numero. Devuelve p.ej. "A-000123".
     */
    public static function folio(int $idEmpresa, string $tipo, string $serie, int $digitos = 6): string
    {
        if (!self::$pdo->inTransaction()) throw new LogicException('Db::folio() requiere una transaccion abierta');
        self::run('INSERT IGNORE INTO folio_series (id_empresa, tipo, serie, ultimo) VALUES (?,?,?,0)', [$idEmpresa, $tipo, $serie]);
        $n = (int) self::one('SELECT ultimo FROM folio_series WHERE id_empresa = ? AND tipo = ? AND serie = ? FOR UPDATE',
            [$idEmpresa, $tipo, $serie])['ultimo'] + 1;
        self::run('UPDATE folio_series SET ultimo = ? WHERE id_empresa = ? AND tipo = ? AND serie = ?', [$n, $idEmpresa, $tipo, $serie]);
        return $serie . '-' . str_pad((string) $n, $digitos, '0', STR_PAD_LEFT);
    }
}
