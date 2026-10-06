# LEVOTEK — Estado del proyecto (handoff)

> Documento para **retomar el proyecto sin perder contexto**. Resume qué se hizo, qué falta,
> las decisiones tomadas, el contrato técnico y cómo seguir.
> Última actualización: **2026-06-29**.

---

## 1. Resumen en una línea
ERP de ropa **LEVOTEK** con **frontend React**
y un **backend nuevo en PHP + MySQL** pensado para **GoDaddy** (hosting compartido).

## 2. Ubicaciones
- **Proyecto:** `C:\Users\Jaqueline\Documents\Github\sistematienda`

## 3. Decisiones tomadas (acordadas con el usuario)
1. **Alcance = NÚCLEO** (no el ERP completo de ~25 módulos).
   Núcleo = login/usuarios, catálogos, artículos + inventario **color-talla**, almacenes,
   inventario/kardex, clientes, empleados, proveedores, ventas/POS, cortes de caja.
2. **Frontend en React tal cual** (se compila y se sube `dist/`). No se reescribió en PHP.
3. **Backend en PHP + MySQL** (compatible con GoDaddy compartido).
4. **Marca LEVOTEK**, paleta índigo+ámbar.

---

## 4. ✅ LO QUE YA ESTÁ HECHO

### Frontend (`frontend/`)
- [x] Marca LEVOTEK: textos, `package.json`, `index.html` (título/favicon/theme),
      `capacitor.config.ts`, token keys `levotek_token`/`levotek_user`, IndexedDB `levotek_offline`.
- [x] Logos **`public/logo.svg`** y **`public/favicon.svg`** (rayo + wordmark).
- [x] **Diseño**: paleta **índigo (#4f46e5) + ámbar (#f59e0b)** en `src/styles/globals.css`;
      sidebar con barra de acento en el item activo; clases `gradient-brand*`.
- [x] Socket.io **desactivado** salvo que se defina `VITE_SOCKET_URL` (no aplica en GoDaddy).
- [x] `.env.production` apunta el API a `/api/v1` (mismo dominio).
- [x] `public/.htaccess` para que React Router (SPA) funcione en Apache/GoDaddy.
- [x] `engines` bajado a Node ≥20.
- [x] **Compila sin errores** (`npm run build` → `dist/` con logo, favicon, .htaccess, assets).

### Backend (`backend/api/`) — PHP puro, sin Composer
- [x] `index.php` — front controller + router (rutas bajo `/api/v1`).
- [x] `lib/config.php` — credenciales BD + JWT (editar aquí en GoDaddy). Soporta `DB_PORT`.
- [x] `lib/Db.php` (PDO), `lib/Jwt.php` (HS256 propio), `lib/Http.php` (envelope + helpers), `lib/Pricing.php` (motor de precios).
- [x] Controladores en `lib/controllers/`: Auth, Catalogo, Articulo, Almacen, Inventario, Cliente, Empleado, Proveedor, Venta, Corte.
- [x] `schema.sql` — esquema MySQL (utf8mb4) con todas las tablas núcleo.
- [x] `install.php` — instalador web que crea tablas + datos semilla (admin, tienda+bodega, catálogos base, artículo demo con inventario, cliente "Público General").
- [x] `.htaccess` (enruta a index.php + pasa header Authorization) y `lib/.htaccess` (bloquea acceso directo).

### Endpoints implementados (todos responden el contrato `{success,data,error,message,meta}`)
- **Auth:** POST `/auth/login`, GET `/auth/me`, POST `/auth/change-password`,
  GET/POST `/auth/usuarios`, PUT/DELETE `/auth/usuarios/:id`, POST `/auth/usuarios/:id/reset-password`.
- **Catálogos:** GET/POST `/catalogos/{tipo}`, GET/PUT/DELETE `/catalogos/{tipo}/:id`
  (tipos: colores, tallas, familias, lineas, colecciones, marcas, conceptos-gasto).
- **Artículos:** GET `/articulos`, GET `/articulos/buscar`, GET/PUT/DELETE `/articulos/:id`, POST `/articulos` (con variantes color/talla).
- **Clientes:** GET `/clientes`, GET/PUT/DELETE `/clientes/:id`, POST `/clientes`, PATCH `/clientes/:id/autorizar-credito`.
- **Empleados / Proveedores:** CRUD básico.
- **Almacenes:** GET/POST `/almacen/almacenes`, PUT/DELETE `/almacen/almacenes/:id`.
- **Inventario:** GET `/almacen/inventario`, GET `/almacen/inventario/kardex`, POST `/almacen/inventario/ajuste`, POST `/almacen/inventario/ajuste-lote`.
- **Ventas/POS:** GET `/ventas`, POST `/ventas/cotizar`, POST `/ventas`, GET `/ventas/:id`, PATCH `/ventas/:id/cancelar`.
- **Cortes:** GET `/cortes/preview`, POST `/cortes/cerrar`, GET `/cortes`, GET `/cortes/:id`.
- **Compras (FASE 2):** GET `/compras`, POST `/compras`, GET `/compras/:id`, PUT `/compras/:id`,
  PATCH `/compras/:id/aprobar`, DELETE `/compras/:id`, GET/POST `/compras/:id/pagos`.
- **Traspasos (FASE 2):** GET `/almacen/traspasos`, POST `/almacen/traspasos`, GET `/almacen/traspasos/:id`,
  PUT `/almacen/traspasos/:id`, PATCH `/almacen/traspasos/:id/aceptar`, PATCH `/almacen/traspasos/:id/rechazar`.
- **Apartados (FASE 2):** GET/POST `/apartados`, GET/PUT `/apartados/:id`, PATCH `/apartados/:id/anticipo`,
  PATCH `/apartados/:id/liquidar`, PATCH `/apartados/:id/cancelar`.
- **Devoluciones/Cambios (FASE 2):** GET `/devoluciones/buscar-venta`, GET/POST `/devoluciones`,
  GET/POST `/devoluciones/cambios`.
- **Comisiones (FASE 2):** GET `/comisiones`, GET `/comisiones/resumen`, PATCH `/comisiones/:id/pagar`.
- **Finanzas (FASE 2):** GET/POST `/finanzas/bancos`; CxC: GET `/finanzas/cuentas-cliente`,
  GET `/finanzas/cuentas-cliente/:id/movimientos`, GET `/finanzas/cuentas-cliente/:id/estado-cuenta`,
  POST `/finanzas/cuentas-cliente/abono`; CxP: GET `/finanzas/cuentas-proveedor`,
  GET `/finanzas/cuentas-proveedor/:id/movimientos`, POST `/finanzas/cuentas-proveedor/pago`.
- **Monedero (FASE 2):** GET `/monedero/:idCliente`, POST `/monedero/ajuste`.
- **Reportes (FASE 2):** GET `/reportes/{ventas,utilidad,por-lista,top-productos,cortes-periodo,cxc-antiguedad,
  cxp-proveedores,comisiones,existencias-valorizadas,kardex,compras,devoluciones}`.
- **Bitácora (FASE 2):** GET `/audit-log` (registro automático vía `Ledger::audit`).

### 🔄 Feature "cambio en efectivo" (2026-06-30)
al pagar de más en efectivo, el cajero elige **devolver el cambio en efectivo** o mandarlo **al monedero**
(antes el excedente siempre iba a monedero). Frontend: POS, Apartados (liquidar), detalle de Ventas, Cortes y
Reportes (con **efectivo neto** = efectivo recibido − cambio entregado). Backend PHP agregado:
`destino_cambio` en `VentaController::registrar` + columna **`ventas.cambio_efectivo`**, `obtenerVenta`,
`ApartadoController::liquidar`, `CorteController` (resumen) y `ReporteController::cortesPeriodo`.
**Migración:** `install.php` ahora aplica migraciones idempotentes (columnas nuevas); en GoDaddy basta
re-abrir `install.php?go=1`. La BD local ya tiene la columna aplicada.

### 🔄 7 features nuevas (2026-07-07)
8 archivos de frontend + su soporte en el backend PHP:
1. **Catálogo "Colecciones" → "Cortes"**: tipo `cortes` en catálogos, `articulos.id_coleccion` → `id_corte`.
   ⚠️ La tabla `cortes` ya existía (cortes de **caja**), así que el catálogo usa la tabla **`cortes_catalogo`**.
   La migración copia `colecciones` → `cortes_catalogo` conservando ids.
2. **Fotos de artículo**: `POST /articulos/foto` (base64 → guarda en `api/uploads/articulos/`, devuelve `{key,url}`),
   tabla `articulo_fotos`, sync en crear/actualizar. La carpeta `uploads/` debe ser **escribible** en GoDaddy.
3. **Inventario**: existencias ahora pobla `id_articulo.id_marca` y `id_articulo.costo` (filtros marca/costo en cliente).
4. **Compras**: editar / ver detalle / eliminar (endpoints ya existían; se pobló color/talla y `aprobada_por` en el detalle).
5. **Traspasos**: editar / ver detalle (detalle con artículo/color/talla poblados y creado_por/aceptado_por).
6. **Proveedores**: dirección estructurada `domicilio` (JSON, 8 campos) + `tipo`.
7. **Clientes: PIN para listas 4/5** (reemplaza el permiso): `GET/PUT /config-sistema/pin-lista-alta`
   (PIN hasheado en `empresas`), validado al asignar lista ≥4. `ConfigController` nuevo.
- Tablas nuevas: `cortes_catalogo`, `articulo_fotos`. Columnas nuevas: `articulos.id_corte`,
  `proveedores.tipo`/`domicilio`, `empresas.pin_lista_alta`/`pin_lista_alta_at`. Todas con migración en `install.php`.
- Validado: `php -l` (10 archivos) + `npm run build` OK. BD local migrada. **Falta probar en la UI** (servicios abajo).

### ✔️ Probado end-to-end (contra MariaDB real, no solo escrito)
- Login + JWT ✓ · `/me` ✓ · catálogos/almacenes/artículos con variantes pobladas ✓
- Cotizar: **5 pz → nivel 2, $189 (lista2)**; **24 pz → nivel 5, $159 (lista5)** ✓
- Venta: descuenta inventario en la **celda exacta** (color3/talla1: 10 → 7), excedente a monedero ✓
- Cancelar venta: repone inventario (→ 10) y revierte saldos ✓
- Kardex registra el movimiento ✓ · Corte preview + cierre ✓
- Errores: pago insuficiente → 400, ruta inexistente → 404, sin token → 401 ✓
- 17/17 archivos PHP pasan `php -l` (sin errores de sintaxis).

### ✔️ Compras (FASE 2) — probado end-to-end (2026-06-29)
- Crear compra (estado `por_aprobar`, **no** toca stock): subtotal 800 + IVA 128 = **928** ✓
- **Aprobar** → entra stock a la celda exacta color/talla (col1/tal1: 10 → **15**), kardex tipo `compra` ✓
- Actualiza **último costo** del artículo al aprobar ✓
- Doble aprobación bloqueada (400) ✓ · Pago a proveedor → `total_pagado` ✓
- **Eliminar/cancelar** una compra aprobada **revierte** el stock (15 → 10) ✓
- Validaciones: compra vacía → 400, pagar/editar cancelada → 400 ✓
- Tablas nuevas: `compras`, `compra_lineas`, `compra_pagos` (en `schema.sql` y en BD viva).

### ✔️ Traspasos (FASE 2) — probado end-to-end (2026-06-30)
- Crear traspaso (estado `pendiente`, **no** mueve stock); valida origen≠destino y stock suficiente en origen ✓
- **Aceptar** → mueve stock origen→destino en la celda exacta color/talla (alm1: 10→6, alm2: 0→4),
  kardex con `traspaso_salida` (-) y `traspaso_entrada` (+); revalida stock al aceptar ✓
- **Rechazar** un pendiente → no mueve stock ✓ · Doble aceptar bloqueado (400) ✓
- Validaciones: mismo origen/destino → 400, stock insuficiente → 400 ✓
- Tablas nuevas: `traspasos`, `traspaso_lineas` (en `schema.sql` y en BD viva).

### ✔️ Resto de FASE 2 — implementado y probado end-to-end (2026-06-30)
Construido con un helper compartido **`lib/Ledger.php`** que centraliza los libros mayores (CxC, monedero, CxP)
y la bitácora, mutando saldos de forma consistente. Se conectó a `VentaController` y `CompraController`
(hooks: venta a crédito → CxC; sobrepago → monedero; venta → comisión; compra aprobada/pagada → CxP).
- **Finanzas** ✓ Banco crear/listar; CxC con cargos/abonos/saldo, movimientos, estado de cuenta y abono
  (sube saldo del banco); CxP por moneda (MXN/USD) desde compras + pagos directos.
- **Monedero** ✓ estado de cuenta (saldo a favor + historial) y ajuste manual (bloquea negativo).
- **Comisiones** ✓ generadas por venta (base × % del vendedor), resumen y marcar pagada; se anulan al cancelar venta.
- **Apartados** ✓ reservan stock (`inventario.reservado`), anticipos, **liquidar genera la venta** (vía
  `VentaController::registrar` reutilizable) y libera reserva; cancelar libera reserva y abona anticipo al monedero.
- **Devoluciones** ✓ `buscar-venta` (con precios/colores/tallas poblados), reingresan stock y acreditan a
  monedero (contado) o CxC (crédito); bloquean devolver más de lo vendido.
- **Cambios** ✓ devuelven + venden con diferencia (a favor→monedero, en contra→pago/CxC).
- **Reportes** ✓ los 12 reportes (ventas, utilidad, por-lista, top-productos, cortes-periodo, cxc-antiguedad,
  cxp-proveedores, comisiones, existencias-valorizadas, kardex, compras, devoluciones).
- **Bitácora** ✓ `/audit-log` con registro automático de acciones clave.
- **Dashboard** ✓ ya funcionaba con `ventasApi` (no requería backend nuevo).
- Pruebas: 3 baterías E2E, **0 fallos** (38+ checks). Tablas nuevas: `bancos`, `cliente_movimientos`,
  `monedero_movimientos`, `proveedor_movimientos`, `apartados`(+lineas/anticipos), `devoluciones`(+lineas),
  `cambios`(+lineas), `comisiones`, `audit_log`. Datos de prueba limpiados (estado base: inventario 10, sin saldos).

---

## 5. ⏳ LO QUE FALTA (fase 2 y mejoras)

### Módulos del frontend SIN backend todavía
**Toda la FASE 2 funcional está HECHA** (ver sección 4). Lo único que queda sin backend:
- ~~Compras, Traspasos, Apartados, Devoluciones/Cambios, Finanzas, Comisiones, Reportes, Bitácora~~ ✅ **HECHO**
- **Facturación CFDI / Facturama** (`/facturacion`) — **pendiente a propósito**: requiere integración con un PAC
  externo (Facturama) y credenciales fiscales. La página del front es un *stub* informativo; no rompe nada.
- **Configuración del sistema** (`/config`) — la página es solo perfil/preferencias del usuario (usa `useAuth`),
  **no llama API**, así que no requiere backend nuevo.

### Mejoras pendientes (deuda técnica conocida)
- [x] **Permisos a nivel servidor** ✅ (2026-06-30): enforcement por **módulo** en `index.php`
  (`route_permiso()` + `user_has_permiso()`, replica la lógica del front; **admin pasa todo**).
  Mapea cada prefijo de ruta a su permiso `*.ver` (p.ej. `/compras/*`→`compras.ver`, `/audit-log`→`admin`).
  Validado con 14 casos vía `php -l`/CLI. *Nota:* es a nivel módulo (no distingue ver/crear/aprobar);
  las acciones finas siguen ocultas en el front. No afecta al admin actual (único usuario).
- [x] **`jwt_secret`** ✅ ahora tiene un valor por defecto fuerte en `lib/config.php` (y sigue
  siendo overrideable por `JWT_SECRET`). Recomendable cambiarlo por uno propio en cada despliegue.
- [ ] **Borrar `install.php`** tras instalar (ya es idempotente: solo siembra si no hay empresa).
- [ ] **Cambiar contraseña** del admin tras el primer login.
- [ ] Multi-tienda/privacidad de clientes por `id_tienda` (campos existen; falta filtrar consultas por tienda para no-admin).
- [ ] Folio de venta: hoy es `serie + correlativo por almacén` (simple); revisar si se quiere por empresa/serie fiscal.
- [ ] Anticipos de apartado: hoy entran al corte el día de la **liquidación** (no el día que se reciben). Revisar si se requiere reconocerlos en caja el día del anticipo.
- [ ] Facturación CFDI: integrar PAC (Facturama) cuando se tengan credenciales.

---

## 6. Contrato técnico (para no romper el frontend)
- **Envelope** de respuesta: `{ success: bool, data, error: {code,message}|null, message?, meta? }`.
- **Auth:** `POST /auth/login {email,password}` → `data: {token, user}`. JWT **Bearer** en header.
  El front guarda `levotek_token` y `levotek_user` en localStorage; lee `exp` del JWT.
- **IDs**: MySQL usa enteros pero se exponen como **`_id` (string)** para imitar a Mongo. Referencias pobladas
  vienen como objetos: ej. inventario → `id_articulo:{_id,codigo,descripcion}`, `id_color:{_id,nombre,hex}`, etc.
- **color/talla 0** = artículo sin color/talla (se puebla como `{_id:'', nombre:'Unico/Unica'}`).
- **Pricing** (listas 1=más cara … 5=más barata): nivel por cantidad total sin ofertas →
  24+→L5, 12-23→L4, 6-11→L3, 3-5→L2, 1-2→L1. Se elige el **menor** precio entre la lista del cliente y la de cantidad.
  Si `es_oferta`, usa `precio_oferta`. Precios **incluyen IVA** (16%).

---

## 7. Despliegue en GoDaddy (resumen)
1. cPanel → crear BD MySQL + usuario; poner datos en `backend/api/lib/config.php` (+ cambiar `jwt_secret`).
2. Subir `backend/api/` → `public_html/api/` y `frontend/dist/` → `public_html/`.
3. Abrir `https://tudominio.com/api/install.php?go=1`.
4. Entrar con **admin@levotek.mx / admin123**, cambiar contraseña, **borrar install.php**.

Detalle completo en **[README.md](README.md)**.

## 8. Cómo retomar en local (desarrollo)
- **Un clic (recomendado):** ejecutar **`iniciar-levotek.bat`** en la raíz del proyecto. Levanta MySQL de
  XAMPP (3307), backend PHP (8080) y frontend Vite (3001). Para apagar todo: **`detener-levotek.bat`**
  (cierre limpio; **no toca** tu MySQL del sistema en 3306). La 1ª vez, abrir `http://127.0.0.1:8080/install.php?go=1`.
- **Manual — Backend:** `cd backend/api` y `php -S 127.0.0.1:8080` con variables
  `DB_HOST/DB_PORT/DB_NAME/DB_USER/DB_PASS` y `APP_DEBUG=true`. Instalar en `/install.php?go=1`.
  Rutas accesibles vía `http://127.0.0.1:8080/index.php/v1/...`.
- **Manual — Frontend:** `cd frontend` → `npm install` → `npm run dev` (puerto 3001, o 3002 si está ocupado).
  El `.env` ya apunta a `VITE_API_URL=http://127.0.0.1:8080/index.php/v1`.
- Requiere PHP con `pdo_mysql` y un MySQL/MariaDB local (XAMPP en 3307).
- **Nota de la prueba ya realizada:** se validó con un PHP portable + el MySQL de XAMPP en el puerto 3307
  (la BD de prueba `levotek_test` ya se eliminó; el servicio MySQL94 del sistema quedó intacto).

## 9. Credenciales por defecto
| | |
|---|---|
| Usuario | `admin@levotek.mx` |
| Contraseña | `admin123` (cambiar al entrar) |

---

## 10. Próximo paso sugerido
**Núcleo + Fase 2 completos** (solo falta Facturación CFDI, que depende de un PAC externo). El siguiente paso
natural es **subir a GoDaddy y probar en vivo** todo el ERP. Antes de producción, atender la deuda técnica de la
sección 5 (sobre todo: cambiar `jwt_secret`, borrar `install.php`, cambiar contraseña admin, y evaluar el
enforcement de permisos por endpoint para usuarios no-admin).
