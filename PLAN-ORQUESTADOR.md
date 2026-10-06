# Orquestador MultiTiendas — Plan de arquitectura

> Copia de MultiTienda convertida en plataforma **multi-tienda (multi-tenant)**: un solo proyecto,
> un solo dominio, un solo hosting y **una sola base de datos**, con dos paneles:
> **Administración general** (superadmin) y **Panel de tienda** (usuarios de cada tienda).
> Fecha del plan: **2026-10-06**.

---

## 1. Punto de partida (lo que ya existe y se aprovecha)

El esquema heredado **ya está pensado para varias empresas**:

- Tabla `empresas` y columna `id_empresa` en **36 de 47 tablas**. Las 11 restantes son tablas hijas
  (`venta_lineas`, `compra_pagos`, `articulo_colores`…) que cuelgan de un padre que sí la tiene.
- Los controladores filtran por `$ctx['user']['id_empresa']` en ~260 lugares; el JWT ya lleva `id_empresa`.
- Llaves únicas ya compuestas por empresa: `articulos (id_empresa, codigo)`, `almacenes (id_empresa, codigo)`.

**Conclusión:** en este sistema, **"tienda" = fila de `empresas`**. La base sirve, pero **sus relaciones
no garantizan el aislamiento**: las llaves foráneas son simples (`ventas.id_cliente → clientes.id`), así que
la BD aceptaría una venta de la Tienda A con un cliente de la Tienda B. Como este proyecto aún **no tiene
datos reales**, se reescribe el esquema completo (**esquema v2**, sección 5) en lugar de parchearlo con `ALTER`s.

### Glosario (para no confundir términos)
| Concepto | Tabla | Qué es |
|---|---|---|
| **Tienda** (cliente de la plataforma) | `empresas` | Unidad aislada: sus usuarios, almacenes, clientes, CxC, CxP… |
| **Sucursal / bodega** de una tienda | `almacenes` | Una tienda puede tener varias. |
| `users.id_tienda` / `clientes.id_tienda` (heredado) | — | Hoy apunta a un **almacén**. Se renombra a `id_almacen_default` para evitar confusión. |

---

## 2. ¿Una BD por tienda o una BD compartida?

| | **BD compartida + `id_empresa`** ✅ | BD por tienda | Esquema por tienda |
|---|---|---|---|
| Encaja con lo ya construido | Sí (90 % hecho) | Hay que reescribir conexión, instalador y migraciones | No aplica en MySQL (esquema = BD) |
| Crear tienda nueva | Un `INSERT` + semilla, desde el panel | En GoDaddy compartido **el usuario MySQL de PHP no puede `CREATE DATABASE`**; hay que crearla a mano en cPanel (o vía API de cPanel) | — |
| Límite de BDs del plan de hosting | No importa | Algunos planes de cPanel limitan el número de BDs | — |
| Migraciones (columna nueva) | 1 vez | N veces, con riesgo de que queden tiendas desfasadas | — |
| Reportes globales del superadmin | Un `SELECT … GROUP BY id_empresa` | Recorrer N conexiones | — |
| Aislamiento | Por código (hay que ser disciplinado) | Físico (más fuerte) | — |
| Respaldo/restauración de **una** tienda | Requiere script de export por `id_empresa` | Trivial (`mysqldump` de su BD) | — |

**Recomendación: BD compartida con `id_empresa`.** En hosting compartido de GoDaddy es lo único práctico,
y el código ya está hecho así. El riesgo (fuga de datos entre tiendas) se mitiga con la capa de
aislamiento y las pruebas de la sección 4.

**Ruta de escape (híbrido, a futuro):** como todo el código ya filtra por `id_empresa`, si una tienda crece
mucho se puede mover a su propia BD agregando `empresas.db_dsn` y que `Db::init()` elija la conexión
según la tienda. No hay que decidirlo hoy.

---

## 3. ¿Cuántas tiendas aguanta?

El límite **no lo pone MySQL**, lo pone el **hosting compartido** (CPU, RAM y procesos PHP simultáneos).

**Volumen de datos por tienda (estimado, tienda mediana):**
~150 ventas/día × 3 renglones + movimientos de inventario, compras, CxC, bitácora ≈ **400–600 mil filas/año**,
del orden de **100–200 MB/año** con índices.

| Escenario | Tiendas activas aprox. | Comentario |
|---|---|---|
| GoDaddy **compartido** | **20–40 tiendas** pequeñas/medianas (≈ 60–120 usuarios conectados a la vez) | El cuello de botella son las peticiones PHP simultáneas, no la BD. |
| **VPS** básico (2–4 vCPU, 4–8 GB) | **100–300 tiendas** | Mismo código, solo cambia el servidor. |
| Volumen de BD | Decenas de millones de filas sin problema | Siempre que los índices empiecen por `id_empresa` (sección 5.6). |

> Son cifras de orden de magnitud. Antes de pasar de ~15 tiendas en compartido, medir tiempos de respuesta
> y uso de CPU en cPanel, y planear el salto a VPS.

---

## 4. Modelo de seguridad y aislamiento (lo más importante)

### 4.0 Principio: cada tienda es un sistema independiente
**Todo** pertenece a una sola tienda y **nunca** se comparte ni se cruza:

| Área | Tablas (todas con `id_empresa`) |
|---|---|
| Usuarios, permisos, empleados, comisiones | `users`, `user_permisos`, `empleados`, `comisiones` |
| Almacenes / sucursales | `almacenes` |
| Catálogos | `atributos`, `atributo_valores`, `categorias`, `familias`, `lineas`, `marcas`, `cortes_catalogo`, `conceptos_gasto` |
| Artículos | `articulos`, variantes, kits, códigos, fotos |
| Inventario y kardex | `inventario`, `inventario_movimientos`, `traspasos` (+ líneas) |
| Ventas y caja | `ventas` (+ líneas, pagos), `apartados` (+ líneas, anticipos), `devoluciones`, `cambios`, `cortes` |
| Clientes y CxC | `clientes`, `cliente_movimientos`, `monedero_movimientos` |
| Proveedores y CxP | `proveedores`, `compras` (+ líneas, pagos), `proveedor_movimientos` |
| Bancos | `bancos` |
| Configuración y bitácora | datos de `empresas`, `audit_log` |

**No hay catálogos compartidos entre tiendas.** Al crear una tienda se le **copian** catálogos base
(colores, tallas, categorías) como filas propias; si una tienda los edita, no afecta a nadie más.
Las únicas tablas globales son de **sistema** y no contienen datos de negocio: `empresas` (solo la ve el
superadmin), `modulos` (lista fija de módulos del software) y `login_intentos`.

**Una tienda no puede saber que existen otras.** En ningún endpoint, mensaje de error, id, folio, URL de
foto o pantalla debe aparecer algo que delate otra tienda (ver 4.3 y 4.8).

**Defensa en 4 capas** (si una falla, la siguiente lo detiene):
1. **Base de datos:** llaves foráneas compuestas `(id_empresa, id)` → la BD **rechaza** cualquier relación
   entre filas de tiendas distintas, aunque el código tenga un error (sección 5.2).
2. **API:** `Tenant` fija la tienda desde el token y valida cada id recibido (4.3).
3. **IDs opacos por tienda:** el front nunca ve ids internos; un id de otra tienda ni siquiera decodifica (4.8).
4. **Pruebas automáticas** de aislamiento antes de cada despliegue (4.5).

### 4.1 Roles
| Rol | `id_empresa` | Acceso |
|---|---|---|
| `superadmin` | `NULL` | Panel de administración general. Todo. |
| `admin_tienda` | id de su tienda | Todos los módulos habilitados de **su** tienda + **gestión de usuarios de su tienda** (sección 4.7). Lo crea el superadmin. |
| `usuario` | id de su tienda | Solo los módulos que le asignó el superadmin o el admin de su tienda, **solo** dentro de su tienda. |

### 4.2 Permisos en tres niveles
1. **Módulos de la tienda** (`empresa_modulos`): qué tiene habilitado la tienda. **Solo el superadmin** los cambia.
2. **Permisos del usuario** (tabla `user_permisos`, por módulo **y acción**: ver/crear/editar/eliminar/aprobar):
   qué puede hacer ese usuario. Los asigna el superadmin o el `admin_tienda` de esa tienda.
3. **Rol**: `admin_tienda` tiene implícitos todos los módulos de su tienda.

**Permiso efectivo = permisos del usuario ∩ módulos de la tienda.** Se calcula en el servidor
(`index.php → user_has_permiso`) y se envía al front en `/auth/me` para ocultar menús. Si el superadmin
deshabilita un módulo de la tienda, desaparece para todos sus usuarios aunque lo tengan asignado.

### 4.3 Capa `Tenant` (nueva, `lib/Tenant.php`)
- `Tenant::id($ctx)` — única fuente del `id_empresa`; **nunca** se lee del body/query. Si el body trae
  `id_empresa`, se ignora.
- `Tenant::owns('clientes', $id)` — valida que un id recibido del front pertenece a la tienda.
  Debe usarse para **todo** id foráneo de entrada (`id_cliente`, `id_almacen`, `id_articulo`, valores de
  variante, `id_proveedor`, `id_banco`, `id_empleado`, `id_venta`, `id_compra`…).
- **Toda** consulta `SELECT/UPDATE/DELETE` lleva `WHERE id_empresa = ?`, incluidas las de tablas hijas
  (ahora también tienen `id_empresa`, sección 5.2). Se agregan helpers `Db::oneT/allT/runT` que exigen
  el `id_empresa` como parámetro para que olvidarlo sea un error visible.
- **Un id de otra tienda responde 404 "No encontrado"**, exactamente igual que un id que no existe.
  Nunca 403 (eso confirmaría que el registro existe).
- En cada petición: si la tienda está suspendida (`empresas.is_active='No'`) → sus usuarios reciben
  "Cuenta suspendida, contacte al administrador", sin más datos.
- Respuestas: los controladores devuelven solo campos de la propia tienda; nunca `id_empresa` ni nombres
  de otras tiendas. `/auth/me` devuelve solo los datos de **su** tienda.

### 4.4 Auditoría de consultas (hallazgo de la revisión)
Hay consultas que buscan por id **sin** filtrar empresa, por ejemplo:
`DevolucionController.php:28` (`SELECT * FROM articulos WHERE id=?`), `:56–57` (clientes/almacenes),
`CorteController.php:40`. Algunas son inofensivas (re-lectura tras un insert), pero hay que revisarlas **todas**
y, donde el id venga del usuario, pasar por `Tenant::owns()`.

### 4.5 Pruebas de aislamiento (obligatorias antes de producción)
Script PHP en `backend/tests/` que crea **Tienda A** y **Tienda B** y, con el token de A, intenta
leer/editar/usar ids de B en cada endpoint (venta con cliente de B, traspaso a almacén de B, abono a banco de B…).
Todas deben responder 404 (o 400 de validación) y la BD de B debe quedar **sin cambios** (se compara un
conteo/checksum de sus tablas antes y después). Además:
- **Reportes y dashboards** de A no incluyen ni una venta, compra, cliente o movimiento de B.
- **Folios, cortes y saldos** (CxC, CxP, monedero, bancos) de A no cambian cuando B opera, y viceversa.
- Ningún mensaje de error de A contiene datos de B (correo ya usado, código duplicado, etc.).
- Prueba de **BD directa**: intentar `INSERT` de una venta de A con cliente de B → MySQL debe rechazarlo.

Se corren antes de cada despliegue; si una falla, no se despliega.

### 4.6 "Entrar como tienda" (superadmin)
El superadmin puede abrir el panel de una tienda para dar soporte: `POST /plataforma/tiendas/:id/entrar`
emite un token temporal con `id_empresa` de esa tienda y `act_as_superadmin=<id>`. Todo lo que haga queda
en `audit_log` marcado como soporte. Hay un banner visible "Estás operando como Tienda X".

### 4.7 Gestión de usuarios delegada a la tienda (`admin_tienda`)
La tienda puede dar de alta a sus propios usuarios y administrar sus permisos **sin tocar otras tiendas
ni el panel de administración**. Reglas, todas validadas en el servidor:

| Regla | Detalle |
|---|---|
| Alcance | Solo ve y modifica usuarios con **su mismo `id_empresa`** (se toma del token, nunca del body). |
| Qué puede crear | Solo usuarios con rol `usuario`. **No** puede crear `admin_tienda` ni `superadmin`; eso lo hace solo el superadmin. |
| Correo de acceso | Solo captura la parte antes de la @; el dominio `@<su-tienda>.levotek.com` lo pone el servidor y no se puede cambiar. |
| Qué permisos puede dar | Solo módulos **habilitados en su tienda**. Si manda un módulo no habilitado → 400. |
| Qué no puede tocar | Su propio rol/permisos, a otros `admin_tienda`, los módulos de la tienda, ni nada bajo `/plataforma/*`. |
| Límite | Respeta `empresas.max_usuarios` si el superadmin lo definió. |
| Bajas | Desactivar (no borrar), resetear contraseña de sus usuarios. Al cambiar permisos o desactivar se incrementa `token_version` → la sesión del usuario se cierra al momento. |
| Almacén por defecto | Solo puede asignar almacenes de su propia tienda (`Tenant::owns('almacenes', …)`). |
| Trazabilidad | Cada alta/cambio de usuario va a `audit_log` de la tienda; el superadmin lo ve en la bitácora global. |
| Control del superadmin | Puede ver, editar o desactivar cualquier usuario de cualquier tienda, por encima del admin de tienda. |

Una tienda puede tener **uno o varios** `admin_tienda` (p. ej. dueño y gerente).

### 4.8 Que una tienda no pueda deducir que existen otras
| Fuga posible | Solución |
|---|---|
| **IDs consecutivos**: si la Tienda A crea un cliente y recibe el id 5000, sabe que hay miles en otras tiendas. | La API expone **ids opacos**: `_id` = id interno codificado con una **sal propia de cada tienda** (estilo Hashids). Un id de B, usado en A, no decodifica → 404. El front ya trata `_id` como texto, así que no cambia. |
| **Folios** (ventas, compras, apartados, cortes) | Consecutivos **por tienda** desde 1, con llave única `(id_empresa, serie, folio)`. |
| **"Este correo ya está registrado"** al crear usuario | Imposible entre tiendas: cada tienda solo crea correos con **su** dominio (`@tienda1.levotek.com`), así que el aviso de duplicado solo puede referirse a un usuario de la misma tienda. |
| Códigos / SKU / EAN duplicados | Únicos **por tienda**; dos tiendas pueden tener el mismo SKU. |
| **URLs de fotos** | `uploads/<token aleatorio de la tienda>/<archivo aleatorio>.jpg`; sin ids ni nombres de tienda en la ruta, y sin listado de directorio. |
| Bitácora | La de la tienda solo muestra sus eventos; las acciones de soporte del superadmin aparecen como "Soporte". |
| Navegador compartido (misma PC) | Al cerrar sesión se borran `localStorage`, la cola offline (IndexedDB) y cachés; las claves llevan la tienda para no mezclar sesiones. |
| Pantallas del front | El panel de tienda no tiene ninguna ruta, menú o llamada que liste tiendas; ese código vive solo en el bundle de `/admin`, que no se descarga en el panel de tienda. |

---

## 5. Base de datos — esquema v2 (relacional, normalizado y aislado)

Como aún no hay datos reales, se escribe un `schema.sql` nuevo (y se elimina la copia duplicada
`backend/database/schema.sql`). Reglas para **todas** las tablas:

### 5.1 Reglas generales
- **InnoDB + utf8mb4**, `DECIMAL(12,2)` para dinero, fechas `DATETIME`.
- **Toda tabla de negocio lleva `id_empresa INT NOT NULL`**, incluidas las tablas hijas
  (`venta_lineas`, `compra_pagos`, `articulo_colores`…). Hoy 11 tablas no la tienen.
- **Sin "0 mágicos"**: hoy `inventario.id_color = 0` significa "sin variante" y por eso **no puede tener
  llave foránea**. En v2 es `NULL` + FK real a `atributo_valores`. Las columnas se renombran a
  `id_valor1`/`id_valor2` (eje 1 / eje 2), porque ya no siempre son color y talla.
- **Nada de relaciones guardadas en JSON**: `users.permisos` (JSON) pasa a la tabla `user_permisos`;
  los JSON que quedan (`domicilio`, `contacto`, `ficha_schema`) son datos descriptivos sin relaciones.
- Se eliminan las tablas legacy `colores`, `tallas` y `colecciones` (reemplazadas por `atributo_valores`
  y `cortes_catalogo`).
- Borrado lógico (`is_active`) para todo lo que tenga historial; `ON DELETE RESTRICT` en las relaciones
  de negocio para que nunca se borre en cascada información contable.

### 5.2 Llaves foráneas compuestas (el candado de la BD)
Cada tabla de negocio declara `UNIQUE (id_empresa, id)`, y **toda** relación entre tablas de negocio se hace
con la pareja `(id_empresa, id_x)`. Así MySQL **impide físicamente** cruzar tiendas:

```sql
CREATE TABLE clientes (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  id_empresa  INT NOT NULL,
  ...
  UNIQUE KEY uq_cli_emp_id (id_empresa, id),
  CONSTRAINT fk_cli_emp FOREIGN KEY (id_empresa) REFERENCES empresas(id)
);

CREATE TABLE ventas (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  id_empresa  INT NOT NULL,
  folio       VARCHAR(40) NOT NULL,    -- serie + consecutivo, tomado de folio_series
  id_almacen  INT NOT NULL,
  id_cliente  INT NULL,
  ...
  UNIQUE KEY uq_venta_emp_id (id_empresa, id),
  UNIQUE KEY uq_venta_folio  (id_empresa, folio),
  KEY idx_venta_fecha        (id_empresa, fecha),
  CONSTRAINT fk_venta_alm FOREIGN KEY (id_empresa, id_almacen) REFERENCES almacenes(id_empresa, id),
  CONSTRAINT fk_venta_cli FOREIGN KEY (id_empresa, id_cliente) REFERENCES clientes(id_empresa, id)
);

CREATE TABLE venta_lineas (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  id_empresa  INT NOT NULL,
  id_venta    INT NOT NULL,
  id_articulo INT NOT NULL,
  id_valor1   INT NULL,   -- eje 1 de variante (antes id_color)
  id_valor2   INT NULL,   -- eje 2 de variante (antes id_talla)
  ...
  CONSTRAINT fk_vl_venta FOREIGN KEY (id_empresa, id_venta)    REFERENCES ventas(id_empresa, id),
  CONSTRAINT fk_vl_art   FOREIGN KEY (id_empresa, id_articulo) REFERENCES articulos(id_empresa, id),
  CONSTRAINT fk_vl_v1    FOREIGN KEY (id_empresa, id_valor1)   REFERENCES atributo_valores(id_empresa, id),
  CONSTRAINT fk_vl_v2    FOREIGN KEY (id_empresa, id_valor2)   REFERENCES atributo_valores(id_empresa, id)
);
-- Si el código intentara guardar una venta de la Tienda 1 con un cliente de la Tienda 2,
-- MySQL responde: "Cannot add or update a child row: a foreign key constraint fails".
```

> **Nota de normalización:** en las tablas hijas, `id_empresa` se podría deducir del padre, pero aquí **forma
> parte de la llave** que garantiza el aislamiento. Es el patrón estándar en sistemas multi-tienda y no
> genera inconsistencias, porque la FK compuesta obliga a que coincida con el del padre.

Se aplica igual a **todas** las relaciones: inventario → almacén/artículo/valores, compras → proveedor,
traspasos → almacén origen y destino, CxC → cliente/venta, CxP → proveedor/compra, pagos → banco,
comisiones → empleado/venta, apartados → cliente, devoluciones → venta, cortes → almacén/usuario,
artículo → categoría/marca/familia/línea, categoría → atributos de sus ejes, usuario → almacén por defecto, etc.

**Inventario sin variante:** como `NULL` no cuenta en llaves únicas de MySQL, la llave única de existencias
usa columnas generadas: `UNIQUE (id_empresa, id_almacen, id_articulo, v1_key, v2_key)` con
`v1_key = IFNULL(id_valor1, 0)` (columna `GENERATED ALWAYS … STORED`). Así no puede haber dos filas de
existencia para la misma celda.

### 5.3 Usuarios, login y permisos
```sql
CREATE TABLE modulos (               -- catálogo FIJO del sistema (no es dato de tienda)
  clave  VARCHAR(40) PRIMARY KEY,    -- 'ventas','compras','cxc','cxp','inventario','usuarios',...
  nombre VARCHAR(80) NOT NULL
);

CREATE TABLE empresa_modulos (       -- módulos habilitados por tienda (los decide el superadmin)
  id_empresa INT NOT NULL,
  modulo     VARCHAR(40) NOT NULL,
  PRIMARY KEY (id_empresa, modulo),
  FOREIGN KEY (id_empresa) REFERENCES empresas(id),
  FOREIGN KEY (modulo)     REFERENCES modulos(clave)
);

CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  id_empresa INT NULL,                -- NULL solo para superadmin
  usuario    VARCHAR(60)  NOT NULL,   -- parte antes de la @ ('admin', 'cajero1')
  login      VARCHAR(160) NOT NULL,   -- correo de acceso completo: 'cajero1@tienda1.levotek.com'
  email_contacto VARCHAR(160) NULL,   -- correo REAL opcional (para avisos/recuperar contraseña)
  rol ENUM('superadmin','admin_tienda','usuario') NOT NULL,
  id_almacen_default INT NULL,
  token_version INT NOT NULL DEFAULT 0,
  ...
  UNIQUE KEY uq_user (id_empresa, usuario),
  UNIQUE KEY uq_user_login (login),       -- único global (el dominio ya distingue la tienda)
  UNIQUE KEY uq_user_emp_id (id_empresa, id),
  CONSTRAINT fk_user_alm FOREIGN KEY (id_empresa, id_almacen_default) REFERENCES almacenes(id_empresa, id),
  CONSTRAINT chk_superadmin CHECK ((rol = 'superadmin') = (id_empresa IS NULL))
);

CREATE TABLE user_permisos (         -- reemplaza el JSON users.permisos
  id_empresa INT NOT NULL,
  id_user    INT NOT NULL,
  modulo     VARCHAR(40) NOT NULL,
  accion     VARCHAR(20) NOT NULL,        -- FK a modulo_acciones (acciones validas de cada modulo)
  PRIMARY KEY (id_user, modulo, accion),
  FOREIGN KEY (id_empresa, id_user) REFERENCES users(id_empresa, id),
  FOREIGN KEY (id_empresa, modulo)  REFERENCES empresa_modulos(id_empresa, modulo)
  -- ↑ la BD impide dar a un usuario un módulo que su tienda no tiene habilitado
);
```
- Los permisos pasan de "por módulo" a **por acción** (ver/crear/editar/eliminar/aprobar),
  que era deuda técnica conocida.
- **Login = correo + contraseña (igual que hoy).** El correo de acceso **lleva la tienda en el dominio**:

  | Quién | Correo de acceso | Lo crea |
  |---|---|---|
  | Superadmin | `admin@levotek.com` | Instalador |
  | Admin de la Tienda 1 | `admin@tienda1.levotek.com` | Superadmin, al crear la tienda |
  | Cajero de la Tienda 1 | `cajero1@tienda1.levotek.com` | Admin de la Tienda 1 |
  | Admin de la Tienda 2 | `admin@tienda2.levotek.com` | Superadmin |

  - Al crear la tienda, el superadmin define su **subdominio de acceso** (`empresas.slug`, p. ej. `tienda1`).
    Al dar de alta un usuario solo se escribe la parte antes de la @ (`cajero1`); el sistema completa
    `@tienda1.levotek.com` automáticamente y **no se puede cambiar**. El admin de una tienda no puede crear
    usuarios con el dominio de otra.
  - Al iniciar sesión, el servidor separa el correo: `tienda1` → busca la tienda → busca `cajero1` **solo
    dentro de esa tienda**. Sin dominio de tienda (`admin@levotek.com`) → solo puede ser superadmin.
  - **Son identificadores de acceso, no buzones.** No hace falta crear los correos, ni registros DNS, ni
    subdominios en GoDaddy: todo sigue en el mismo dominio y hosting. Por eso existe `email_contacto`
    (opcional) para un correo real si se quiere recuperar contraseña por email; si no, la contraseña
    la restablece el admin de la tienda o el superadmin.
  - El dominio base (`levotek.com`) va en `config.php` (`login_domain`), para poder cambiarlo sin tocar código.
  - El `slug` de una tienda **no se puede editar** después de creada (cambiaría el correo de todos sus usuarios).
- **Recomendación:** usar slugs con el nombre del negocio (`zapateriacentro`, `papeleriaroma`) en lugar de
  `tienda1`, `tienda2`. Con números consecutivos, un usuario de `tienda1` puede adivinar que existe `tienda2`.
- Se mantiene la protección básica, que no cambia nada para el usuario: cualquier fallo responde
  **"Correo o contraseña incorrectos"** (no dice si la tienda o el usuario existen) y hay bloqueo temporal
  tras 5 intentos fallidos por IP/correo (tabla `login_intentos`).
- `audit_log` agrega `act_as INT NULL` (superadmin operando como tienda).
- **CHECK de MySQL:** se aplica desde MySQL 8.0.16. Si el servidor de GoDaddy es más viejo o MariaDB,
  se valida en `Tenant`/`AuthController` (verificar versión en F1).

### 5.4 `empresas` (tiendas)
`slug` (único, no editable; es el subdominio del correo de acceso), `nombre`, `rfc`, `iva`, `logo`, `telefono`, `direccion`, `uploads_token`
(carpeta aleatoria de fotos), `id_salt` (sal para ids opacos), `max_usuarios`, `max_almacenes`,
`is_active`, `notas` y configuración (PIN de listas altas, series de folio).

### 5.5 Saldos
`clientes.saldo_credito`, `clientes.saldo_favor`, `bancos.saldo` y el saldo de proveedores son **acumulados**
de sus tablas de movimientos (`cliente_movimientos`, `monedero_movimientos`, `proveedor_movimientos`).
Se conservan por rendimiento, pero:
- solo se modifican a través de `Ledger` (nunca con `UPDATE` sueltos), dentro de la misma transacción
  que el movimiento;
- se agrega una verificación (`GET /plataforma/tiendas/:id/conciliar`, y también en las pruebas) que
  recalcula los saldos desde los movimientos y avisa si alguno no cuadra.

### 5.6 Índices
Todos los índices de consulta **empiezan por `id_empresa`**: `ventas (id_empresa, fecha)`,
`inventario_movimientos (id_empresa, id_articulo, fecha)`, `cliente_movimientos (id_empresa, id_cliente, fecha)`,
`compras (id_empresa, fecha)`, `audit_log (id_empresa, created_at)`, etc. Esto además mantiene rápidas
las consultas cuando haya muchas tiendas.

---

## 6. Backend — API de plataforma

Prefijo `/api/v1/plataforma/*`, solo `rol = superadmin` (nuevo `PlataformaController`):

| Endpoint | Qué hace |
|---|---|
| `GET/POST /plataforma/tiendas`, `GET/PUT /plataforma/tiendas/:id` | Alta/edición de tiendas. Al crear (en **una sola transacción**): valida el `slug`, crea la tienda, sus módulos, su primer `admin_tienda` (`admin@<slug>.levotek.com` con contraseña temporal) y **siembra** almacén principal, cliente "Público General", atributos/categorías base y banco "Caja". El `slug` no se puede editar después. |
| `PATCH /plataforma/tiendas/:id/estado` | Suspender / reactivar. |
| `GET/PUT /plataforma/tiendas/:id/modulos` | Módulos habilitados de la tienda. |
| `GET/POST /plataforma/usuarios`, `PUT/DELETE /plataforma/usuarios/:id`, `POST …/:id/reset-password` | Usuarios de cualquier tienda, con sus permisos (validados contra los módulos de la tienda). |
| `POST /plataforma/tiendas/:id/entrar` | Token de soporte (sección 4.6). |
| `GET /plataforma/dashboard` | Ventas del día/mes por tienda, tiendas activas, usuarios conectados. |
| `GET /plataforma/audit-log` | Bitácora global, filtrable por tienda. |
| `GET /plataforma/tiendas/:id/export` | Respaldo de una tienda (JSON/SQL de sus filas). |
| `GET /plataforma/tiendas/:id/conciliar` | Recalcula saldos (CxC, monedero, CxP, bancos) desde los movimientos y reporta diferencias (sección 5.5). |

Las rutas actuales `/auth/usuarios*` se **conservan para la tienda**, restringidas a `admin_tienda`
y con las reglas de la sección 4.7 (`AuthController` reutiliza las mismas validaciones que
`PlataformaController`, pero con el `id_empresa` fijo del token). Un `usuario` normal recibe 403.
Nuevo `GET /auth/modulos-tienda`: lista los módulos habilitados de la tienda para armar el editor de permisos.

---

## 7. Frontend — dos paneles en el mismo SPA

Mismo build, mismo dominio; se separa por **ruta y layout**:

```
/login                → login único: correo de acceso + contraseña (el dominio del correo identifica la tienda)
/admin/*              → Panel de administración general (solo superadmin)
    /admin              Dashboard global
    /admin/tiendas      Lista + alta/edición + módulos + suspender
    /admin/usuarios     Usuarios de todas las tiendas, permisos por módulo y acción
    /admin/bitacora     Bitácora global
/*                    → Panel de tienda (lo que ya existe hoy)
```

- `LoginPage`: un campo **Correo** y uno **Contraseña** (como hoy), con ejemplo `usuario@tutienda.levotek.com`.
- Tras el login: superadmin → `/admin`; usuario de tienda → `/`. Un superadmin no puede abrir `/`
  salvo con "entrar como tienda"; un usuario de tienda que abra `/admin` va a "No encontrado".
- Primer acceso con contraseña temporal → obliga a cambiarla.
- `AdminLayout` propio (otro color de barra para que sea evidente en qué panel estás).
- El panel de admin se carga con `import()` dinámico: los usuarios de tienda no descargan ese código
  (y ayuda con el bundle de 1.4 MB que ya teníamos).
- En el panel de tienda: el Sidebar se arma con los **permisos efectivos** de `/auth/me`; el Header
  muestra el nombre/logo de la tienda.
- **"Usuarios" en el panel de tienda** (la `UsuariosPage` actual, adaptada): visible solo para `admin_tienda`.
  Lista sus usuarios, alta/edición, activar/desactivar, reset de contraseña y editor de permisos que
  **solo muestra los módulos habilitados de la tienda**. No muestra selector de tienda ni de rol superior.

---

## 8. Flujos de punta a punta

### 8.1 Alta de una tienda (superadmin)
1. Entra con `admin@levotek.com` → `/admin/tiendas` → **Nueva tienda**.
2. Captura nombre, RFC, IVA, **subdominio** (`zapateriacentro`; el sistema avisa si ya existe o si no es válido:
   solo minúsculas, números y guiones) y marca los **módulos** que tendrá.
3. El servidor, en una transacción, crea la tienda, sus módulos, el usuario `admin@zapateriacentro.levotek.com`
   con contraseña temporal y la semilla (almacén principal, Público General, catálogos base, caja).
4. El superadmin entrega al dueño el correo y la contraseña temporal.

### 8.2 La tienda da de alta a su personal (admin de tienda)
1. El dueño entra con `admin@zapateriacentro.levotek.com`; el sistema le pide cambiar la contraseña.
2. Va a **Usuarios → Nuevo**, escribe `cajero1` (ve el dominio fijo `@zapateriacentro.levotek.com`),
   nombre, almacén por defecto y marca permisos (solo aparecen los módulos de su tienda).
3. El cajero entra con `cajero1@zapateriacentro.levotek.com` y solo ve los menús que le dieron.

### 8.3 Inicio de sesión (servidor)
1. Recibe `cajero1@zapateriacentro.levotek.com` + contraseña. Revisa bloqueo por intentos.
2. Separa: usuario `cajero1`, subdominio `zapateriacentro`, dominio `levotek.com` (si el dominio no es
   `login_domain` → error genérico).
3. Busca la tienda por `slug` → busca `cajero1` **dentro de esa tienda** → verifica contraseña, que el usuario
   y la tienda estén activos.
4. Emite JWT con `id_user`, `id_empresa`, `rol`, `token_version`. Cualquier fallo: "Correo o contraseña incorrectos".

### 8.4 Una operación cualquiera (ej. venta en POS)
1. El front manda ids opacos (`_id`) de almacén, cliente, artículos y variantes.
2. `index.php` valida JWT, `token_version`, tienda activa y permiso efectivo `ventas.crear`.
3. `VentaController` decodifica cada id con la sal de **su** tienda (`Tenant`); si alguno no es de la tienda → 404.
4. Inserta venta, líneas, pagos, movimientos de inventario, CxC/monedero y comisión con `id_empresa` de la tienda;
   las FKs compuestas garantizan que todo pertenece a la misma tienda. El folio es el siguiente **de esa tienda**.

### 8.5 Suspensión de una tienda
El superadmin la marca inactiva → en la siguiente petición sus usuarios reciben "Cuenta suspendida, contacte
al administrador" y no pueden entrar. Sus datos se conservan intactos; al reactivarla todo sigue igual.

---

## 9. Fases de trabajo

| Fase | Contenido | Esfuerzo aprox. |
|---|---|---|
| **F0** ✅ | Copia del proyecto, BD `orquestadormultiendas` en XAMPP (3307), repo git, secretos fuera de `config.php`, scripts `iniciar.bat`/`detener.bat`, `reset-db.php` bloqueado fuera de localhost. *Pendiente:* rebrand de textos visibles (falta definir el nombre comercial). | 0.5 día |
| **F1** ✅ | **Esquema v2** completo (sección 5): `id_empresa` en todas las tablas, FKs compuestas, variantes con `NULL`+FK, folios por tienda, `modulos`/`empresa_modulos`/`user_permisos`, índices. Instalador y semilla (superadmin + 2 tiendas demo). | 2–3 días |
| **F2** ✅ | `lib/Tenant.php`, helpers `Db::*T`, ids opacos por tienda, login con correo `usuario@<tienda>.levotek.com`, bloqueo por intentos, tienda suspendida, permiso efectivo por acción, `token_version`. | 2 días |
| **F3** ✅ | Adaptar los 21 controladores al esquema v2 (columnas renombradas, `id_empresa` en hijas, `Tenant::owns` en cada id de entrada, 404 en vez de 403) + **pruebas de aislamiento A/B** (API y BD directa) + conciliación de saldos. | 4–5 días |
| **F4** ✅ | `PlataformaController` (tiendas, módulos, usuarios, siembra, entrar-como, dashboard, export). | 2 días |
| **F5** ✅ | Frontend panel de administración (`/admin`). | 2–3 días |
| **F6** ✅ | Ajustes del panel de tienda (menú por permisos efectivos, branding por tienda, uploads por tienda). | 1 día |
| **F6b** ✅ | Gestión de usuarios por la tienda (`admin_tienda`): reglas de la sección 4.7 en `AuthController`, página Usuarios adaptada, y casos extra en las pruebas de aislamiento (admin de A intentando crear/editar usuarios de B, dar módulos no habilitados, escalar a `admin_tienda`). | 1.5 días |
| **F7** ✅ | Despliegue en GoDaddy + checklist (borrar `install.php`/`reset-db.php`, secretos fuera del repo, cambiar contraseña superadmin). | 0.5 día |

### Decisiones tomadas al implementar F1 (difieren del borrador)
- **Acciones de permiso:** en lugar de un `ENUM` fijo, tabla `modulo_acciones` (cada módulo define sus acciones:
  p. ej. `ventas.cancelar`, `compras.aprobar`, `clientes.autorizar_credito`) y `user_permisos` la referencia por FK.
- **Folios:** `folio VARCHAR` con `UNIQUE (id_empresa, folio)` + tabla `folio_series` (consecutivo por tienda/tipo/serie
  tomado con `SELECT … FOR UPDATE`), en vez de columnas `serie` + `folio INT`. Elimina el `COUNT(*)+1` que tenía carreras.
- **Variantes del artículo:** `articulo_colores`/`articulo_tallas` → una sola tabla `articulo_eje_valores (eje 1/2)`.
- `folio_venta` copiado en devoluciones/cambios/comisiones se eliminó (dato derivado; se obtiene por JOIN).
- `empresa_modulos.activo`: deshabilitar un módulo no borra los permisos; solo dejan de aplicar.
- Verificado en MariaDB 10.4 (XAMPP): 49 tablas, 128 FKs, CHECKs activos. `backend/tests/esquema_test.php`: 53/53.

**Estado (2026-10-06): F0–F7 completas.** Total estimado original: 16–20 días de trabajo. F1–F3 no se deben recortar: son las que garantizan que una
tienda nunca vea, use ni deduzca datos de otra.

---

### Entregables y criterio de terminado por fase

**F0 — Preparación**
- Repo git propio, BD `orquestadormultiendas` en XAMPP, `login_domain = levotek.com` en `config.php`.
- Rebrand de textos de MultiTienda/LEVOTEK al nombre final; secretos fuera del repo (`config.php` lee de
  variables de entorno o de `config.local.php` ignorado).
- ✔ Terminado cuando: el proyecto arranca en local apuntando a la BD nueva.

**F1 — Esquema v2**
- `backend/api/schema.sql` reescrito (47 → ~45 tablas: se eliminan `colores`, `tallas`, `colecciones`; se agregan
  `modulos`, `empresa_modulos`, `user_permisos`, `login_intentos`).
- `install.php` nuevo: crea esquema, catálogo de módulos, superadmin `admin@levotek.com` y 2 tiendas demo
  (`demo1`, `demo2`) con su admin, cajero y datos de ejemplo.
- ✔ Terminado cuando: el instalador corre limpio y un `INSERT` manual que cruce tiendas es rechazado por MySQL.

**F2 — Núcleo de seguridad**
- Nuevos: `lib/Tenant.php` (tienda del token, `owns`, ids opacos), `lib/LoginId.php` (armar/separar
  `usuario@slug.levotek.com`), helpers `Db::oneT/allT/runT`.
- `index.php`: validación de `token_version`, tienda activa, permiso efectivo por acción, rutas `/plataforma/*`
  solo superadmin. `AuthController::login` con el nuevo formato y bloqueo por intentos.
- ✔ Terminado cuando: login funciona para los 3 roles y las pruebas de login (correo de otra tienda, dominio
  ajeno, 6 intentos fallidos, tienda suspendida) pasan.

**F3 — Controladores + pruebas de aislamiento**
- Los 21 controladores adaptados: columnas `id_valor1/2`, `id_empresa` en tablas hijas, `Tenant::owns` en todo
  id de entrada, ids opacos en respuestas, folios por tienda.
- `backend/tests/aislamiento.php`: batería A/B de la sección 4.5 + conciliación de saldos.
- ✔ Terminado cuando: 0 fallos en la batería y las pruebas funcionales que ya existían (ventas, compras,
  traspasos, apartados, devoluciones, cortes, reportes) siguen pasando por tienda.

**F4 — API de plataforma**
- `PlataformaController` con todos los endpoints de la sección 6 (alta de tienda transaccional con su admin).
- ✔ Terminado cuando: se crea una tienda desde la API y su admin puede entrar de inmediato.

**F5 — Panel de administración (frontend)**
- `AdminLayout` + páginas `/admin` (Dashboard, Tiendas, Usuarios, Bitácora), carga diferida con `import()`.
- ✔ Terminado cuando: el flujo 8.1 se puede hacer completo desde la pantalla.

**F6 / F6b — Panel de tienda**
- Menú por permisos efectivos, nombre/logo de la tienda, fotos en carpeta aleatoria por tienda, limpieza de
  sesión al salir, página Usuarios para `admin_tienda` con dominio fijo y editor de permisos por acción.
- ✔ Terminado cuando: el flujo 8.2 funciona y las pruebas de abuso del admin de tienda pasan.

**F7 — Despliegue**
- Build, subida a GoDaddy, instalador, borrar `install.php`/`reset-db.php`, verificar versión de MySQL
  (para los `CHECK`), cambiar contraseña del superadmin, correr la batería de aislamiento contra producción
  con las tiendas demo y luego eliminarlas.

---

## 10. Pendientes heredados a resolver aquí
- Sacar credenciales del repo (`config.php` con valores por defecto, `config.local.php`).
- `reset-db.php` sin autenticación: no desplegar, o exigir token de superadmin.
- Unificar las dos copias de `schema.sql`.
- Pruebas automatizadas (las de aislamiento de F3 son el primer paso).
