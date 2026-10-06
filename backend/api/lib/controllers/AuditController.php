<?php
class AuditController
{
    public static function listar(array $p, array $ctx): void
    {
        $emp = Tenant::id();
        $where = 'id_empresa=?'; $args = [$emp];
        if (!empty($_GET['accion']))  { $where .= ' AND accion=?';  $args[] = $_GET['accion']; }
        if (!empty($_GET['entidad'])) { $where .= ' AND entidad=?'; $args[] = $_GET['entidad']; }
        if (!empty($_GET['desde']))   { $where .= ' AND created_at>=?'; $args[] = $_GET['desde']; }
        if (!empty($_GET['hasta']))   { $where .= ' AND created_at<=?'; $args[] = $_GET['hasta'] . ' 23:59:59'; }
        $limit = min(1000, max(1, (int) ($_GET['limit'] ?? 300)));
        $rows = Db::all("SELECT * FROM audit_log WHERE $where ORDER BY created_at DESC, id DESC LIMIT $limit", $args);
        Http::ok(array_map(fn($r) => [
            '_id'         => (int) $r['id'],
            'fecha'       => $r['created_at'],
            'createdAt'   => $r['created_at'],
            'usuario'     => $r['usuario'],
            'id_usuario'  => $r['id_usuario'] !== null ? (int) $r['id_usuario'] : null,
            'soporte'     => $r['act_as'] !== null,
            'accion'      => $r['accion'],
            'entidad'     => $r['entidad'],
            'id_entidad'  => $r['id_entidad'],
            'descripcion' => $r['descripcion'],
            'ip'          => $r['ip'],
        ], $rows));
    }
}
