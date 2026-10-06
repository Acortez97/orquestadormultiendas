# CLAUDE.md — Orquestador MultiTiendas

Contexto para Claude Code. Léelo completo antes de tocar código. El diseño detallado está en
[PLAN-ORQUESTADOR.md](PLAN-ORQUESTADOR.md); este archivo resume lo que no se puede olvidar.

## Qué es
Plataforma multi-tienda (multi-tenant) de POS/ERP: **un proyecto, un dominio, un hosting (GoDaddy compartido),
una sola BD**. Fork de MultiTienda (a su vez de LEVOTEK). Dos paneles en el mismo SPA:
- `/admin` → **superadmin** (dueño de la plataforma): crea tiendas, habilita módulos, administra usuarios.
- `/` → **panel de tienda**: cada tienda trabaja solo con lo suyo.

**Idioma:** el usuario escribe en español; responde y documenta en español.

## Estado: F0–F7 completas (2026-10-06)
Backend, frontend y empaquetado listos y probados. Pendiente del usuario: subir a GoDaddy
(ver [DESPLIEGUE-GODADDY.md](DESPLIEGUE-GODADDY.md)) y definir el nombre comercial (los textos visibles aún
dicen "MultiTienda"; la tienda ve su propio nombre/logo).

## Reglas NO negociables (aislamiento entre tiendas)
1. **"Tienda" = fila de `empresas`.** Toda tabla de negocio tiene `id_empresa NOT NULL`. Solo son globales:
   `empresas`, `modulos`, `modulo_acciones`, `login_intentos`.
2. **Ninguna tienda puede ver, usar ni deducir datos de otra**, ni saber que existen (ids, folios, SKU,
   mensajes de error, URLs de fotos, pantallas).
3. **El `id_empresa` sale SIEMPRE de `Tenant::id()`** (sesión), nunca del body/query (`Tenant::entrada` lo descarta).
4. **Toda consulta** filtra `id_empresa = ?`, incluidos los JOIN (`ON x.id_empresa = y.id_empresa AND x.id = y.id_x`).
5. **Todo id que llega del front** se valida con `Tenant::owns($tabla, $id, 'Etiqueta', $requerido)`.
   Ajeno o inexistente → **404** (nunca 403).
6. **Esquema:** toda relación entre tablas de negocio es FK compuesta `(id_empresa, id_x) → padre(id_empresa, id)`;
   cada tabla declara `UNIQUE (id_empresa, id)`. RESTRICT en negocio, CASCADE solo en detalle, **nunca SET NULL**.
7. **Importes y precios nunca se toman del front** (ventas/cambios cotizan con `Pricing`; devoluciones valoran al
   precio vendido). Saldos (`clientes.saldo_*`, `bancos.saldo_actual`) solo cambian vía `lib/Ledger.php`.
8. Cambios al esquema → `backend/api/schema.sql` + casos en `backend/tests/esquema_test.php` (debe dar 100 %).
9. **Nunca** commitear secretos: `lib/config.local.php`, `frontend/.env`, `*.local.txt`, `deploy-godaddy/` están ignorados.

## Arquitectura del backend (`backend/api/`)
- `index.php`: tabla de rutas `[metodo, patron, handler, permiso]`. Permisos: `publico`, `sesion`, `tienda`,
  `'modulo.accion'` (o array = cualquiera), `'admin:modulo'` (solo admin_tienda), `plataforma` (solo superadmin).
  **Ruta sin permiso declarado = 404.** Recarga el usuario de la BD en cada petición (activo + `token_version`),
  activa la tienda, convierte ids opacos y autoriza. En modo soporte registra toda escritura en la bitácora.
  `PDOException` 23000 → 400 genérico.
- `lib/Tenant.php`: tienda activa + **ids opacos** (Feistel 32 bits + HMAC 16 bits, base62 de 9 caracteres, sal
  `empresas.id_salt`). Conversión automática: `$_GET`, parámetros `:id` y `Http::body()` se decodifican al entrar;
  `Http::ok()` codifica la salida. Claves de id: `id`, `_id`, `id_*`, `*_por`, `colores`, `tallas`, `valores`.
  Nunca sale `id_empresa`. En `/plataforma/*` se usa la sal de plataforma. `Http::bodyCrudo()` = sin conversión.
- `lib/Permisos.php`: permiso efectivo = `user_permisos` ∩ `empresa_modulos.activo`; admin_tienda = todas las
  acciones de los módulos activos. El módulo `usuarios` no se asigna como permiso suelto.
- `lib/Usuarios.php` (alta/edición con reglas de admin_tienda vs superadmin), `lib/LoginId.php` (correos de acceso),
  `lib/Seeder.php` (superadmin, alta de tienda con su semilla, datos demo), `lib/Variantes.php` (valida variante
  del artículo; API usa `id_color/id_talla`, BD `id_valor1/id_valor2` con NULL), `Db::folio()` (consecutivos por
  tienda con `FOR UPDATE`).
- **Dinero** (`lib/Cobros.php` + `Ledger::bancoMov/cajaMov`): todo cobro entra con `Cobros::entrada` y todo pago
  sale con `Cobros::salida`. Efectivo → caja del almacén (`caja_movimientos`); `tdc`/`tdb` → **terminal obligatoria**
  y el dinero cae en la cuenta de esa terminal; `transferencia`/`cheque` → **cuenta obligatoria**; `monedero` y
  `anticipo` no mueven dinero. Saldo de cuenta = Σ `banco_movimientos` (la conciliación lo revisa). Depósito =
  sale de caja y entra a cuenta. El corte (`CorteController::computar`) toma el efectivo esperado del libro de caja
  del día y el desglose "dónde quedó el dinero" solo de cobros a clientes (`Venta`, `CancelacionVenta`,
  `Apartado`, `Cambio`, `Abono`). Un corte por almacén y día.
- `lib/controllers/PlataformaController.php`: tiendas, módulos, usuarios de cualquier tienda, entrar en soporte
  (token 2 h con claim `t`), conciliación, respaldo JSON, bitácora global.

## Roles, login y sesión
- Roles: `superadmin` (`id_empresa` NULL; CHECK en BD), `admin_tienda`, `usuario`.
- Login: `usuario@<slug>.levotek.com` (tienda) o `usuario@levotek.com` (superadmin). Error siempre
  "Correo o contraseña incorrectos" (hash de relleno contra timing); bloqueo 5 fallos/15 min por correo, 20 por IP.
- JWT solo trae `u` (id cifrado con sal de plataforma), `v` (token_version) y opcional `t` (soporte). Cambiar
  permisos, desactivar o resetear contraseña sube `token_version` → la sesión se cierra.
- Usuarios nuevos `debe_cambiar_password = 1` (el front obliga a cambiarla). `empresas.slug` es inmutable.
- Superadmin en soporte: `ctx.user.id = null`, `act_as` = su id; los registros que crea guardan `id_usuario` NULL.

## Frontend (`frontend/src/`)
- `services/session.js` (token/usuario, modo soporte, limpieza), `contexts/AuthContext.jsx` (`hasPermiso('mod.acc')`,
  `esSuperadmin`, `esAdminTienda`, `entrarSoporte`, `cambiarPassword`).
- `App.jsx`: zonas `tienda` / `admin` / `sesion`, todas las páginas con `React.lazy`.
- `pages/admin/*`: panel general. `pages/usuarios/UsuariosPage.jsx`: usuarios de la tienda (solo admin_tienda).
  `components/common/PermisosEditor.jsx`: editor módulo × acción.
- Permisos del front deben coincidir con `modulo_acciones` (datos al final de `schema.sql`).

## Entorno local (Windows)
- MariaDB 10.4 de XAMPP en **3307** (forzado con `--port=3307`; en 3306 está el MySQL del sistema: no tocarlo).
- `iniciar.bat` / `detener.bat`: MariaDB 3307, backend `php -S 127.0.0.1:8082 -t backend/api`, Vite 3001.
- `backend/api/lib/config.local.php` (de `config.local.example.php`); `frontend/.env` (de `.env.example`).
- `php backend/api/reset-db.php --go --demo` → superadmin + tiendas `demo1` y `demo2` (admin + cajero1).
  Contraseñas aleatorias en `backend/api/credenciales.local.txt` (ignorado). **No las muestres en el chat.**
  Todos los usuarios demo deben cambiar su contraseña al primer acceso por la UI (la API de pruebas no lo exige).

## Pruebas (todas deben dar 100 %; las de API requieren el backend en 8082)
```bash
php backend/api/reset-db.php --go --demo && php backend/tests/esquema_test.php         # 53  aislamiento en BD
php backend/tests/loginid_test.php                                                     # 16  correos de acceso
php backend/api/reset-db.php --go --demo && php backend/tests/auth_test.php            # 59  login, permisos, usuarios
php backend/api/reset-db.php --go --demo && php backend/tests/aislamiento_api_test.php # 200 flujo completo + ataques
php backend/api/reset-db.php --go --demo && php backend/tests/plataforma_test.php      # 53  panel de plataforma
php backend/api/reset-db.php --go --demo && php backend/tests/cobros_test.php          # 44  cobros, cuentas, caja y corte
cd frontend && npm run build                                                           # JS principal ~309 KB
```
**Datos de prueba para revisar en pantalla:** `php backend/api/reset-db.php --go --demo && php herramientas/datos_prueba.php`
(crea además la tienda `papeleriaroma` desde la API de plataforma y en las 3 tiendas: compras, traspasos, merma,
ventas con cada forma de pago, crédito, kit, cancelación, devoluciones, cambios, apartados, abonos, pagos CxP,
comisiones, gastos, depósitos y corte; al final verifica caja, cuentas, corte y conciliación). Correr las suites
de pruebas después borra esos datos: vuelve a cargarlos.

Cada suite de API necesita una BD recién reiniciada. Para agregar casos de aislamiento, usa `noEncontrado()`
(exige 404 de registro, no de ruta) y agrega un control positivo con la tienda dueña.

## Despliegue
`cd frontend && npm run build` → `php herramientas/empaquetar.php` → subir `deploy-godaddy/public_html/`.
Guía completa: [DESPLIEGUE-GODADDY.md](DESPLIEGUE-GODADDY.md). Nunca se suben `reset-db.php`, `backend/tests/`,
`config.local.php` local ni `credenciales.local.txt` (el empaquetador lo verifica).

## Limitaciones conocidas / ideas
- Kits: se expanden a componentes en venta, cancelación, devolución y cambio; en compras, traspasos y apartados se
  mueve el propio kit.
- Las comisiones de terminal (`terminales.comision_pct`) son informativas: el cobro entra completo a la cuenta.
- Facturación CFDI sigue pendiente (requiere PAC).
- No hay migraciones: el esquema v2 es la versión inicial; cambios futuros necesitan su script de migración.
- Documentación histórica (MultiTienda/LEVOTEK) en `docs/historial/`.
