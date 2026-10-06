<?php
class ClienteController
{
    public static function fmt(array $r): array
    {
        return [
            '_id'           => (string) $r['id'],
            'nombre'        => $r['nombre'],
            'telefono'      => $r['telefono'],
            'rfc'           => $r['rfc'],
            'razon_social'  => $r['razon_social'],
            'contacto'      => $r['contacto']  ? json_decode($r['contacto'], true)  : null,
            'domicilio'     => $r['domicilio'] ? json_decode($r['domicilio'], true) : null,
            'lista_precios' => (int) $r['lista_precios'],
            'forma_pago'    => $r['forma_pago'],
            'plazo_dias'    => (int) $r['plazo_dias'],
            'limite_credito'=> (float) $r['limite_credito'],
            'es_publico_general' => (bool) $r['es_publico_general'],
            'id_tienda'     => $r['id_tienda']   !== null ? (string) $r['id_tienda']   : null,
            'id_vendedor'   => $r['id_vendedor'] !== null ? (string) $r['id_vendedor'] : null,
            'saldo_credito' => (float) $r['saldo_credito'],
            'saldo_favor'   => (float) $r['saldo_favor'],
            'notas'         => $r['notas'],
            'is_active'     => $r['is_active'],
            'createdAt'     => $r['created_at'] ?? null,
            'updatedAt'     => $r['updated_at'] ?? null,
        ];
    }

    public static function listar(array $p, array $ctx): void
    {
        $emp = (int) $ctx['user']['id_empresa'];
        $where = 'id_empresa=?'; $args = [$emp];
        $estado = $_GET['is_active'] ?? 'Si';
        if ($estado !== 'todos' && $estado !== '') { $where .= ' AND is_active=?'; $args[] = ($estado === 'No' ? 'No' : 'Si'); }
        if (!empty($_GET['search'])) { $where .= ' AND (nombre LIKE ? OR telefono LIKE ? OR rfc LIKE ?)'; $s = '%' . $_GET['search'] . '%'; array_push($args, $s, $s, $s); }
        $rows = Db::all("SELECT * FROM clientes WHERE $where ORDER BY nombre LIMIT 1000", $args);
        Http::ok(array_map([self::class, 'fmt'], $rows));
    }

    public static function obtener(array $p, array $ctx): void
    {
        $r = Db::one('SELECT * FROM clientes WHERE id=? AND id_empresa=?', [(int) $p['id'], (int) $ctx['user']['id_empresa']]);
        if (!$r) throw new ApiError('Cliente no encontrado', 404, 'NOT_FOUND');
        Http::ok(self::fmt($r));
    }

    public static function crear(array $p, array $ctx): void
    {
        $b = Http::body();
        if (trim($b['nombre'] ?? '') === '') throw new ApiError('El nombre es obligatorio', 400, 'VALIDATION');
        $emp = (int) $ctx['user']['id_empresa'];
        $lista = max(1, min(5, (int) ($b['lista_precios'] ?? 1)));
        if ($lista >= 4 && !ConfigController::validarPin($emp, $b['pin'] ?? null))
            throw new ApiError('PIN de autorizacion invalido para asignar Lista ' . $lista, 403, 'PIN_INVALIDO');
        $id = Db::insert(
            'INSERT INTO clientes (id_empresa,nombre,telefono,rfc,razon_social,contacto,domicilio,lista_precios,
              forma_pago,plazo_dias,limite_credito,es_publico_general,id_tienda,id_vendedor,saldo_credito,saldo_favor,notas)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [
                $emp, trim($b['nombre']), $b['telefono'] ?? null,
                isset($b['rfc']) ? strtoupper($b['rfc']) : null, $b['razon_social'] ?? null,
                isset($b['contacto']) ? json_encode($b['contacto'], JSON_UNESCAPED_UNICODE) : null,
                isset($b['domicilio']) ? json_encode($b['domicilio'], JSON_UNESCAPED_UNICODE) : null,
                max(1, min(5, (int) ($b['lista_precios'] ?? 1))),
                ($b['forma_pago'] ?? 'Contado') === 'Credito' ? 'Credito' : 'Contado',
                (int) ($b['plazo_dias'] ?? 0), num($b['limite_credito'] ?? 0),
                !empty($b['es_publico_general']) ? 1 : 0,
                id_or_null($b['id_tienda'] ?? $ctx['user']['id_tienda'] ?? null),
                id_or_null($b['id_vendedor'] ?? null),
                num($b['saldo_credito'] ?? 0), num($b['saldo_favor'] ?? 0), $b['notas'] ?? null,
            ]
        );
        Http::created(self::fmt(Db::one('SELECT * FROM clientes WHERE id=?', [$id])), 'Cliente');
    }

    public static function actualizar(array $p, array $ctx): void
    {
        $emp = (int) $ctx['user']['id_empresa']; $id = (int) $p['id'];
        $r = Db::one('SELECT * FROM clientes WHERE id=? AND id_empresa=?', [$id, $emp]);
        if (!$r) throw new ApiError('Cliente no encontrado', 404, 'NOT_FOUND');
        $b = Http::body();
        $nuevaLista = array_key_exists('lista_precios', $b) ? max(1, min(5, (int) $b['lista_precios'])) : (int) $r['lista_precios'];
        if ($nuevaLista >= 4 && $nuevaLista !== (int) $r['lista_precios'] && !ConfigController::validarPin($emp, $b['pin'] ?? null))
            throw new ApiError('PIN de autorizacion invalido para asignar Lista ' . $nuevaLista, 403, 'PIN_INVALIDO');
        Db::run(
            'UPDATE clientes SET nombre=?, telefono=?, rfc=?, razon_social=?, contacto=?, domicilio=?, lista_precios=?,
              forma_pago=?, plazo_dias=?, limite_credito=?, es_publico_general=?, id_tienda=?, id_vendedor=?, notas=?, is_active=?
             WHERE id=?',
            [
                array_key_exists('nombre', $b) ? trim($b['nombre']) : $r['nombre'],
                array_key_exists('telefono', $b) ? $b['telefono'] : $r['telefono'],
                array_key_exists('rfc', $b) ? ($b['rfc'] !== null ? strtoupper($b['rfc']) : null) : $r['rfc'],
                array_key_exists('razon_social', $b) ? $b['razon_social'] : $r['razon_social'],
                array_key_exists('contacto', $b) ? json_encode($b['contacto'], JSON_UNESCAPED_UNICODE) : $r['contacto'],
                array_key_exists('domicilio', $b) ? json_encode($b['domicilio'], JSON_UNESCAPED_UNICODE) : $r['domicilio'],
                array_key_exists('lista_precios', $b) ? max(1, min(5, (int) $b['lista_precios'])) : $r['lista_precios'],
                array_key_exists('forma_pago', $b) ? (($b['forma_pago'] === 'Credito') ? 'Credito' : 'Contado') : $r['forma_pago'],
                array_key_exists('plazo_dias', $b) ? (int) $b['plazo_dias'] : $r['plazo_dias'],
                array_key_exists('limite_credito', $b) ? num($b['limite_credito']) : $r['limite_credito'],
                array_key_exists('es_publico_general', $b) ? (!empty($b['es_publico_general']) ? 1 : 0) : $r['es_publico_general'],
                array_key_exists('id_tienda', $b) ? id_or_null($b['id_tienda']) : $r['id_tienda'],
                array_key_exists('id_vendedor', $b) ? id_or_null($b['id_vendedor']) : $r['id_vendedor'],
                array_key_exists('notas', $b) ? $b['notas'] : $r['notas'],
                array_key_exists('is_active', $b) ? (($b['is_active'] === 'No') ? 'No' : 'Si') : $r['is_active'],
                $id,
            ]
        );
        Http::updated(self::fmt(Db::one('SELECT * FROM clientes WHERE id=?', [$id])), 'Cliente');
    }

    public static function autorizarCredito(array $p, array $ctx): void
    {
        $emp = (int) $ctx['user']['id_empresa']; $id = (int) $p['id'];
        $r = Db::one('SELECT * FROM clientes WHERE id=? AND id_empresa=?', [$id, $emp]);
        if (!$r) throw new ApiError('Cliente no encontrado', 404, 'NOT_FOUND');
        $b = Http::body();
        Db::run(
            'UPDATE clientes SET forma_pago=\'Credito\', limite_credito=?, plazo_dias=?, credito_autorizado_por=? WHERE id=?',
            [num($b['limite_credito'] ?? 0), (int) ($b['plazo_dias'] ?? 0), (int) $ctx['user']['id'], $id]
        );
        Http::ok(self::fmt(Db::one('SELECT * FROM clientes WHERE id=?', [$id])), 'Credito autorizado');
    }

    public static function eliminar(array $p, array $ctx): void
    {
        Db::run('UPDATE clientes SET is_active=\'No\' WHERE id=? AND id_empresa=?',
            [(int) $p['id'], (int) $ctx['user']['id_empresa']]);
        Http::deleted('Cliente');
    }
}
