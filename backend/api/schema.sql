-- ============================================================
-- Orquestador MultiTiendas — Esquema v2 (multi-tienda aislado)
-- MySQL 8.0.16+ / MariaDB 10.4+ · InnoDB · utf8mb4
--
-- REGLAS (ver PLAN-ORQUESTADOR.md, seccion 5):
--  * "Tienda" = fila de `empresas`. Toda tabla de negocio lleva id_empresa NOT NULL.
--  * Toda tabla de negocio declara UNIQUE (id_empresa, id) y TODA relacion entre
--    tablas de negocio es una FK compuesta (id_empresa, id_x) -> padre(id_empresa, id).
--    Asi la BD rechaza fisicamente cualquier relacion entre tiendas distintas.
--  * Variantes: id_valor1 / id_valor2 -> atributo_valores. NULL = "sin ese eje".
--  * FKs de negocio: ON DELETE RESTRICT (borrado logico con is_active).
--    Solo el detalle de un documento (lineas, pagos) usa CASCADE con su encabezado.
--    Nunca SET NULL: en una FK compuesta pondria id_empresa en NULL.
--  * ref_tipo + id_referencia (kardex, ledgers) son polimorficas: sin FK, pero
--    siempre se consultan junto con id_empresa.
--  * Registros creados por el superadmin "entrando como tienda" guardan id_usuario
--    NULL (el superadmin no pertenece a ninguna tienda); queda en audit_log.act_as.
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================
-- 1. PLATAFORMA (tablas de sistema; no son datos de una tienda)
-- ============================================================

-- Tiendas (tenants)
CREATE TABLE empresas (
  id                INT AUTO_INCREMENT PRIMARY KEY,
  slug              VARCHAR(40)  NOT NULL,          -- subdominio de acceso: usuario@<slug>.levotek.com (NO editable)
  nombre            VARCHAR(150) NOT NULL,
  rfc               VARCHAR(20)  DEFAULT NULL,
  iva               DECIMAL(5,4) NOT NULL DEFAULT 0.1600,
  telefono          VARCHAR(40)  DEFAULT NULL,
  direccion         VARCHAR(255) DEFAULT NULL,
  logo_url          VARCHAR(500) DEFAULT NULL,
  uploads_token     CHAR(32)     NOT NULL,          -- carpeta aleatoria de fotos (no revela la tienda)
  id_salt           CHAR(32)     NOT NULL,          -- sal para ids opacos de la API
  max_usuarios      INT          DEFAULT NULL,      -- NULL = sin limite
  max_almacenes     INT          DEFAULT NULL,
  pin_lista_alta    VARCHAR(255) DEFAULT NULL,      -- hash del PIN para listas de precio 4/5
  pin_lista_alta_at DATETIME     DEFAULT NULL,
  notas             TEXT         DEFAULT NULL,
  is_active         ENUM('Si','No') NOT NULL DEFAULT 'Si',
  created_at        DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_emp_slug (slug),
  UNIQUE KEY uq_emp_uploads (uploads_token),
  CONSTRAINT chk_emp_slug CHECK (slug REGEXP '^[a-z0-9]([a-z0-9-]{0,38}[a-z0-9])?$'),
  CONSTRAINT chk_emp_iva  CHECK (iva >= 0 AND iva < 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Catalogo FIJO de modulos del software
CREATE TABLE modulos (
  clave   VARCHAR(40) PRIMARY KEY,
  nombre  VARCHAR(80) NOT NULL,
  orden   INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Acciones validas de cada modulo (ver, crear, editar, eliminar, aprobar, ...)
CREATE TABLE modulo_acciones (
  modulo  VARCHAR(40) NOT NULL,
  accion  VARCHAR(20) NOT NULL,
  nombre  VARCHAR(80) NOT NULL,
  PRIMARY KEY (modulo, accion),
  CONSTRAINT fk_modacc_mod FOREIGN KEY (modulo) REFERENCES modulos(clave) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Modulos habilitados por tienda (los decide SOLO el superadmin)
CREATE TABLE empresa_modulos (
  id_empresa INT NOT NULL,
  modulo     VARCHAR(40) NOT NULL,
  activo     TINYINT(1) NOT NULL DEFAULT 1,   -- 0 = deshabilitado (los permisos de usuarios se conservan pero no aplican)
  PRIMARY KEY (id_empresa, modulo),
  CONSTRAINT fk_empmod_emp FOREIGN KEY (id_empresa) REFERENCES empresas(id) ON DELETE RESTRICT,
  CONSTRAINT fk_empmod_mod FOREIGN KEY (modulo) REFERENCES modulos(clave) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Intentos de inicio de sesion (bloqueo temporal por fuerza bruta)
CREATE TABLE login_intentos (
  id         BIGINT AUTO_INCREMENT PRIMARY KEY,
  login      VARCHAR(160) NOT NULL,
  ip         VARCHAR(45)  NOT NULL,
  exito      TINYINT(1)   NOT NULL DEFAULT 0,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_li_login (login, created_at),
  KEY idx_li_ip (ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 2. ALMACENES, USUARIOS Y PERMISOS
-- ============================================================

-- Sucursales / bodegas de una tienda
CREATE TABLE almacenes (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  id_empresa    INT NOT NULL,
  codigo        VARCHAR(30)  NOT NULL,
  nombre        VARCHAR(120) NOT NULL,
  tipo          ENUM('bodega','tienda') NOT NULL DEFAULT 'tienda',
  vende_publico TINYINT(1) NOT NULL DEFAULT 1,
  serie_folio   VARCHAR(10) DEFAULT NULL,
  direccion     VARCHAR(255) DEFAULT NULL,
  telefono      VARCHAR(40)  DEFAULT NULL,
  is_active     ENUM('Si','No') NOT NULL DEFAULT 'Si',
  created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_alm_emp_id (id_empresa, id),
  UNIQUE KEY uq_alm_codigo (id_empresa, codigo),
  CONSTRAINT fk_alm_emp FOREIGN KEY (id_empresa) REFERENCES empresas(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Usuarios. superadmin: id_empresa NULL. admin_tienda / usuario: id_empresa de su tienda.
CREATE TABLE users (
  id                    INT AUTO_INCREMENT PRIMARY KEY,
  id_empresa            INT DEFAULT NULL,
  usuario               VARCHAR(60)  NOT NULL,     -- parte antes de la @ ('admin', 'cajero1')
  login                 VARCHAR(160) NOT NULL,     -- correo de acceso completo (cajero1@tienda.levotek.com)
  nombre                VARCHAR(80)  NOT NULL,
  apellido              VARCHAR(80)  NOT NULL DEFAULT '',
  email_contacto        VARCHAR(160) DEFAULT NULL, -- correo REAL opcional
  password              VARCHAR(255) NOT NULL,
  rol                   ENUM('superadmin','admin_tienda','usuario') NOT NULL DEFAULT 'usuario',
  id_almacen_default    INT DEFAULT NULL,
  debe_cambiar_password TINYINT(1) NOT NULL DEFAULT 1,
  token_version         INT NOT NULL DEFAULT 0,    -- +1 al cambiar permisos/desactivar -> invalida sesiones
  is_active             ENUM('Si','No') NOT NULL DEFAULT 'Si',
  ultimo_acceso         DATETIME DEFAULT NULL,
  created_at            DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at            DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_users_emp_id (id_empresa, id),
  UNIQUE KEY uq_users_usuario (id_empresa, usuario),
  UNIQUE KEY uq_users_login (login),
  CONSTRAINT fk_users_emp FOREIGN KEY (id_empresa) REFERENCES empresas(id) ON DELETE RESTRICT,
  CONSTRAINT fk_users_alm FOREIGN KEY (id_empresa, id_almacen_default) REFERENCES almacenes(id_empresa, id) ON DELETE RESTRICT,
  CONSTRAINT chk_users_rol CHECK ((rol = 'superadmin') = (id_empresa IS NULL)),
  CONSTRAINT chk_users_usuario CHECK (usuario REGEXP '^[a-z0-9]([a-z0-9._-]{0,58}[a-z0-9])?$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Permisos por usuario (modulo + accion). Solo modulos que la tienda tiene en empresa_modulos.
CREATE TABLE user_permisos (
  id_empresa INT NOT NULL,
  id_user    INT NOT NULL,
  modulo     VARCHAR(40) NOT NULL,
  accion     VARCHAR(20) NOT NULL,
  PRIMARY KEY (id_user, modulo, accion),
  KEY idx_uperm_emp (id_empresa, modulo),
  CONSTRAINT fk_uperm_user   FOREIGN KEY (id_empresa, id_user) REFERENCES users(id_empresa, id) ON DELETE CASCADE,
  CONSTRAINT fk_uperm_empmod FOREIGN KEY (id_empresa, modulo) REFERENCES empresa_modulos(id_empresa, modulo) ON DELETE RESTRICT,
  CONSTRAINT fk_uperm_acc    FOREIGN KEY (modulo, accion) REFERENCES modulo_acciones(modulo, accion) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Consecutivos de folio por tienda (se toman con SELECT ... FOR UPDATE dentro de la transaccion)
CREATE TABLE folio_series (
  id_empresa INT NOT NULL,
  tipo       VARCHAR(20) NOT NULL,      -- venta | compra | traspaso | apartado | devolucion | cambio | corte
  serie      VARCHAR(10) NOT NULL,
  ultimo     INT NOT NULL DEFAULT 0,
  PRIMARY KEY (id_empresa, tipo, serie),
  CONSTRAINT fk_folio_emp FOREIGN KEY (id_empresa) REFERENCES empresas(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 3. CATALOGOS (cada tienda tiene los suyos; nada se comparte)
-- ============================================================

CREATE TABLE familias (
  id INT AUTO_INCREMENT PRIMARY KEY, id_empresa INT NOT NULL,
  nombre VARCHAR(120) NOT NULL, is_active ENUM('Si','No') NOT NULL DEFAULT 'Si',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_fam_emp_id (id_empresa, id),
  CONSTRAINT fk_fam_emp FOREIGN KEY (id_empresa) REFERENCES empresas(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE lineas (
  id INT AUTO_INCREMENT PRIMARY KEY, id_empresa INT NOT NULL,
  nombre VARCHAR(120) NOT NULL, is_active ENUM('Si','No') NOT NULL DEFAULT 'Si',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_lin_emp_id (id_empresa, id),
  CONSTRAINT fk_lin_emp FOREIGN KEY (id_empresa) REFERENCES empresas(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- "Cortes" de prenda (catalogo). Distinto de `cortes` = cortes de caja.
CREATE TABLE cortes_catalogo (
  id INT AUTO_INCREMENT PRIMARY KEY, id_empresa INT NOT NULL,
  nombre VARCHAR(120) NOT NULL, is_active ENUM('Si','No') NOT NULL DEFAULT 'Si',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_cortecat_emp_id (id_empresa, id),
  CONSTRAINT fk_cortecat_emp FOREIGN KEY (id_empresa) REFERENCES empresas(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE marcas (
  id INT AUTO_INCREMENT PRIMARY KEY, id_empresa INT NOT NULL,
  nombre VARCHAR(120) NOT NULL, is_active ENUM('Si','No') NOT NULL DEFAULT 'Si',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_mar_emp_id (id_empresa, id),
  CONSTRAINT fk_mar_emp FOREIGN KEY (id_empresa) REFERENCES empresas(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE conceptos_gasto (
  id INT AUTO_INCREMENT PRIMARY KEY, id_empresa INT NOT NULL,
  nombre VARCHAR(120) NOT NULL, is_active ENUM('Si','No') NOT NULL DEFAULT 'Si',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_cg_emp_id (id_empresa, id),
  CONSTRAINT fk_cg_emp FOREIGN KEY (id_empresa) REFERENCES empresas(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Atributos de variante (Color, Talla, Numero, Material, ...)
CREATE TABLE atributos (
  id INT AUTO_INCREMENT PRIMARY KEY, id_empresa INT NOT NULL,
  nombre     VARCHAR(80) NOT NULL,
  tipo_valor ENUM('texto','color','numero') NOT NULL DEFAULT 'texto',
  orden      INT NOT NULL DEFAULT 0,
  is_active  ENUM('Si','No') NOT NULL DEFAULT 'Si',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_atr_emp_id (id_empresa, id),
  CONSTRAINT fk_atr_emp FOREIGN KEY (id_empresa) REFERENCES empresas(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Valores de cada atributo (Rojo, M, 26, Plata, ...)
CREATE TABLE atributo_valores (
  id INT AUTO_INCREMENT PRIMARY KEY, id_empresa INT NOT NULL,
  id_atributo INT NOT NULL,
  nombre      VARCHAR(80) NOT NULL,
  extra       VARCHAR(40) DEFAULT NULL,   -- hex si es color, o dato libre
  orden       INT NOT NULL DEFAULT 0,
  is_active   ENUM('Si','No') NOT NULL DEFAULT 'Si',
  created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_atrval_emp_id (id_empresa, id),
  KEY idx_atrval_atr (id_empresa, id_atributo),
  CONSTRAINT fk_atrval_atr FOREIGN KEY (id_empresa, id_atributo) REFERENCES atributos(id_empresa, id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Categorias: definen los ejes de variante y la ficha de cada tipo de producto
CREATE TABLE categorias (
  id INT AUTO_INCREMENT PRIMARY KEY, id_empresa INT NOT NULL,
  nombre           VARCHAR(120) NOT NULL,
  prefijo_sku      VARCHAR(8) DEFAULT NULL,
  id_atributo_eje1 INT DEFAULT NULL,       -- NULL = sin eje
  id_atributo_eje2 INT DEFAULT NULL,
  ficha_schema     JSON DEFAULT NULL,      -- [{key,label,tipo}] (descriptivo, sin relaciones)
  is_active  ENUM('Si','No') NOT NULL DEFAULT 'Si',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_cat_emp_id (id_empresa, id),
  CONSTRAINT fk_cat_emp  FOREIGN KEY (id_empresa) REFERENCES empresas(id) ON DELETE RESTRICT,
  CONSTRAINT fk_cat_eje1 FOREIGN KEY (id_empresa, id_atributo_eje1) REFERENCES atributos(id_empresa, id) ON DELETE RESTRICT,
  CONSTRAINT fk_cat_eje2 FOREIGN KEY (id_empresa, id_atributo_eje2) REFERENCES atributos(id_empresa, id) ON DELETE RESTRICT,
  CONSTRAINT chk_cat_ejes CHECK (id_atributo_eje2 IS NULL OR id_atributo_eje1 IS NOT NULL),
  CONSTRAINT chk_cat_ejes_dif CHECK (id_atributo_eje1 IS NULL OR id_atributo_eje2 IS NULL OR id_atributo_eje1 <> id_atributo_eje2)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 4. ARTICULOS
-- ============================================================

CREATE TABLE articulos (
  id                INT AUTO_INCREMENT PRIMARY KEY,
  id_empresa        INT NOT NULL,
  codigo            VARCHAR(60)  NOT NULL,
  sku               VARCHAR(40)  DEFAULT NULL,
  ean               VARCHAR(40)  DEFAULT NULL,
  descripcion       VARCHAR(200) NOT NULL,
  id_categoria      INT DEFAULT NULL,
  id_familia        INT DEFAULT NULL,
  id_linea          INT DEFAULT NULL,
  id_corte          INT DEFAULT NULL,
  id_marca          INT DEFAULT NULL,
  unidad            VARCHAR(20) NOT NULL DEFAULT 'pieza',
  contenido_paquete INT NOT NULL DEFAULT 1,
  es_kit            TINYINT(1) NOT NULL DEFAULT 0,
  ficha             JSON DEFAULT NULL,     -- valores de la ficha de la categoria (descriptivo)
  costo             DECIMAL(12,2) NOT NULL DEFAULT 0,
  lista1            DECIMAL(12,2) NOT NULL DEFAULT 0,
  lista2            DECIMAL(12,2) NOT NULL DEFAULT 0,
  lista3            DECIMAL(12,2) NOT NULL DEFAULT 0,
  lista4            DECIMAL(12,2) NOT NULL DEFAULT 0,
  lista5            DECIMAL(12,2) NOT NULL DEFAULT 0,
  es_oferta         TINYINT(1) NOT NULL DEFAULT 0,
  precio_oferta     DECIMAL(12,2) NOT NULL DEFAULT 0,
  piezas_por_caja   INT NOT NULL DEFAULT 1,
  is_active         ENUM('Si','No') NOT NULL DEFAULT 'Si',
  created_at        DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_art_emp_id (id_empresa, id),
  UNIQUE KEY uq_art_codigo (id_empresa, codigo),
  UNIQUE KEY uq_art_sku (id_empresa, sku),
  KEY idx_art_ean (id_empresa, ean),
  KEY idx_art_desc (id_empresa, descripcion),
  CONSTRAINT fk_art_emp   FOREIGN KEY (id_empresa) REFERENCES empresas(id) ON DELETE RESTRICT,
  CONSTRAINT fk_art_cat   FOREIGN KEY (id_empresa, id_categoria) REFERENCES categorias(id_empresa, id) ON DELETE RESTRICT,
  CONSTRAINT fk_art_fam   FOREIGN KEY (id_empresa, id_familia) REFERENCES familias(id_empresa, id) ON DELETE RESTRICT,
  CONSTRAINT fk_art_lin   FOREIGN KEY (id_empresa, id_linea) REFERENCES lineas(id_empresa, id) ON DELETE RESTRICT,
  CONSTRAINT fk_art_corte FOREIGN KEY (id_empresa, id_corte) REFERENCES cortes_catalogo(id_empresa, id) ON DELETE RESTRICT,
  CONSTRAINT fk_art_mar   FOREIGN KEY (id_empresa, id_marca) REFERENCES marcas(id_empresa, id) ON DELETE RESTRICT,
  CONSTRAINT chk_art_paquete CHECK (contenido_paquete >= 1 AND piezas_por_caja >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Valores de variante que maneja cada articulo, por eje (antes articulo_colores / articulo_tallas)
CREATE TABLE articulo_eje_valores (
  id_empresa  INT NOT NULL,
  id_articulo INT NOT NULL,
  eje         TINYINT NOT NULL,          -- 1 o 2
  id_valor    INT NOT NULL,
  PRIMARY KEY (id_articulo, eje, id_valor),
  KEY idx_aev_emp_art (id_empresa, id_articulo),
  CONSTRAINT fk_aev_art FOREIGN KEY (id_empresa, id_articulo) REFERENCES articulos(id_empresa, id) ON DELETE CASCADE,
  CONSTRAINT fk_aev_val FOREIGN KEY (id_empresa, id_valor) REFERENCES atributo_valores(id_empresa, id) ON DELETE RESTRICT,
  CONSTRAINT chk_aev_eje CHECK (eje IN (1, 2))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Componentes de un kit
CREATE TABLE articulo_componentes (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  id_empresa    INT NOT NULL,
  id_kit        INT NOT NULL,
  id_componente INT NOT NULL,
  cantidad      DECIMAL(12,2) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_comp (id_kit, id_componente),
  KEY idx_comp_emp_kit (id_empresa, id_kit),
  CONSTRAINT fk_comp_kit  FOREIGN KEY (id_empresa, id_kit) REFERENCES articulos(id_empresa, id) ON DELETE CASCADE,
  CONSTRAINT fk_comp_comp FOREIGN KEY (id_empresa, id_componente) REFERENCES articulos(id_empresa, id) ON DELETE RESTRICT,
  CONSTRAINT chk_comp CHECK (id_kit <> id_componente AND cantidad > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- SKU / codigo de barras por variante
CREATE TABLE articulo_variante_codigos (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  id_empresa    INT NOT NULL,
  id_articulo   INT NOT NULL,
  id_valor1     INT DEFAULT NULL,
  id_valor2     INT DEFAULT NULL,
  v1_key        INT AS (IFNULL(id_valor1, 0)) STORED,
  v2_key        INT AS (IFNULL(id_valor2, 0)) STORED,
  sku           VARCHAR(50) DEFAULT NULL,
  codigo_barras VARCHAR(50) DEFAULT NULL,
  UNIQUE KEY uq_avc_celda (id_empresa, id_articulo, v1_key, v2_key),
  UNIQUE KEY uq_avc_sku (id_empresa, sku),
  UNIQUE KEY uq_avc_bc (id_empresa, codigo_barras),
  CONSTRAINT fk_avc_art FOREIGN KEY (id_empresa, id_articulo) REFERENCES articulos(id_empresa, id) ON DELETE CASCADE,
  CONSTRAINT fk_avc_v1  FOREIGN KEY (id_empresa, id_valor1) REFERENCES atributo_valores(id_empresa, id) ON DELETE RESTRICT,
  CONSTRAINT fk_avc_v2  FOREIGN KEY (id_empresa, id_valor2) REFERENCES atributo_valores(id_empresa, id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Fotos (archivo en uploads/<empresas.uploads_token>/<nombre aleatorio>)
CREATE TABLE articulo_fotos (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  id_empresa  INT NOT NULL,
  id_articulo INT NOT NULL,
  archivo     VARCHAR(120) NOT NULL,
  url         VARCHAR(500) NOT NULL,
  nombre      VARCHAR(200) DEFAULT NULL,
  orden       INT NOT NULL DEFAULT 0,
  created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_foto_emp_art (id_empresa, id_articulo),
  CONSTRAINT fk_foto_art FOREIGN KEY (id_empresa, id_articulo) REFERENCES articulos(id_empresa, id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 5. PERSONAS: EMPLEADOS, CLIENTES, PROVEEDORES. BANCOS.
-- ============================================================

CREATE TABLE empleados (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  id_empresa   INT NOT NULL,
  nombre       VARCHAR(80) NOT NULL,
  apellido     VARCHAR(80) NOT NULL DEFAULT '',
  telefono     VARCHAR(40) DEFAULT NULL,
  email        VARCHAR(160) DEFAULT NULL,
  puesto       VARCHAR(80) DEFAULT NULL,
  area         VARCHAR(40) NOT NULL DEFAULT 'ventas',
  id_almacen   INT DEFAULT NULL,
  es_vendedor  TINYINT(1) NOT NULL DEFAULT 0,
  pct_comision DECIMAL(6,2) NOT NULL DEFAULT 0,
  is_active    ENUM('Si','No') NOT NULL DEFAULT 'Si',
  created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_empl_emp_id (id_empresa, id),
  CONSTRAINT fk_empl_emp FOREIGN KEY (id_empresa) REFERENCES empresas(id) ON DELETE RESTRICT,
  CONSTRAINT fk_empl_alm FOREIGN KEY (id_empresa, id_almacen) REFERENCES almacenes(id_empresa, id) ON DELETE RESTRICT,
  CONSTRAINT chk_empl_pct CHECK (pct_comision >= 0 AND pct_comision <= 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE clientes (
  id                     INT AUTO_INCREMENT PRIMARY KEY,
  id_empresa             INT NOT NULL,
  nombre                 VARCHAR(160) NOT NULL,
  telefono               VARCHAR(40)  DEFAULT NULL,
  rfc                    VARCHAR(20)  DEFAULT NULL,
  razon_social           VARCHAR(200) DEFAULT NULL,
  contacto               JSON DEFAULT NULL,      -- descriptivo
  domicilio              JSON DEFAULT NULL,      -- descriptivo
  lista_precios          INT NOT NULL DEFAULT 1,
  forma_pago             ENUM('Contado','Credito') NOT NULL DEFAULT 'Contado',
  plazo_dias             INT NOT NULL DEFAULT 0,
  limite_credito         DECIMAL(12,2) NOT NULL DEFAULT 0,
  credito_autorizado_por INT DEFAULT NULL,
  es_publico_general     TINYINT(1) NOT NULL DEFAULT 0,
  id_almacen             INT DEFAULT NULL,       -- sucursal donde se registro
  id_vendedor            INT DEFAULT NULL,
  saldo_credito          DECIMAL(14,2) NOT NULL DEFAULT 0,   -- acumulado de cliente_movimientos (solo via Ledger)
  saldo_favor            DECIMAL(14,2) NOT NULL DEFAULT 0,   -- acumulado de monedero_movimientos (solo via Ledger)
  notas                  TEXT DEFAULT NULL,
  is_active              ENUM('Si','No') NOT NULL DEFAULT 'Si',
  created_at             DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at             DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_cli_emp_id (id_empresa, id),
  KEY idx_cli_nombre (id_empresa, nombre),
  CONSTRAINT fk_cli_emp   FOREIGN KEY (id_empresa) REFERENCES empresas(id) ON DELETE RESTRICT,
  CONSTRAINT fk_cli_alm   FOREIGN KEY (id_empresa, id_almacen) REFERENCES almacenes(id_empresa, id) ON DELETE RESTRICT,
  CONSTRAINT fk_cli_vend  FOREIGN KEY (id_empresa, id_vendedor) REFERENCES empleados(id_empresa, id) ON DELETE RESTRICT,
  CONSTRAINT fk_cli_autor FOREIGN KEY (id_empresa, credito_autorizado_por) REFERENCES users(id_empresa, id) ON DELETE RESTRICT,
  CONSTRAINT chk_cli_lista CHECK (lista_precios BETWEEN 1 AND 5),
  CONSTRAINT chk_cli_credito CHECK (plazo_dias >= 0 AND limite_credito >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE proveedores (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  id_empresa  INT NOT NULL,
  nombre      VARCHAR(160) NOT NULL,
  telefono    VARCHAR(40) DEFAULT NULL,
  rfc         VARCHAR(20) DEFAULT NULL,
  contacto    VARCHAR(160) DEFAULT NULL,
  tipo        VARCHAR(30) NOT NULL DEFAULT 'Mercancia',
  domicilio   JSON DEFAULT NULL,          -- descriptivo
  notas       TEXT DEFAULT NULL,
  is_active   ENUM('Si','No') NOT NULL DEFAULT 'Si',
  created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_prov_emp_id (id_empresa, id),
  CONSTRAINT fk_prov_emp FOREIGN KEY (id_empresa) REFERENCES empresas(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE bancos (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  id_empresa   INT NOT NULL,
  nombre       VARCHAR(120) NOT NULL,
  moneda       ENUM('MXN','USD') NOT NULL DEFAULT 'MXN',
  cuenta       VARCHAR(60) DEFAULT NULL,
  clabe        VARCHAR(40) DEFAULT NULL,
  saldo_actual DECIMAL(14,2) NOT NULL DEFAULT 0,   -- acumulado de sus movimientos (solo via Ledger)
  is_active    ENUM('Si','No') NOT NULL DEFAULT 'Si',
  created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_banco_emp_id (id_empresa, id),
  CONSTRAINT fk_banco_emp FOREIGN KEY (id_empresa) REFERENCES empresas(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 6. INVENTARIO
-- ============================================================

-- Existencia por celda: almacen + articulo + variante (v1_key/v2_key = 0 cuando no hay eje)
CREATE TABLE inventario (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  id_empresa  INT NOT NULL,
  id_almacen  INT NOT NULL,
  id_articulo INT NOT NULL,
  id_valor1   INT DEFAULT NULL,
  id_valor2   INT DEFAULT NULL,
  v1_key      INT AS (IFNULL(id_valor1, 0)) STORED,
  v2_key      INT AS (IFNULL(id_valor2, 0)) STORED,
  cantidad    DECIMAL(12,2) NOT NULL DEFAULT 0,
  reservado   DECIMAL(12,2) NOT NULL DEFAULT 0,   -- apartados
  created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_inv_emp_id (id_empresa, id),
  UNIQUE KEY uq_inv_celda (id_empresa, id_almacen, id_articulo, v1_key, v2_key),
  KEY idx_inv_art (id_empresa, id_articulo),
  CONSTRAINT fk_inv_alm FOREIGN KEY (id_empresa, id_almacen) REFERENCES almacenes(id_empresa, id) ON DELETE RESTRICT,
  CONSTRAINT fk_inv_art FOREIGN KEY (id_empresa, id_articulo) REFERENCES articulos(id_empresa, id) ON DELETE RESTRICT,
  CONSTRAINT fk_inv_v1  FOREIGN KEY (id_empresa, id_valor1) REFERENCES atributo_valores(id_empresa, id) ON DELETE RESTRICT,
  CONSTRAINT fk_inv_v2  FOREIGN KEY (id_empresa, id_valor2) REFERENCES atributo_valores(id_empresa, id) ON DELETE RESTRICT,
  CONSTRAINT chk_inv_reservado CHECK (reservado >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Kardex
CREATE TABLE inventario_movimientos (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  id_empresa       INT NOT NULL,
  folio            VARCHAR(40) DEFAULT NULL,
  tipo             VARCHAR(30) NOT NULL,
  id_almacen       INT NOT NULL,
  id_articulo      INT NOT NULL,
  id_valor1        INT DEFAULT NULL,
  id_valor2        INT DEFAULT NULL,
  cantidad         DECIMAL(12,2) NOT NULL,
  saldo_resultante DECIMAL(12,2) NOT NULL DEFAULT 0,
  motivo           VARCHAR(160) DEFAULT NULL,
  ref_tipo         VARCHAR(40)  DEFAULT NULL,
  id_referencia    INT DEFAULT NULL,
  id_usuario       INT DEFAULT NULL,
  created_at       DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_mov_art_fecha (id_empresa, id_articulo, created_at),
  KEY idx_mov_alm_fecha (id_empresa, id_almacen, created_at),
  KEY idx_mov_fecha (id_empresa, created_at),
  CONSTRAINT fk_mov_alm  FOREIGN KEY (id_empresa, id_almacen) REFERENCES almacenes(id_empresa, id) ON DELETE RESTRICT,
  CONSTRAINT fk_mov_art  FOREIGN KEY (id_empresa, id_articulo) REFERENCES articulos(id_empresa, id) ON DELETE RESTRICT,
  CONSTRAINT fk_mov_v1   FOREIGN KEY (id_empresa, id_valor1) REFERENCES atributo_valores(id_empresa, id) ON DELETE RESTRICT,
  CONSTRAINT fk_mov_v2   FOREIGN KEY (id_empresa, id_valor2) REFERENCES atributo_valores(id_empresa, id) ON DELETE RESTRICT,
  CONSTRAINT fk_mov_user FOREIGN KEY (id_empresa, id_usuario) REFERENCES users(id_empresa, id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 7. VENTAS
-- ============================================================

CREATE TABLE ventas (
  id                   INT AUTO_INCREMENT PRIMARY KEY,
  id_empresa           INT NOT NULL,
  folio                VARCHAR(40) NOT NULL,
  fecha                DATETIME NOT NULL,
  id_almacen           INT NOT NULL,
  id_cliente           INT DEFAULT NULL,
  id_vendedor          INT DEFAULT NULL,
  id_usuario           INT DEFAULT NULL,
  total_prendas_lista  INT NOT NULL DEFAULT 0,
  nivel_cantidad       INT NOT NULL DEFAULT 1,
  lista_cliente        INT NOT NULL DEFAULT 1,
  subtotal             DECIMAL(12,2) NOT NULL DEFAULT 0,
  iva                  DECIMAL(12,2) NOT NULL DEFAULT 0,
  total                DECIMAL(12,2) NOT NULL DEFAULT 0,
  total_pagado         DECIMAL(12,2) NOT NULL DEFAULT 0,
  a_credito            TINYINT(1) NOT NULL DEFAULT 0,
  monto_credito        DECIMAL(12,2) NOT NULL DEFAULT 0,
  saldo_favor_usado    DECIMAL(12,2) NOT NULL DEFAULT 0,
  saldo_favor_generado DECIMAL(12,2) NOT NULL DEFAULT 0,
  cambio_efectivo      DECIMAL(12,2) NOT NULL DEFAULT 0,
  estado               ENUM('completada','cancelada') NOT NULL DEFAULT 'completada',
  cancelada_por        INT DEFAULT NULL,
  fecha_cancelacion    DATETIME DEFAULT NULL,
  notas                TEXT DEFAULT NULL,
  created_at           DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at           DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_venta_emp_id (id_empresa, id),
  UNIQUE KEY uq_venta_folio (id_empresa, folio),
  KEY idx_venta_fecha (id_empresa, fecha),
  KEY idx_venta_alm_fecha (id_empresa, id_almacen, fecha),
  KEY idx_venta_cli (id_empresa, id_cliente),
  CONSTRAINT fk_venta_emp     FOREIGN KEY (id_empresa) REFERENCES empresas(id) ON DELETE RESTRICT,
  CONSTRAINT fk_venta_alm     FOREIGN KEY (id_empresa, id_almacen) REFERENCES almacenes(id_empresa, id) ON DELETE RESTRICT,
  CONSTRAINT fk_venta_cli     FOREIGN KEY (id_empresa, id_cliente) REFERENCES clientes(id_empresa, id) ON DELETE RESTRICT,
  CONSTRAINT fk_venta_vend    FOREIGN KEY (id_empresa, id_vendedor) REFERENCES empleados(id_empresa, id) ON DELETE RESTRICT,
  CONSTRAINT fk_venta_user    FOREIGN KEY (id_empresa, id_usuario) REFERENCES users(id_empresa, id) ON DELETE RESTRICT,
  CONSTRAINT fk_venta_cancela FOREIGN KEY (id_empresa, cancelada_por) REFERENCES users(id_empresa, id) ON DELETE RESTRICT,
  CONSTRAINT chk_venta_lista CHECK (lista_cliente BETWEEN 1 AND 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE venta_lineas (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  id_empresa      INT NOT NULL,
  id_venta        INT NOT NULL,
  id_articulo     INT NOT NULL,
  id_valor1       INT DEFAULT NULL,
  id_valor2       INT DEFAULT NULL,
  codigo          VARCHAR(60)  DEFAULT NULL,   -- copia historica al momento de la venta
  descripcion     VARCHAR(200) DEFAULT NULL,   -- copia historica al momento de la venta
  cantidad        DECIMAL(12,2) NOT NULL DEFAULT 0,
  precio_unitario DECIMAL(12,2) NOT NULL DEFAULT 0,
  costo_unitario  DECIMAL(12,2) NOT NULL DEFAULT 0,
  lista_aplicada  VARCHAR(10) DEFAULT NULL,
  comisiona       TINYINT(1) NOT NULL DEFAULT 0,
  importe         DECIMAL(12,2) NOT NULL DEFAULT 0,
  KEY idx_vl_venta (id_empresa, id_venta),
  KEY idx_vl_art (id_empresa, id_articulo),
  CONSTRAINT fk_vl_venta FOREIGN KEY (id_empresa, id_venta) REFERENCES ventas(id_empresa, id) ON DELETE CASCADE,
  CONSTRAINT fk_vl_art   FOREIGN KEY (id_empresa, id_articulo) REFERENCES articulos(id_empresa, id) ON DELETE RESTRICT,
  CONSTRAINT fk_vl_v1    FOREIGN KEY (id_empresa, id_valor1) REFERENCES atributo_valores(id_empresa, id) ON DELETE RESTRICT,
  CONSTRAINT fk_vl_v2    FOREIGN KEY (id_empresa, id_valor2) REFERENCES atributo_valores(id_empresa, id) ON DELETE RESTRICT,
  CONSTRAINT chk_vl_cant CHECK (cantidad > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE venta_pagos (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  id_empresa INT NOT NULL,
  id_venta   INT NOT NULL,
  forma      VARCHAR(20) NOT NULL,
  importe    DECIMAL(12,2) NOT NULL DEFAULT 0,
  id_banco   INT DEFAULT NULL,
  referencia VARCHAR(80) DEFAULT NULL,
  KEY idx_vp_venta (id_empresa, id_venta),
  CONSTRAINT fk_vp_venta FOREIGN KEY (id_empresa, id_venta) REFERENCES ventas(id_empresa, id) ON DELETE CASCADE,
  CONSTRAINT fk_vp_banco FOREIGN KEY (id_empresa, id_banco) REFERENCES bancos(id_empresa, id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 8. COMPRAS Y TRASPASOS
-- ============================================================

CREATE TABLE compras (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  id_empresa       INT NOT NULL,
  folio            VARCHAR(40) NOT NULL,
  fecha            DATETIME NOT NULL,
  id_proveedor     INT NOT NULL,
  id_almacen       INT NOT NULL,
  id_usuario       INT DEFAULT NULL,
  aplica_iva       TINYINT(1) NOT NULL DEFAULT 0,
  subtotal         DECIMAL(12,2) NOT NULL DEFAULT 0,
  iva              DECIMAL(12,2) NOT NULL DEFAULT 0,
  total            DECIMAL(12,2) NOT NULL DEFAULT 0,
  total_pagado     DECIMAL(12,2) NOT NULL DEFAULT 0,
  estado           ENUM('por_aprobar','aprobada','cancelada') NOT NULL DEFAULT 'por_aprobar',
  aprobada_por     INT DEFAULT NULL,
  fecha_aprobacion DATETIME DEFAULT NULL,
  notas            TEXT DEFAULT NULL,
  created_at       DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_compra_emp_id (id_empresa, id),
  UNIQUE KEY uq_compra_folio (id_empresa, folio),
  KEY idx_compra_fecha (id_empresa, fecha),
  KEY idx_compra_prov (id_empresa, id_proveedor),
  CONSTRAINT fk_compra_emp     FOREIGN KEY (id_empresa) REFERENCES empresas(id) ON DELETE RESTRICT,
  CONSTRAINT fk_compra_prov    FOREIGN KEY (id_empresa, id_proveedor) REFERENCES proveedores(id_empresa, id) ON DELETE RESTRICT,
  CONSTRAINT fk_compra_alm     FOREIGN KEY (id_empresa, id_almacen) REFERENCES almacenes(id_empresa, id) ON DELETE RESTRICT,
  CONSTRAINT fk_compra_user    FOREIGN KEY (id_empresa, id_usuario) REFERENCES users(id_empresa, id) ON DELETE RESTRICT,
  CONSTRAINT fk_compra_aprueba FOREIGN KEY (id_empresa, aprobada_por) REFERENCES users(id_empresa, id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE compra_lineas (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  id_empresa     INT NOT NULL,
  id_compra      INT NOT NULL,
  id_articulo    INT NOT NULL,
  id_valor1      INT DEFAULT NULL,
  id_valor2      INT DEFAULT NULL,
  codigo         VARCHAR(60)  DEFAULT NULL,
  descripcion    VARCHAR(200) DEFAULT NULL,
  cantidad       DECIMAL(12,2) NOT NULL DEFAULT 0,
  costo_unitario DECIMAL(12,2) NOT NULL DEFAULT 0,
  importe        DECIMAL(12,2) NOT NULL DEFAULT 0,
  KEY idx_cl_compra (id_empresa, id_compra),
  CONSTRAINT fk_cl_compra FOREIGN KEY (id_empresa, id_compra) REFERENCES compras(id_empresa, id) ON DELETE CASCADE,
  CONSTRAINT fk_cl_art    FOREIGN KEY (id_empresa, id_articulo) REFERENCES articulos(id_empresa, id) ON DELETE RESTRICT,
  CONSTRAINT fk_cl_v1     FOREIGN KEY (id_empresa, id_valor1) REFERENCES atributo_valores(id_empresa, id) ON DELETE RESTRICT,
  CONSTRAINT fk_cl_v2     FOREIGN KEY (id_empresa, id_valor2) REFERENCES atributo_valores(id_empresa, id) ON DELETE RESTRICT,
  CONSTRAINT chk_cl_cant CHECK (cantidad > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE compra_pagos (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  id_empresa INT NOT NULL,
  id_compra  INT NOT NULL,
  fecha      DATETIME NOT NULL,
  forma_pago VARCHAR(20) NOT NULL,
  importe    DECIMAL(12,2) NOT NULL DEFAULT 0,
  aplica_iva TINYINT(1) NOT NULL DEFAULT 0,
  id_banco   INT DEFAULT NULL,
  referencia VARCHAR(80) DEFAULT NULL,
  id_usuario INT DEFAULT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_cp_compra (id_empresa, id_compra),
  CONSTRAINT fk_cp_compra FOREIGN KEY (id_empresa, id_compra) REFERENCES compras(id_empresa, id) ON DELETE CASCADE,
  CONSTRAINT fk_cp_banco  FOREIGN KEY (id_empresa, id_banco) REFERENCES bancos(id_empresa, id) ON DELETE RESTRICT,
  CONSTRAINT fk_cp_user   FOREIGN KEY (id_empresa, id_usuario) REFERENCES users(id_empresa, id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE traspasos (
  id                 INT AUTO_INCREMENT PRIMARY KEY,
  id_empresa         INT NOT NULL,
  folio              VARCHAR(40) NOT NULL,
  fecha              DATETIME NOT NULL,
  id_almacen_origen  INT NOT NULL,
  id_almacen_destino INT NOT NULL,
  id_usuario         INT DEFAULT NULL,
  estado             ENUM('pendiente','aceptado','rechazado') NOT NULL DEFAULT 'pendiente',
  aceptado_por       INT DEFAULT NULL,
  fecha_aceptacion   DATETIME DEFAULT NULL,
  notas              TEXT DEFAULT NULL,
  created_at         DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at         DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_tras_emp_id (id_empresa, id),
  UNIQUE KEY uq_tras_folio (id_empresa, folio),
  KEY idx_tras_fecha (id_empresa, fecha),
  CONSTRAINT fk_tras_emp     FOREIGN KEY (id_empresa) REFERENCES empresas(id) ON DELETE RESTRICT,
  CONSTRAINT fk_tras_origen  FOREIGN KEY (id_empresa, id_almacen_origen) REFERENCES almacenes(id_empresa, id) ON DELETE RESTRICT,
  CONSTRAINT fk_tras_destino FOREIGN KEY (id_empresa, id_almacen_destino) REFERENCES almacenes(id_empresa, id) ON DELETE RESTRICT,
  CONSTRAINT fk_tras_user    FOREIGN KEY (id_empresa, id_usuario) REFERENCES users(id_empresa, id) ON DELETE RESTRICT,
  CONSTRAINT fk_tras_acepta  FOREIGN KEY (id_empresa, aceptado_por) REFERENCES users(id_empresa, id) ON DELETE RESTRICT,
  CONSTRAINT chk_tras_almacenes CHECK (id_almacen_origen <> id_almacen_destino)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE traspaso_lineas (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  id_empresa  INT NOT NULL,
  id_traspaso INT NOT NULL,
  id_articulo INT NOT NULL,
  id_valor1   INT DEFAULT NULL,
  id_valor2   INT DEFAULT NULL,
  codigo      VARCHAR(60)  DEFAULT NULL,
  descripcion VARCHAR(200) DEFAULT NULL,
  cantidad    DECIMAL(12,2) NOT NULL DEFAULT 0,
  KEY idx_tl_tras (id_empresa, id_traspaso),
  CONSTRAINT fk_tl_tras FOREIGN KEY (id_empresa, id_traspaso) REFERENCES traspasos(id_empresa, id) ON DELETE CASCADE,
  CONSTRAINT fk_tl_art  FOREIGN KEY (id_empresa, id_articulo) REFERENCES articulos(id_empresa, id) ON DELETE RESTRICT,
  CONSTRAINT fk_tl_v1   FOREIGN KEY (id_empresa, id_valor1) REFERENCES atributo_valores(id_empresa, id) ON DELETE RESTRICT,
  CONSTRAINT fk_tl_v2   FOREIGN KEY (id_empresa, id_valor2) REFERENCES atributo_valores(id_empresa, id) ON DELETE RESTRICT,
  CONSTRAINT chk_tl_cant CHECK (cantidad > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 9. FINANZAS: CxC, MONEDERO, CxP
-- ============================================================

CREATE TABLE cliente_movimientos (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  id_empresa    INT NOT NULL,
  id_cliente    INT NOT NULL,
  fecha         DATETIME NOT NULL,
  tipo          VARCHAR(20) NOT NULL,          -- venta|abono|devolucion|cambio|bonificacion|cargo|monedero
  concepto      VARCHAR(200) DEFAULT NULL,
  monto         DECIMAL(14,2) NOT NULL DEFAULT 0,
  efecto        ENUM('cargo','abono','info') NOT NULL DEFAULT 'info',
  moneda        VARCHAR(5) NOT NULL DEFAULT 'MXN',
  id_banco      INT DEFAULT NULL,
  anulado       TINYINT(1) NOT NULL DEFAULT 0,
  ref_tipo      VARCHAR(30) DEFAULT NULL,
  id_referencia INT DEFAULT NULL,
  created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_climov_cli (id_empresa, id_cliente, fecha),
  CONSTRAINT fk_climov_cli   FOREIGN KEY (id_empresa, id_cliente) REFERENCES clientes(id_empresa, id) ON DELETE RESTRICT,
  CONSTRAINT fk_climov_banco FOREIGN KEY (id_empresa, id_banco) REFERENCES bancos(id_empresa, id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE monedero_movimientos (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  id_empresa    INT NOT NULL,
  id_cliente    INT NOT NULL,
  tipo          VARCHAR(20) NOT NULL,          -- deposito|gasto|ajuste
  importe       DECIMAL(14,2) NOT NULL DEFAULT 0,   -- con signo
  origen        VARCHAR(200) DEFAULT NULL,
  ref_tipo      VARCHAR(30) DEFAULT NULL,
  id_referencia INT DEFAULT NULL,
  created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_monmov_cli (id_empresa, id_cliente, created_at),
  CONSTRAINT fk_monmov_cli FOREIGN KEY (id_empresa, id_cliente) REFERENCES clientes(id_empresa, id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE proveedor_movimientos (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  id_empresa    INT NOT NULL,
  id_proveedor  INT NOT NULL,
  fecha         DATETIME NOT NULL,
  tipo          ENUM('cargo','pago') NOT NULL,
  concepto      VARCHAR(200) DEFAULT NULL,
  monto         DECIMAL(14,2) NOT NULL DEFAULT 0,
  moneda        VARCHAR(5) NOT NULL DEFAULT 'MXN',
  id_banco      INT DEFAULT NULL,
  ref_tipo      VARCHAR(30) DEFAULT NULL,
  id_referencia INT DEFAULT NULL,
  created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_provmov_prov (id_empresa, id_proveedor, fecha),
  CONSTRAINT fk_provmov_prov  FOREIGN KEY (id_empresa, id_proveedor) REFERENCES proveedores(id_empresa, id) ON DELETE RESTRICT,
  CONSTRAINT fk_provmov_banco FOREIGN KEY (id_empresa, id_banco) REFERENCES bancos(id_empresa, id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 10. APARTADOS, DEVOLUCIONES, CAMBIOS, COMISIONES
-- ============================================================

CREATE TABLE apartados (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  id_empresa   INT NOT NULL,
  folio        VARCHAR(40) NOT NULL,
  fecha        DATETIME NOT NULL,
  fecha_limite DATE DEFAULT NULL,
  id_cliente   INT NOT NULL,
  id_almacen   INT NOT NULL,
  id_vendedor  INT DEFAULT NULL,
  id_usuario   INT DEFAULT NULL,
  total        DECIMAL(14,2) NOT NULL DEFAULT 0,
  anticipo     DECIMAL(14,2) NOT NULL DEFAULT 0,
  estado       ENUM('vigente','con_anticipo','vencido','liquidado','cancelado') NOT NULL DEFAULT 'vigente',
  id_venta     INT DEFAULT NULL,               -- venta generada al liquidar
  notas        TEXT DEFAULT NULL,
  created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_apt_emp_id (id_empresa, id),
  UNIQUE KEY uq_apt_folio (id_empresa, folio),
  KEY idx_apt_cli (id_empresa, id_cliente),
  KEY idx_apt_fecha (id_empresa, fecha),
  CONSTRAINT fk_apt_emp   FOREIGN KEY (id_empresa) REFERENCES empresas(id) ON DELETE RESTRICT,
  CONSTRAINT fk_apt_cli   FOREIGN KEY (id_empresa, id_cliente) REFERENCES clientes(id_empresa, id) ON DELETE RESTRICT,
  CONSTRAINT fk_apt_alm   FOREIGN KEY (id_empresa, id_almacen) REFERENCES almacenes(id_empresa, id) ON DELETE RESTRICT,
  CONSTRAINT fk_apt_vend  FOREIGN KEY (id_empresa, id_vendedor) REFERENCES empleados(id_empresa, id) ON DELETE RESTRICT,
  CONSTRAINT fk_apt_user  FOREIGN KEY (id_empresa, id_usuario) REFERENCES users(id_empresa, id) ON DELETE RESTRICT,
  CONSTRAINT fk_apt_venta FOREIGN KEY (id_empresa, id_venta) REFERENCES ventas(id_empresa, id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE apartado_lineas (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  id_empresa      INT NOT NULL,
  id_apartado     INT NOT NULL,
  id_articulo     INT NOT NULL,
  id_valor1       INT DEFAULT NULL,
  id_valor2       INT DEFAULT NULL,
  codigo          VARCHAR(60)  DEFAULT NULL,
  descripcion     VARCHAR(200) DEFAULT NULL,
  cantidad        DECIMAL(12,2) NOT NULL DEFAULT 0,
  precio_unitario DECIMAL(12,2) NOT NULL DEFAULT 0,
  importe         DECIMAL(12,2) NOT NULL DEFAULT 0,
  KEY idx_aptl_apt (id_empresa, id_apartado),
  CONSTRAINT fk_aptl_apt FOREIGN KEY (id_empresa, id_apartado) REFERENCES apartados(id_empresa, id) ON DELETE CASCADE,
  CONSTRAINT fk_aptl_art FOREIGN KEY (id_empresa, id_articulo) REFERENCES articulos(id_empresa, id) ON DELETE RESTRICT,
  CONSTRAINT fk_aptl_v1  FOREIGN KEY (id_empresa, id_valor1) REFERENCES atributo_valores(id_empresa, id) ON DELETE RESTRICT,
  CONSTRAINT fk_aptl_v2  FOREIGN KEY (id_empresa, id_valor2) REFERENCES atributo_valores(id_empresa, id) ON DELETE RESTRICT,
  CONSTRAINT chk_aptl_cant CHECK (cantidad > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE apartado_anticipos (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  id_empresa  INT NOT NULL,
  id_apartado INT NOT NULL,
  fecha       DATETIME NOT NULL,
  forma       VARCHAR(20) NOT NULL DEFAULT 'efectivo',
  importe     DECIMAL(12,2) NOT NULL DEFAULT 0,
  id_usuario  INT DEFAULT NULL,
  KEY idx_apta_apt (id_empresa, id_apartado),
  CONSTRAINT fk_apta_apt  FOREIGN KEY (id_empresa, id_apartado) REFERENCES apartados(id_empresa, id) ON DELETE CASCADE,
  CONSTRAINT fk_apta_user FOREIGN KEY (id_empresa, id_usuario) REFERENCES users(id_empresa, id) ON DELETE RESTRICT,
  CONSTRAINT chk_apta_imp CHECK (importe > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE devoluciones (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  id_empresa    INT NOT NULL,
  folio         VARCHAR(40) NOT NULL,
  fecha         DATETIME NOT NULL,
  id_venta      INT DEFAULT NULL,
  id_cliente    INT DEFAULT NULL,
  id_almacen    INT DEFAULT NULL,
  total         DECIMAL(14,2) NOT NULL DEFAULT 0,
  destino_saldo ENUM('monedero','cxc') NOT NULL DEFAULT 'monedero',
  id_usuario    INT DEFAULT NULL,
  created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_dev_emp_id (id_empresa, id),
  UNIQUE KEY uq_dev_folio (id_empresa, folio),
  KEY idx_dev_venta (id_empresa, id_venta),
  KEY idx_dev_fecha (id_empresa, fecha),
  CONSTRAINT fk_dev_emp   FOREIGN KEY (id_empresa) REFERENCES empresas(id) ON DELETE RESTRICT,
  CONSTRAINT fk_dev_venta FOREIGN KEY (id_empresa, id_venta) REFERENCES ventas(id_empresa, id) ON DELETE RESTRICT,
  CONSTRAINT fk_dev_cli   FOREIGN KEY (id_empresa, id_cliente) REFERENCES clientes(id_empresa, id) ON DELETE RESTRICT,
  CONSTRAINT fk_dev_alm   FOREIGN KEY (id_empresa, id_almacen) REFERENCES almacenes(id_empresa, id) ON DELETE RESTRICT,
  CONSTRAINT fk_dev_user  FOREIGN KEY (id_empresa, id_usuario) REFERENCES users(id_empresa, id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE devolucion_lineas (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  id_empresa      INT NOT NULL,
  id_devolucion   INT NOT NULL,
  id_articulo     INT NOT NULL,
  id_valor1       INT DEFAULT NULL,
  id_valor2       INT DEFAULT NULL,
  codigo          VARCHAR(60)  DEFAULT NULL,
  descripcion     VARCHAR(200) DEFAULT NULL,
  cantidad        DECIMAL(12,2) NOT NULL DEFAULT 0,
  precio_unitario DECIMAL(12,2) NOT NULL DEFAULT 0,
  importe         DECIMAL(12,2) NOT NULL DEFAULT 0,
  KEY idx_devl_dev (id_empresa, id_devolucion),
  CONSTRAINT fk_devl_dev FOREIGN KEY (id_empresa, id_devolucion) REFERENCES devoluciones(id_empresa, id) ON DELETE CASCADE,
  CONSTRAINT fk_devl_art FOREIGN KEY (id_empresa, id_articulo) REFERENCES articulos(id_empresa, id) ON DELETE RESTRICT,
  CONSTRAINT fk_devl_v1  FOREIGN KEY (id_empresa, id_valor1) REFERENCES atributo_valores(id_empresa, id) ON DELETE RESTRICT,
  CONSTRAINT fk_devl_v2  FOREIGN KEY (id_empresa, id_valor2) REFERENCES atributo_valores(id_empresa, id) ON DELETE RESTRICT,
  CONSTRAINT chk_devl_cant CHECK (cantidad > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE cambios (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  id_empresa      INT NOT NULL,
  folio           VARCHAR(40) NOT NULL,
  fecha           DATETIME NOT NULL,
  id_venta        INT DEFAULT NULL,
  id_cliente      INT DEFAULT NULL,
  id_almacen      INT DEFAULT NULL,
  total_devuelto  DECIMAL(14,2) NOT NULL DEFAULT 0,
  total_nuevo     DECIMAL(14,2) NOT NULL DEFAULT 0,
  diferencia      DECIMAL(14,2) NOT NULL DEFAULT 0,
  pago_diferencia DECIMAL(14,2) NOT NULL DEFAULT 0,
  id_usuario      INT DEFAULT NULL,
  created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_camb_emp_id (id_empresa, id),
  UNIQUE KEY uq_camb_folio (id_empresa, folio),
  KEY idx_camb_fecha (id_empresa, fecha),
  CONSTRAINT fk_camb_emp   FOREIGN KEY (id_empresa) REFERENCES empresas(id) ON DELETE RESTRICT,
  CONSTRAINT fk_camb_venta FOREIGN KEY (id_empresa, id_venta) REFERENCES ventas(id_empresa, id) ON DELETE RESTRICT,
  CONSTRAINT fk_camb_cli   FOREIGN KEY (id_empresa, id_cliente) REFERENCES clientes(id_empresa, id) ON DELETE RESTRICT,
  CONSTRAINT fk_camb_alm   FOREIGN KEY (id_empresa, id_almacen) REFERENCES almacenes(id_empresa, id) ON DELETE RESTRICT,
  CONSTRAINT fk_camb_user  FOREIGN KEY (id_empresa, id_usuario) REFERENCES users(id_empresa, id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE cambio_lineas (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  id_empresa      INT NOT NULL,
  id_cambio       INT NOT NULL,
  rol             ENUM('devuelta','nueva') NOT NULL,
  id_articulo     INT NOT NULL,
  id_valor1       INT DEFAULT NULL,
  id_valor2       INT DEFAULT NULL,
  codigo          VARCHAR(60)  DEFAULT NULL,
  descripcion     VARCHAR(200) DEFAULT NULL,
  cantidad        DECIMAL(12,2) NOT NULL DEFAULT 0,
  precio_unitario DECIMAL(12,2) NOT NULL DEFAULT 0,
  importe         DECIMAL(12,2) NOT NULL DEFAULT 0,
  KEY idx_cambl_camb (id_empresa, id_cambio),
  CONSTRAINT fk_cambl_camb FOREIGN KEY (id_empresa, id_cambio) REFERENCES cambios(id_empresa, id) ON DELETE CASCADE,
  CONSTRAINT fk_cambl_art  FOREIGN KEY (id_empresa, id_articulo) REFERENCES articulos(id_empresa, id) ON DELETE RESTRICT,
  CONSTRAINT fk_cambl_v1   FOREIGN KEY (id_empresa, id_valor1) REFERENCES atributo_valores(id_empresa, id) ON DELETE RESTRICT,
  CONSTRAINT fk_cambl_v2   FOREIGN KEY (id_empresa, id_valor2) REFERENCES atributo_valores(id_empresa, id) ON DELETE RESTRICT,
  CONSTRAINT chk_cambl_cant CHECK (cantidad > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE comisiones (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  id_empresa  INT NOT NULL,
  id_empleado INT NOT NULL,
  id_venta    INT DEFAULT NULL,
  base        DECIMAL(14,2) NOT NULL DEFAULT 0,
  porcentaje  DECIMAL(6,2) NOT NULL DEFAULT 0,
  importe     DECIMAL(14,2) NOT NULL DEFAULT 0,
  pagada      ENUM('Si','No') NOT NULL DEFAULT 'No',
  fecha_pago  DATETIME DEFAULT NULL,
  anulada     TINYINT(1) NOT NULL DEFAULT 0,
  created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_comis_emp_id (id_empresa, id),
  KEY idx_comis_empleado (id_empresa, id_empleado),
  KEY idx_comis_venta (id_empresa, id_venta),
  CONSTRAINT fk_comis_emp   FOREIGN KEY (id_empresa) REFERENCES empresas(id) ON DELETE RESTRICT,
  CONSTRAINT fk_comis_empl  FOREIGN KEY (id_empresa, id_empleado) REFERENCES empleados(id_empresa, id) ON DELETE RESTRICT,
  CONSTRAINT fk_comis_venta FOREIGN KEY (id_empresa, id_venta) REFERENCES ventas(id_empresa, id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 11. CORTES DE CAJA Y BITACORA
-- ============================================================

CREATE TABLE cortes (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  id_empresa       INT NOT NULL,
  folio            VARCHAR(40) NOT NULL,
  fecha            DATE NOT NULL,
  id_almacen       INT NOT NULL,
  id_usuario       INT DEFAULT NULL,
  fondo            DECIMAL(12,2) NOT NULL DEFAULT 0,
  efectivo_contado DECIMAL(12,2) NOT NULL DEFAULT 0,
  diferencia       DECIMAL(12,2) NOT NULL DEFAULT 0,
  resumen          JSON DEFAULT NULL,      -- snapshot del dia (descriptivo)
  detalle_notas    JSON DEFAULT NULL,
  notas            TEXT DEFAULT NULL,
  created_at       DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_corte_emp_id (id_empresa, id),
  UNIQUE KEY uq_corte_folio (id_empresa, folio),
  KEY idx_corte_alm_fecha (id_empresa, id_almacen, fecha),
  CONSTRAINT fk_corte_emp  FOREIGN KEY (id_empresa) REFERENCES empresas(id) ON DELETE RESTRICT,
  CONSTRAINT fk_corte_alm  FOREIGN KEY (id_empresa, id_almacen) REFERENCES almacenes(id_empresa, id) ON DELETE RESTRICT,
  CONSTRAINT fk_corte_user FOREIGN KEY (id_empresa, id_usuario) REFERENCES users(id_empresa, id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Bitacora. id_empresa NULL = accion de plataforma (superadmin). act_as = superadmin operando como tienda.
CREATE TABLE audit_log (
  id          BIGINT AUTO_INCREMENT PRIMARY KEY,
  id_empresa  INT DEFAULT NULL,
  id_usuario  INT DEFAULT NULL,
  act_as      INT DEFAULT NULL,
  usuario     VARCHAR(160) DEFAULT NULL,   -- copia del login al momento de la accion
  accion      VARCHAR(60) NOT NULL,
  entidad     VARCHAR(60) DEFAULT NULL,
  id_entidad  VARCHAR(40) DEFAULT NULL,
  descripcion VARCHAR(255) DEFAULT NULL,
  ip          VARCHAR(45) DEFAULT NULL,
  created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_audit_emp_fecha (id_empresa, created_at),
  CONSTRAINT fk_audit_emp   FOREIGN KEY (id_empresa) REFERENCES empresas(id) ON DELETE RESTRICT,
  CONSTRAINT fk_audit_user  FOREIGN KEY (id_empresa, id_usuario) REFERENCES users(id_empresa, id) ON DELETE RESTRICT,
  CONSTRAINT fk_audit_actas FOREIGN KEY (act_as) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- 12. DATOS DE SISTEMA: modulos y acciones (versionados con el esquema)
-- ============================================================

INSERT INTO modulos (clave, nombre, orden) VALUES
  ('ventas',       'Ventas / Punto de venta', 10),
  ('apartados',    'Apartados',               20),
  ('devoluciones', 'Devoluciones y cambios',  30),
  ('cortes',       'Cortes de caja',          40),
  ('clientes',     'Clientes y monedero',     50),
  ('catalogos',    'Articulos y catalogos',   60),
  ('proveedores',  'Proveedores',             70),
  ('empleados',    'Empleados',               80),
  ('almacen',      'Almacenes y existencias', 90),
  ('traspasos',    'Traspasos',              100),
  ('compras',      'Compras',                110),
  ('finanzas',     'Bancos, CxC y CxP',      120),
  ('comisiones',   'Comisiones',             130),
  ('reportes',     'Reportes',               140),
  ('facturacion',  'Facturacion',            150),
  ('configuracion','Configuracion de tienda',160),
  ('usuarios',     'Usuarios de la tienda',  170),
  ('bitacora',     'Bitacora',               180);

INSERT INTO modulo_acciones (modulo, accion, nombre) VALUES
  ('ventas','ver','Ver ventas'), ('ventas','crear','Vender'), ('ventas','cancelar','Cancelar ventas'),
  ('apartados','ver','Ver'), ('apartados','crear','Crear'), ('apartados','editar','Abonar / liquidar'), ('apartados','cancelar','Cancelar'),
  ('devoluciones','ver','Ver'), ('devoluciones','crear','Registrar devoluciones y cambios'),
  ('cortes','ver','Ver'), ('cortes','crear','Cerrar corte'),
  ('clientes','ver','Ver'), ('clientes','crear','Crear'), ('clientes','editar','Editar'), ('clientes','eliminar','Eliminar'),
  ('clientes','autorizar_credito','Autorizar credito'), ('clientes','monedero','Ajustar monedero'),
  ('catalogos','ver','Ver'), ('catalogos','crear','Crear'), ('catalogos','editar','Editar'), ('catalogos','eliminar','Eliminar'),
  ('proveedores','ver','Ver'), ('proveedores','crear','Crear'), ('proveedores','editar','Editar'), ('proveedores','eliminar','Eliminar'),
  ('empleados','ver','Ver'), ('empleados','crear','Crear'), ('empleados','editar','Editar'), ('empleados','eliminar','Eliminar'),
  ('almacen','ver','Ver existencias'), ('almacen','crear','Crear almacenes'), ('almacen','editar','Editar almacenes'),
  ('almacen','eliminar','Eliminar almacenes'), ('almacen','ajustar','Ajustar inventario'),
  ('traspasos','ver','Ver'), ('traspasos','crear','Crear'), ('traspasos','editar','Editar'), ('traspasos','aprobar','Aceptar / rechazar'),
  ('compras','ver','Ver'), ('compras','crear','Crear'), ('compras','editar','Editar'), ('compras','eliminar','Cancelar'),
  ('compras','aprobar','Aprobar'), ('compras','pagar','Registrar pagos'),
  ('finanzas','ver','Ver'), ('finanzas','crear','Registrar abonos y pagos'), ('finanzas','editar','Administrar bancos'),
  ('comisiones','ver','Ver'), ('comisiones','pagar','Marcar pagadas'),
  ('reportes','ver','Ver'),
  ('facturacion','ver','Ver'), ('facturacion','crear','Facturar'),
  ('configuracion','ver','Ver'), ('configuracion','editar','Editar'),
  ('usuarios','ver','Ver'), ('usuarios','crear','Crear'), ('usuarios','editar','Editar y permisos'), ('usuarios','eliminar','Desactivar'),
  ('bitacora','ver','Ver');
