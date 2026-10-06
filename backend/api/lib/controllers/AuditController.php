<?php
// Bitacora de la tienda (solo sus eventos). Las acciones del superadmin en soporte aparecen como "Soporte".
class AuditController
{
    public static function listar(array $p, array $ctx): void
    {
        $where = 'id_empresa = ?'; $args = [Tenant::id()];
        $accion = $_GET['operacion'] ?? $_GET['accion'] ?? '';
        $entidad = $_GET['modulo'] ?? $_GET['entidad'] ?? '';
        $desde = $_GET['fecha_desde'] ?? $_GET['desde'] ?? '';
        $hasta = $_GET['fecha_hasta'] ?? $_GET['hasta'] ?? '';
        if ($accion !== '')  { $where .= ' AND accion = ?';  $args[] = $accion; }
        if ($entidad !== '') { $where .= ' AND entidad LIKE ?'; $args[] = '%' . $entidad . '%'; }
        if ($desde !== '')   { $where .= ' AND created_at >= ?'; $args[] = substr($desde, 0, 10); }
        if ($hasta !== '')   { $where .= ' AND created_at <= ?'; $args[] = substr($hasta, 0, 10) . ' 23:59:59'; }
        $limit = min(1000, max(1, (int) ($_GET['limit'] ?? 300)));
        $rows = Db::all("SELECT * FROM audit_log WHERE $where ORDER BY created_at DESC, id DESC LIMIT $limit", $args);
        Http::ok(array_map(fn($r) => [
            '_id'            => (int) $r['id'],
            'fecha'          => $r['created_at'],
            'createdAt'      => $r['created_at'],
            'usuario'        => $r['usuario'],
            'nombre_usuario' => $r['usuario'],
            'id_usuario'     => $r['id_usuario'] !== null ? (int) $r['id_usuario'] : null,
            'soporte'        => $r['act_as'] !== null,
            'accion'         => $r['accion'],
            'operacion'      => $r['accion'],
            'entidad'        => $r['entidad'],
            'modulo'         => $r['entidad'],
            'descripcion'    => $r['descripcion'],
            'ip'             => $r['ip'],
        ], $rows));
    }
}
