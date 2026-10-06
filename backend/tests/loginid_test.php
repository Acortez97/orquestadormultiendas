<?php
// Prueba del armado/separado de correos de acceso.   php backend/tests/loginid_test.php
require_once __DIR__ . '/../api/lib/LoginId.php';

$ok = 0; $fallos = [];
function igual(string $desc, $esperado, $real): void {
    global $ok, $fallos;
    if ($esperado === $real) { $ok++; echo "  ✓ $desc\n"; return; }
    $fallos[] = $desc;
    echo "  ✗ $desc  esperado=" . json_encode($esperado) . ' real=' . json_encode($real) . "\n";
}
function lanza(string $desc, callable $fn): void {
    global $ok, $fallos;
    try { $fn(); $fallos[] = $desc; echo "  ✗ $desc  (no lanzo error)\n"; }
    catch (InvalidArgumentException $e) { $ok++; echo "  ✓ $desc\n"; }
}

$d = 'levotek.com';
echo "== Armar ==\n";
igual('Usuario de tienda', 'cajero1@zapateriacentro.levotek.com', LoginId::armar('cajero1', 'zapateriacentro', $d));
igual('Superadmin', 'admin@levotek.com', LoginId::armar('admin', null, $d));

echo "== Separar ==\n";
igual('Usuario de tienda', ['usuario' => 'cajero1', 'slug' => 'zapateriacentro'], LoginId::separar('cajero1@zapateriacentro.levotek.com', $d));
igual('Mayusculas y espacios se normalizan', ['usuario' => 'cajero1', 'slug' => 'demo1'], LoginId::separar('  Cajero1@DEMO1.Levotek.com ', $d));
igual('Superadmin (sin subdominio)', ['usuario' => 'admin', 'slug' => null], LoginId::separar('admin@levotek.com', $d));
igual('Otro dominio se rechaza', null, LoginId::separar('admin@gmail.com', $d));
igual('Dominio parecido se rechaza', null, LoginId::separar('admin@demo1.levotek.com.mx', $d));
igual('Dominio que solo termina igual se rechaza', null, LoginId::separar('admin@falsolevotek.com', $d));
igual('Subdominio de dos niveles se rechaza', null, LoginId::separar('admin@a.b.levotek.com', $d));
igual('Sin @ se rechaza', null, LoginId::separar('admin', $d));
igual('Usuario invalido se rechaza', null, LoginId::separar('ju an@demo1.levotek.com', $d));

echo "== Validaciones ==\n";
lanza('Slug con espacios', fn() => LoginId::slug('mi tienda'));
lanza('Slug reservado', fn() => LoginId::slug('admin'));
lanza('Slug que empieza con guion', fn() => LoginId::slug('-tienda'));
lanza('Usuario con @', fn() => LoginId::usuario('a@b'));
igual('Slug valido se normaliza', 'papeleria-roma', LoginId::slug('Papeleria-Roma'));

echo "\n== Resultado: $ok / " . ($ok + count($fallos)) . " ==\n";
exit($fallos ? 1 : 0);
