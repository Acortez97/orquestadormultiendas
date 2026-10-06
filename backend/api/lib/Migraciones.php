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

    private static function existeColumna(PDO $db, string $tabla, string $columna): bool
    {
        $st = $db->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
        $st->execute([$tabla, $columna]);
        return (int) $st->fetchColumn() > 0;
    }
}
