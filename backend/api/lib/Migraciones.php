<?php
// ============================================================
// Migraciones para actualizar una base YA INSTALADA a la version actual de schema.sql.
//
// Cada migracion revisa primero si ya esta aplicada, asi que correrlas varias veces es seguro.
// Solo agregan estructura (nunca borran datos). Una instalacion nueva ya trae todo en schema.sql.
//
// Para agregar una: cambia schema.sql Y agrega aqui su entrada con la misma estructura.
// ============================================================
class Migraciones
{
    /** [nombre => [descripcion, fn(PDO): bool ya aplicada, SQL[]]] */
    private static function lista(): array
    {
        return [
            '2026-10-06_aviso_pago' => [
                'Aviso de pago pendiente por tienda (empresas.aviso_pago)',
                fn(PDO $db) => self::existeColumna($db, 'empresas', 'aviso_pago'),
                ["ALTER TABLE empresas ADD COLUMN aviso_pago VARCHAR(500) DEFAULT NULL AFTER notas"],
            ],
            '2026-10-09_color_tienda' => [
                'Color de la tienda en la interfaz (empresas.color)',
                fn(PDO $db) => self::existeColumna($db, 'empresas', 'color'),
                ["ALTER TABLE empresas ADD COLUMN color CHAR(7) DEFAULT NULL AFTER aviso_pago"],
            ],
            '2026-10-08_tipo_cambio_cxp' => [
                'Tipo de cambio en pagos a proveedor (proveedor_movimientos.tipo_cambio)',
                fn(PDO $db) => self::existeColumna($db, 'proveedor_movimientos', 'tipo_cambio'),
                ["ALTER TABLE proveedor_movimientos ADD COLUMN tipo_cambio DECIMAL(12,4) DEFAULT NULL AFTER id_almacen"],
            ],
            '2026-10-08_devolucion_mixta' => [
                'Devolucion repartida entre deuda y monedero (devoluciones.destino_saldo = mixto)',
                fn(PDO $db) => self::columnaAdmite($db, 'devoluciones', 'destino_saldo', "'mixto'"),
                ["ALTER TABLE devoluciones MODIFY COLUMN destino_saldo ENUM('monedero','cxc','mixto') NOT NULL DEFAULT 'monedero'"],
            ],
        ];
    }

    /** Aplica las pendientes. Devuelve [[nombre, descripcion, 'aplicada'|'ya estaba']] */
    public static function aplicar(PDO $db): array
    {
        $res = [];
        foreach (self::lista() as $nombre => [$desc, $yaEsta, $sqls]) {
            if ($yaEsta($db)) { $res[] = [$nombre, $desc, 'ya estaba']; continue; }
            foreach ($sqls as $sql) $db->exec($sql);   // DDL: MySQL no la revierte en transaccion
            if (!$yaEsta($db)) throw new RuntimeException("La migracion $nombre no quedo aplicada");
            $res[] = [$nombre, $desc, 'aplicada'];
        }
        return $res;
    }

    /** Pendientes sin aplicar nada */
    public static function pendientes(PDO $db): array
    {
        $p = [];
        foreach (self::lista() as $nombre => [$desc, $yaEsta]) if (!$yaEsta($db)) $p[] = [$nombre, $desc];
        return $p;
    }

    /** El tipo de la columna (p. ej. un ENUM) contiene $texto */
    private static function columnaAdmite(PDO $db, string $tabla, string $columna, string $texto): bool
    {
        $st = $db->prepare('SELECT column_type FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
        $st->execute([$tabla, $columna]);
        return strpos((string) $st->fetchColumn(), $texto) !== false;
    }

    private static function existeColumna(PDO $db, string $tabla, string $columna): bool
    {
        $st = $db->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
        $st->execute([$tabla, $columna]);
        return (int) $st->fetchColumn() > 0;
    }
}
