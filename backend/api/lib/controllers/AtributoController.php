<?php
class AtributoController
{
    private static function fmt(array $a, bool $conValores = true): array
    {
        $o = [
            '_id'        => (string) $a['id'],
            'nombre'     => $a['nombre'],
            'tipo_valor' => $a['tipo_valor'],
            'orden'      => (int) $a['orden'],
            'is_active'  => $a['is_active'],
        ];
        if ($conValores) $o['valores'] = self::valoresDe((int) $a['id']);
        return $o;
    }

    private static function fmtValor(array $v): array
    {
        return [
            '_id'         => (string) $v['id'],
            'id_atributo' => (string) $v['id_atributo'],
            'nombre'      => $v['nombre'],
            'extra'       => $v['extra'],
            'orden'       => (int) $v['orden'],
            'is_active'   => $v['is_active'],
        ];
    }

    public static function valoresDe(int $idAtr): array
    {
        return array_map([self::class, 'fmtValor'],
            Db::all('SELECT * FROM atributo_valores WHERE id_atributo=? AND is_active=\'Si\' ORDER BY orden, nombre', [$idAtr]));
    }

    // ---- Atributos ----
    public static function listar(array $p, array $ctx): void
    {
        $emp = (int) $ctx['user']['id_empresa'];
        $rows = Db::all('SELECT * FROM atributos WHERE id_empresa=? AND is_active=\'Si\' ORDER BY orden, nombre', [$emp]);
        Http::ok(array_map(fn($a) => self::fmt($a, true), $rows));
    }

    public static function crear(array $p, array $ctx): void
    {
        $b = Http::body();
        if (trim($b['nombre'] ?? '') === '') throw new ApiError('El nombre es obligatorio', 400, 'VALIDATION');
        $tipo = in_array($b['tipo_valor'] ?? 'texto', ['texto', 'color', 'numero'], true) ? $b['tipo_valor'] : 'texto';
        $id = Db::insert('INSERT INTO atributos (id_empresa,nombre,tipo_valor,orden) VALUES (?,?,?,?)',
            [(int) $ctx['user']['id_empresa'], trim($b['nombre']), $tipo, (int) ($b['orden'] ?? 0)]);
        Http::created(self::fmt(Db::one('SELECT * FROM atributos WHERE id=?', [$id])), 'Atributo');
    }

    public static function actualizar(array $p, array $ctx): void
    {
        $emp = (int) $ctx['user']['id_empresa']; $id = (int) $p['id'];
        $a = Db::one('SELECT * FROM atributos WHERE id=? AND id_empresa=?', [$id, $emp]);
        if (!$a) throw new ApiError('Atributo no encontrado', 404, 'NOT_FOUND');
        $b = Http::body();
        Db::run('UPDATE atributos SET nombre=?, tipo_valor=?, orden=?, is_active=? WHERE id=?', [
            array_key_exists('nombre', $b) ? trim($b['nombre']) : $a['nombre'],
            array_key_exists('tipo_valor', $b) && in_array($b['tipo_valor'], ['texto', 'color', 'numero'], true) ? $b['tipo_valor'] : $a['tipo_valor'],
            array_key_exists('orden', $b) ? (int) $b['orden'] : $a['orden'],
            array_key_exists('is_active', $b) ? (($b['is_active'] === 'No') ? 'No' : 'Si') : $a['is_active'],
            $id,
        ]);
        Http::updated(self::fmt(Db::one('SELECT * FROM atributos WHERE id=?', [$id])), 'Atributo');
    }

    public static function eliminar(array $p, array $ctx): void
    {
        Db::run('UPDATE atributos SET is_active=\'No\' WHERE id=? AND id_empresa=?',
            [(int) $p['id'], (int) $ctx['user']['id_empresa']]);
        Http::deleted('Atributo');
    }

    // ---- Valores de un atributo ----
    public static function listarValores(array $p, array $ctx): void
    {
        $emp = (int) $ctx['user']['id_empresa']; $idAtr = (int) $p['id'];
        if (!Db::one('SELECT id FROM atributos WHERE id=? AND id_empresa=?', [$idAtr, $emp]))
            throw new ApiError('Atributo no encontrado', 404, 'NOT_FOUND');
        Http::ok(self::valoresDe($idAtr));
    }

    public static function crearValor(array $p, array $ctx): void
    {
        $emp = (int) $ctx['user']['id_empresa']; $idAtr = (int) $p['id'];
        if (!Db::one('SELECT id FROM atributos WHERE id=? AND id_empresa=?', [$idAtr, $emp]))
            throw new ApiError('Atributo no encontrado', 404, 'NOT_FOUND');
        $b = Http::body();
        if (trim($b['nombre'] ?? '') === '') throw new ApiError('El nombre es obligatorio', 400, 'VALIDATION');
        $id = Db::insert('INSERT INTO atributo_valores (id_empresa,id_atributo,nombre,extra,orden) VALUES (?,?,?,?,?)',
            [$emp, $idAtr, trim($b['nombre']), $b['extra'] ?? null, (int) ($b['orden'] ?? 0)]);
        Http::created(self::fmtValor(Db::one('SELECT * FROM atributo_valores WHERE id=?', [$id])), 'Valor');
    }

    public static function actualizarValor(array $p, array $ctx): void
    {
        $emp = (int) $ctx['user']['id_empresa']; $id = (int) $p['id'];
        $v = Db::one('SELECT * FROM atributo_valores WHERE id=? AND id_empresa=?', [$id, $emp]);
        if (!$v) throw new ApiError('Valor no encontrado', 404, 'NOT_FOUND');
        $b = Http::body();
        Db::run('UPDATE atributo_valores SET nombre=?, extra=?, orden=?, is_active=? WHERE id=?', [
            array_key_exists('nombre', $b) ? trim($b['nombre']) : $v['nombre'],
            array_key_exists('extra', $b) ? $b['extra'] : $v['extra'],
            array_key_exists('orden', $b) ? (int) $b['orden'] : $v['orden'],
            array_key_exists('is_active', $b) ? (($b['is_active'] === 'No') ? 'No' : 'Si') : $v['is_active'],
            $id,
        ]);
        Http::updated(self::fmtValor(Db::one('SELECT * FROM atributo_valores WHERE id=?', [$id])), 'Valor');
    }

    public static function eliminarValor(array $p, array $ctx): void
    {
        Db::run('UPDATE atributo_valores SET is_active=\'No\' WHERE id=? AND id_empresa=?',
            [(int) $p['id'], (int) $ctx['user']['id_empresa']]);
        Http::deleted('Valor');
    }
}
