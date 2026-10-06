<?php
// Atributos de variante (Color, Talla, Numero...) y sus valores. Todo por tienda.
class AtributoController
{
    const TIPOS = ['texto', 'color', 'numero'];

    private static function fmt(array $a, bool $conValores = true): array
    {
        $o = [
            '_id'        => (int) $a['id'],
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
            '_id'         => (int) $v['id'],
            'id_atributo' => (int) $v['id_atributo'],
            'nombre'      => $v['nombre'],
            'extra'       => $v['extra'],
            'orden'       => (int) $v['orden'],
            'is_active'   => $v['is_active'],
        ];
    }

    public static function valoresDe(int $idAtr): array
    {
        return array_map([self::class, 'fmtValor'], Db::all(
            "SELECT * FROM atributo_valores WHERE id_empresa = ? AND id_atributo = ? AND is_active = 'Si' ORDER BY orden, nombre",
            [Tenant::id(), $idAtr]));
    }

    private static function atributo(int $id): array
    {
        $a = Db::one('SELECT * FROM atributos WHERE id = ? AND id_empresa = ?', [$id, Tenant::id()]);
        if (!$a) throw new ApiError('Atributo no encontrado', 404, 'NOT_FOUND');
        return $a;
    }

    private static function valor(int $id): array
    {
        $v = Db::one('SELECT * FROM atributo_valores WHERE id = ? AND id_empresa = ?', [$id, Tenant::id()]);
        if (!$v) throw new ApiError('Valor no encontrado', 404, 'NOT_FOUND');
        return $v;
    }

    // ---- Atributos ----
    public static function listar(array $p, array $ctx): void
    {
        $rows = Db::all("SELECT * FROM atributos WHERE id_empresa = ? AND is_active = 'Si' ORDER BY orden, nombre", [Tenant::id()]);
        Http::ok(array_map(fn($a) => self::fmt($a, true), $rows));
    }

    public static function crear(array $p, array $ctx): void
    {
        $b = Http::body();
        $nombre = trim((string) ($b['nombre'] ?? ''));
        if ($nombre === '') throw new ApiError('El nombre es obligatorio', 400, 'VALIDATION');
        $tipo = in_array($b['tipo_valor'] ?? 'texto', self::TIPOS, true) ? $b['tipo_valor'] : 'texto';
        $id = Db::insert('INSERT INTO atributos (id_empresa, nombre, tipo_valor, orden) VALUES (?,?,?,?)',
            [Tenant::id(), $nombre, $tipo, (int) ($b['orden'] ?? 0)]);
        Http::created(self::fmt(self::atributo($id)), 'Atributo');
    }

    public static function actualizar(array $p, array $ctx): void
    {
        $a = self::atributo((int) $p['id']);
        $b = Http::body();
        Db::run('UPDATE atributos SET nombre = ?, tipo_valor = ?, orden = ?, is_active = ? WHERE id = ? AND id_empresa = ?', [
            array_key_exists('nombre', $b) && trim((string) $b['nombre']) !== '' ? trim((string) $b['nombre']) : $a['nombre'],
            array_key_exists('tipo_valor', $b) && in_array($b['tipo_valor'], self::TIPOS, true) ? $b['tipo_valor'] : $a['tipo_valor'],
            array_key_exists('orden', $b) ? (int) $b['orden'] : $a['orden'],
            array_key_exists('is_active', $b) ? (($b['is_active'] === 'No') ? 'No' : 'Si') : $a['is_active'],
            $a['id'], Tenant::id(),
        ]);
        Http::updated(self::fmt(self::atributo((int) $a['id'])), 'Atributo');
    }

    public static function eliminar(array $p, array $ctx): void
    {
        $a = self::atributo((int) $p['id']);
        Db::run("UPDATE atributos SET is_active = 'No' WHERE id = ? AND id_empresa = ?", [$a['id'], Tenant::id()]);
        Http::deleted('Atributo');
    }

    // ---- Valores de un atributo ----
    public static function listarValores(array $p, array $ctx): void
    {
        $a = self::atributo((int) $p['id']);
        Http::ok(self::valoresDe((int) $a['id']));
    }

    public static function crearValor(array $p, array $ctx): void
    {
        $a = self::atributo((int) $p['id']);
        $b = Http::body();
        $nombre = trim((string) ($b['nombre'] ?? ''));
        if ($nombre === '') throw new ApiError('El nombre es obligatorio', 400, 'VALIDATION');
        $id = Db::insert('INSERT INTO atributo_valores (id_empresa, id_atributo, nombre, extra, orden) VALUES (?,?,?,?,?)',
            [Tenant::id(), (int) $a['id'], $nombre, $b['extra'] ?? null, (int) ($b['orden'] ?? 0)]);
        Http::created(self::fmtValor(self::valor($id)), 'Valor');
    }

    public static function actualizarValor(array $p, array $ctx): void
    {
        $v = self::valor((int) $p['id']);
        $b = Http::body();
        Db::run('UPDATE atributo_valores SET nombre = ?, extra = ?, orden = ?, is_active = ? WHERE id = ? AND id_empresa = ?', [
            array_key_exists('nombre', $b) && trim((string) $b['nombre']) !== '' ? trim((string) $b['nombre']) : $v['nombre'],
            array_key_exists('extra', $b) ? $b['extra'] : $v['extra'],
            array_key_exists('orden', $b) ? (int) $b['orden'] : $v['orden'],
            array_key_exists('is_active', $b) ? (($b['is_active'] === 'No') ? 'No' : 'Si') : $v['is_active'],
            $v['id'], Tenant::id(),
        ]);
        Http::updated(self::fmtValor(self::valor((int) $v['id'])), 'Valor');
    }

    public static function eliminarValor(array $p, array $ctx): void
    {
        $v = self::valor((int) $p['id']);
        Db::run("UPDATE atributo_valores SET is_active = 'No' WHERE id = ? AND id_empresa = ?", [$v['id'], Tenant::id()]);
        Http::deleted('Valor');
    }
}
