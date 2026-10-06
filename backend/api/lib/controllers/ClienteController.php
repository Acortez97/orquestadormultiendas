<?php
// Clientes de la tienda. Los saldos (credito / a favor) solo cambian via Ledger (movimientos).
class ClienteController
{
    public static function fmt(array $r): array
    {
        return [
            '_id'           => (int) $r['id'],
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
            'id_tienda'     => $r['id_almacen']  !== null ? (int) $r['id_almacen']  : null,
            'id_vendedor'   => $r['id_vendedor'] !== null ? (int) $r['id_vendedor'] : null,
            'saldo_credito' => (float) $r['saldo_credito'],
            'saldo_favor'   => (float) $r['saldo_favor'],
            'notas'         => $r['notas'],
            'is_active'     => $r['is_active'],
            'createdAt'     => $r['created_at'] ?? null,
            'updatedAt'     => $r['updated_at'] ?? null,
        ];
    }

    public static function cliente(int $id): array
    {
        $r = Db::one('SELECT * FROM clientes WHERE id = ? AND id_empresa = ?', [$id, Tenant::id()]);
        if (!$r) throw new ApiError('Cliente no encontrado', 404, 'NOT_FOUND');
        return $r;
    }

    public static function listar(array $p, array $ctx): void
    {
        $where = 'id_empresa = ?'; $args = [Tenant::id()];
        $estado = $_GET['is_active'] ?? 'Si';
        if ($estado !== 'todos' && $estado !== '') { $where .= ' AND is_active = ?'; $args[] = ($estado === 'No' ? 'No' : 'Si'); }
        if (!empty($_GET['search'])) { $where .= ' AND (nombre LIKE ? OR telefono LIKE ? OR rfc LIKE ?)'; $s = '%' . $_GET['search'] . '%'; array_push($args, $s, $s, $s); }
        Http::ok(array_map([self::class, 'fmt'], Db::all("SELECT * FROM clientes WHERE $where ORDER BY nombre LIMIT 1000", $args)));
    }

    public static function obtener(array $p, array $ctx): void
    {
        Http::ok(self::fmt(self::cliente((int) $p['id'])));
    }

    private static function lista($v): int
    {
        $l = (int) $v;
        if ($l < 1 || $l > 5) throw new ApiError('La lista de precios debe ser de 1 a 5', 400, 'VALIDATION');
        return $l;
    }

    public static function crear(array $p, array $ctx): void
    {
        $b = Http::body();
        $emp = Tenant::id();
        $nombre = trim((string) ($b['nombre'] ?? ''));
        if ($nombre === '') throw new ApiError('El nombre es obligatorio', 400, 'VALIDATION');
        $lista = self::lista($b['lista_precios'] ?? 1);
        if ($lista >= 4 && !ConfigController::validarPin($emp, $b['pin'] ?? null))
            throw new ApiError('PIN de autorizacion invalido para asignar Lista ' . $lista, 403, 'PIN_INVALIDO');
        $saldoCredito = num($b['saldo_credito'] ?? 0);
        $saldoFavor = num($b['saldo_favor'] ?? 0);
        if ($saldoCredito < 0 || $saldoFavor < 0) throw new ApiError('Los saldos iniciales no pueden ser negativos', 400, 'VALIDATION');

        Db::begin();
        $id = Db::insert(
            'INSERT INTO clientes (id_empresa, nombre, telefono, rfc, razon_social, contacto, domicilio, lista_precios,
               forma_pago, plazo_dias, limite_credito, es_publico_general, id_almacen, id_vendedor, notas)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [$emp, $nombre, $b['telefono'] ?? null, isset($b['rfc']) ? strtoupper((string) $b['rfc']) : null, $b['razon_social'] ?? null,
             isset($b['contacto']) ? json_encode($b['contacto'], JSON_UNESCAPED_UNICODE) : null,
             isset($b['domicilio']) ? json_encode($b['domicilio'], JSON_UNESCAPED_UNICODE) : null,
             $lista, ($b['forma_pago'] ?? 'Contado') === 'Credito' ? 'Credito' : 'Contado',
             max(0, (int) ($b['plazo_dias'] ?? 0)), max(0, num($b['limite_credito'] ?? 0)),
             !empty($b['es_publico_general']) ? 1 : 0,
             Tenant::owns('almacenes', $b['id_tienda'] ?? $ctx['user']['id_tienda'] ?? null, 'Almacen'),
             Tenant::owns('empleados', $b['id_vendedor'] ?? null, 'Vendedor'),
             $b['notas'] ?? null]);
        // Saldos iniciales: siempre como movimientos (los saldos son acumulados de sus libros)
        if ($saldoCredito > 0) Ledger::clienteMov($emp, $id, 'saldo_inicial', 'Saldo inicial', $saldoCredito, 'cargo');
        if ($saldoFavor > 0) Ledger::monederoMov($emp, $id, $saldoFavor, 'ajuste', 'Saldo inicial');
        Db::commit();
        Http::created(self::fmt(self::cliente($id)), 'Cliente');
    }

    public static function actualizar(array $p, array $ctx): void
    {
        $r = self::cliente((int) $p['id']);
        $b = Http::body();
        $nuevaLista = array_key_exists('lista_precios', $b) ? self::lista($b['lista_precios']) : (int) $r['lista_precios'];
        if ($nuevaLista >= 4 && $nuevaLista !== (int) $r['lista_precios'] && !ConfigController::validarPin(Tenant::id(), $b['pin'] ?? null))
            throw new ApiError('PIN de autorizacion invalido para asignar Lista ' . $nuevaLista, 403, 'PIN_INVALIDO');
        Db::run(
            'UPDATE clientes SET nombre = ?, telefono = ?, rfc = ?, razon_social = ?, contacto = ?, domicilio = ?, lista_precios = ?,
               forma_pago = ?, plazo_dias = ?, limite_credito = ?, es_publico_general = ?, id_almacen = ?, id_vendedor = ?, notas = ?, is_active = ?
             WHERE id = ? AND id_empresa = ?',
            [array_key_exists('nombre', $b) && trim((string) $b['nombre']) !== '' ? trim((string) $b['nombre']) : $r['nombre'],
             array_key_exists('telefono', $b) ? $b['telefono'] : $r['telefono'],
             array_key_exists('rfc', $b) ? ($b['rfc'] !== null ? strtoupper((string) $b['rfc']) : null) : $r['rfc'],
             array_key_exists('razon_social', $b) ? $b['razon_social'] : $r['razon_social'],
             array_key_exists('contacto', $b) ? json_encode($b['contacto'], JSON_UNESCAPED_UNICODE) : $r['contacto'],
             array_key_exists('domicilio', $b) ? json_encode($b['domicilio'], JSON_UNESCAPED_UNICODE) : $r['domicilio'],
             $nuevaLista,
             array_key_exists('forma_pago', $b) ? (($b['forma_pago'] === 'Credito') ? 'Credito' : 'Contado') : $r['forma_pago'],
             array_key_exists('plazo_dias', $b) ? max(0, (int) $b['plazo_dias']) : $r['plazo_dias'],
             array_key_exists('limite_credito', $b) ? max(0, num($b['limite_credito'])) : $r['limite_credito'],
             array_key_exists('es_publico_general', $b) ? (!empty($b['es_publico_general']) ? 1 : 0) : $r['es_publico_general'],
             array_key_exists('id_tienda', $b) ? Tenant::owns('almacenes', $b['id_tienda'], 'Almacen') : $r['id_almacen'],
             array_key_exists('id_vendedor', $b) ? Tenant::owns('empleados', $b['id_vendedor'], 'Vendedor') : $r['id_vendedor'],
             array_key_exists('notas', $b) ? $b['notas'] : $r['notas'],
             array_key_exists('is_active', $b) ? (($b['is_active'] === 'No') ? 'No' : 'Si') : $r['is_active'],
             $r['id'], Tenant::id()]);
        Http::updated(self::fmt(self::cliente((int) $r['id'])), 'Cliente');
    }

    public static function autorizarCredito(array $p, array $ctx): void
    {
        $r = self::cliente((int) $p['id']);
        $b = Http::body();
        Db::run("UPDATE clientes SET forma_pago = 'Credito', limite_credito = ?, plazo_dias = ?, credito_autorizado_por = ? WHERE id = ? AND id_empresa = ?",
            [max(0, num($b['limite_credito'] ?? 0)), max(0, (int) ($b['plazo_dias'] ?? 0)), $ctx['user']['id'], $r['id'], Tenant::id()]);
        Ledger::audit($ctx, 'autorizar_credito', 'cliente', $r['id'], 'Limite ' . num($b['limite_credito'] ?? 0));
        Http::ok(self::fmt(self::cliente((int) $r['id'])), 'Credito autorizado');
    }

    public static function eliminar(array $p, array $ctx): void
    {
        $r = self::cliente((int) $p['id']);
        Db::run("UPDATE clientes SET is_active = 'No' WHERE id = ? AND id_empresa = ?", [$r['id'], Tenant::id()]);
        Http::deleted('Cliente');
    }
}
