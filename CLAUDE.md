# CLAUDE.md — Orquestador MultiTiendas

Contexto para Claude Code. Léelo completo antes de tocar código. El diseño detallado está en
[PLAN-ORQUESTADOR.md](PLAN-ORQUESTADOR.md); este archivo resume lo que no se puede olvidar.

## Qué es
Plataforma multi-tienda (multi-tenant) de POS/ERP: **un proyecto, un dominio, un hosting (GoDaddy compartido),
una sola BD**. Fork de MultiTienda (a su vez de LEVOTEK). Dos paneles en el mismo SPA:
- `/admin` → **superadmin** (dueño de la plataforma): crea tiendas, habilita módulos, administra usuarios.
- `/` → **panel de tienda**: cada tienda trabaja solo con lo suyo.

**Idioma:** el usuario escribe en español; responde y documenta en español.

## Reglas NO negociables (aislamiento entre tiendas)
1. **"Tienda" = fila de `empresas`.** Toda tabla de negocio tiene `id_empresa NOT NULL`. Las únicas tablas sin
   `id_empresa` son de sistema: `empresas`, `modulos`, `modulo_acciones`, `login_intentos`.
2. **Ninguna tienda puede ver, usar ni deducir datos de otra**, ni saber que existen otras tiendas
   (ni por ids, folios, mensajes de error, URLs de fotos o pantallas).
3. **El `id_empresa` sale SIEMPRE del token (JWT)**, nunca del body/query. Si el body lo trae, se ignora.
4. **Toda consulta** (`SELECT/UPDATE/DELETE`, incluidas tablas hijas) filtra `WHERE id_empresa = ?`.
5. **Todo id que llega del front** (cliente, almacén, artículo, variante, proveedor, banco, empleado, venta…)
   se valida contra la tienda del token. Si no es suyo → **404 "No encontrado"** (igual que si no existiera;
   nunca 403, que confirmaría que existe).
6. **Esquema:** toda relación entre tablas de negocio es FK compuesta `(id_empresa, id_x) → padre(id_empresa, id)`.
   Cada tabla de negocio declara `UNIQUE (id_empresa, id)`. Así MySQL rechaza cruces aunque el código falle.
7. FKs de negocio: `ON DELETE RESTRICT` (borrado lógico con `is_active`); solo el detalle de un documento
   usa `CASCADE`. **Nunca `SET NULL`** en FK compuesta (pondría `id_empresa` en NULL).
8. Cualquier cambio al esquema: actualizar `backend/api/schema.sql` y correr `php backend/tests/esquema_test.php`
   (debe dar 100 %). Agregar casos de prueba para tablas/relaciones nuevas.
9. **Nunca** commitear secretos: `lib/config.local.php`, `frontend/.env`, `*.local.txt` están en `.gitignore`.

## Roles, permisos y login
- Roles: `superadmin` (`id_empresa` NULL), `admin_tienda`, `usuario`. CHECK en BD: superadmin ⇔ sin tienda.
- **Permiso efectivo** = `user_permisos` (módulo + acción) ∩ `empresa_modulos.activo = 1`.
  `admin_tienda` tiene implícitos todos los módulos activos de su tienda. Superadmin pasa todo en `/plataforma/*`.
- Módulos y acciones válidas: tablas `modulos` y `modulo_acciones` (datos al final de `schema.sql`).
  FK `user_permisos(id_empresa, modulo) → empresa_modulos`: la BD impide dar un módulo que la tienda no tiene.
- **Login = correo de acceso + contraseña.** `usuario@<slug>.levotek.com` (tienda) o `usuario@levotek.com`
  (superadmin). Helper: `lib/LoginId.php` (`armar`, `separar`, `usuario`, `slug`). Dominio en `config['login_domain']`.
  - `users.usuario` = parte antes de la @ (única por tienda); `users.login` = correo completo (único global).
  - El `admin_tienda` solo escribe la parte antes de la @; el dominio lo pone el servidor.
  - `empresas.slug` **no se puede editar** después de crear la tienda.
  - Error de login siempre genérico: "Correo o contraseña incorrectos". Bloqueo tras 5 intentos (`login_intentos`).
  - `debe_cambiar_password = 1` en usuarios nuevos; `token_version` +1 al cambiar permisos/desactivar.
- `admin_tienda` puede crear/editar usuarios **rol `usuario`** de su tienda con módulos de su tienda; no puede
  crear `admin_tienda`/`superadmin`, ni tocar su propio rol/permisos, ni módulos de la tienda.
- Superadmin "entrando como tienda" (soporte): token temporal con `id_empresa` de la tienda; los registros que
  cree guardan `id_usuario = NULL` y la bitácora lleva `audit_log.act_as = id del superadmin`.

## Estado del proyecto (actualizar al cerrar cada fase)
| Fase | Estado |
|---|---|
| F0 Preparación (repo, BD propia, secretos fuera de `config.php`, scripts locales) | ✅ |
| F1 Esquema v2 + `install.php` + `Seeder` + pruebas de BD (53/53) | ✅ |
| **F2 Núcleo de seguridad** | ⏳ **siguiente** |
| F3 Adaptar los 21 controladores + pruebas de aislamiento por API | ⏳ |
| F4 API de plataforma · F5 panel `/admin` · F6/F6b panel de tienda · F7 despliegue | ⏳ |

⚠️ **Hoy la API no funciona contra la BD v2**: los controladores (`backend/api/lib/controllers/`) e
`index.php` siguen escritos para el esquema de MultiTienda. Eso se arregla en F2 + F3.

### Qué hace F2 (siguiente tarea)
- `lib/Tenant.php`: `Tenant::id($ctx)`, `Tenant::owns($tabla, $id)` (lanza 404), ids opacos por tienda
  (codificar/decodificar `_id` con `empresas.id_salt`, estilo Hashids).
- Helpers `Db::oneT / allT / runT` que exigen `id_empresa`.
- `AuthController::login` con `LoginId::separar` + `login_intentos` + validar tienda y usuario activos;
  JWT con `id_user`, `id_empresa`, `rol`, `token_version`. `/auth/me` con permisos efectivos.
- `index.php`: verificar `token_version` y tienda activa en cada petición; mapa ruta → (módulo, acción);
  `/plataforma/*` solo superadmin; usuarios de tienda nunca llegan ahí (404).
- Pruebas en `backend/tests/` (login por dominio, intentos, tienda suspendida, permisos).

### Mapa de cambios del esquema (lo que F3 debe adaptar en los controladores)
| Antes (MultiTienda) | Ahora (v2) |
|---|---|
| `users.email` | `users.usuario` + `users.login` |
| `users.permisos` (JSON) | tabla `user_permisos` (módulo, acción) |
| `users.rol` `admin` | `superadmin` / `admin_tienda` / `usuario` |
| `users.id_tienda` | `users.id_almacen_default` |
| `clientes.id_tienda`, `empleados.id_tienda` | `clientes.id_almacen`, `empleados.id_almacen` |
| `id_color` / `id_talla` con `0` = sin eje | `id_valor1` / `id_valor2` con `NULL` (inventario, kardex y todas las `*_lineas`) |
| `articulo_colores` / `articulo_tallas` | `articulo_eje_valores (id_articulo, eje 1/2, id_valor)` |
| `articulo_variante_codigos.id_eje1/2` | `id_valor1/2` |
| tablas `colores`, `tallas`, `colecciones`; `articulos.id_coleccion` | eliminadas (usar `atributos`/`atributo_valores`, `cortes_catalogo`) |
| `articulo_fotos.imgkey`, `uploads/articulos/` | `archivo`, `uploads/<empresas.uploads_token>/` |
| `inventario_movimientos.referencia_tipo` | `ref_tipo` |
| `folio_venta` en devoluciones/cambios/comisiones | eliminado (se obtiene con JOIN a `ventas`) |
| Folio = `COUNT(*)+1` | tabla `folio_series` con `SELECT … FOR UPDATE`; `UNIQUE (id_empresa, folio)` |
| Tablas hijas sin `id_empresa` | **todas** lo llevan: incluirlo en cada `INSERT` |
| `compra_pagos` sin banco | `compra_pagos.id_banco` |
| `audit_log.id_empresa` NOT NULL | NULL = acción de plataforma; columna `act_as` |

Unicidades por tienda: `inventario` usa columnas generadas `v1_key/v2_key = IFNULL(id_valorN, 0)` para que
la celda sin variante sea única. SKU, código, EAN, folios y usuarios son únicos **por tienda**.

## Entorno local (Windows)
- MariaDB **10.4** de XAMPP en **3307** (se fuerza con `--port=3307`: el `my.ini` de esta PC dice 3306).
  En **3306** corre el MySQL del sistema: **no tocarlo**.
- `iniciar.bat` / `detener.bat`: MariaDB 3307, backend PHP `php -S 127.0.0.1:8082 -t backend/api`, Vite 3001.
- Config local: `backend/api/lib/config.local.php` (copia de `config.local.example.php`). `config.php` no lleva secretos;
  `index.php` se niega a arrancar si `jwt_secret` tiene menos de 32 caracteres.
- Reinstalar BD: `php backend/api/reset-db.php --go --demo` → superadmin + tiendas `demo1` y `demo2`
  (admin + cajero1 cada una). Contraseñas aleatorias en `backend/api/credenciales.local.txt` (ignorado).
  **No muestres esas contraseñas en el chat.**
- `reset-db.php` solo funciona con `debug = true` y desde localhost/CLI; nunca se despliega.

## Comandos
```bash
php backend/api/reset-db.php --go --demo   # BD limpia con tiendas demo
php backend/tests/esquema_test.php         # aislamiento a nivel BD (debe dar 100 %)
php backend/tests/loginid_test.php         # correos de acceso
cd frontend && npm run build               # el bundle principal pesa ~1.4 MB (pendiente dividir en F5)
for f in backend/api/*.php backend/api/lib/*.php backend/api/lib/controllers/*.php; do php -l "$f"; done
```

## Convenciones
- Respuestas de la API: `{ success, data, error: {code,message}|null, message?, meta? }` (`lib/Http.php`).
- Ids expuestos como `_id` string; referencias pobladas como objetos (`id_articulo: {_id, codigo, descripcion}`).
- PHP puro sin Composer (hosting compartido). Saldos (`clientes.saldo_*`, `bancos.saldo_actual`) solo se
  modifican vía `lib/Ledger.php` dentro de la transacción del movimiento.
- Al cerrar una fase: actualizar la tabla de estado de este archivo, la de `README.md` y la de `PLAN-ORQUESTADOR.md`.
- Documentación histórica (MultiTienda/LEVOTEK) en `docs/historial/`: solo referencia, puede estar desactualizada.
