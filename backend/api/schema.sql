-- ============================================================
-- LEVOTEK — Esquema MySQL (modulos nucleo)
-- Compatible con MySQL 5.7+ / MariaDB 10.2+ (GoDaddy)
-- Charset utf8mb4. Importar en phpMyAdmin sobre la BD creada.
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------- Empresas (multi-tenant simple) ----------
CREATE TABLE IF NOT EXISTS empresas (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  nombre        VARCHAR(150) NOT NULL,
  rfc           VARCHAR(20)  DEFAULT NULL,
  iva           DECIMAL(5,4) NOT NULL DEFAULT 0.1600,
  pin_lista_alta    VARCHAR(255) DEFAULT NULL,
  pin_lista_alta_at DATETIME DEFAULT NULL,
  is_active     ENUM('Si','No') NOT NULL DEFAULT 'Si',
  created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- Usuarios ----------
CREATE TABLE IF NOT EXISTS users (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  id_empresa    INT NOT NULL,
  nombre        VARCHAR(80)  NOT NULL,
  apellido      VARCHAR(80)  NOT NULL DEFAULT '',
  email         VARCHAR(160) NOT NULL,
  password      VARCHAR(255) NOT NULL,
  rol           ENUM('admin','usuario') NOT NULL DEFAULT 'usuario',
  is_active     ENUM('Si','No') NOT NULL DEFAULT 'Si',
  permisos      JSON DEFAULT NULL,
  id_tienda     INT DEFAULT NULL,
  ultimo_acceso DATETIME DEFAULT NULL,
  created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_users_email (email),
  KEY idx_users_empresa (id_empresa)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- Almacenes / Tiendas ----------
CREATE TABLE IF NOT EXISTS almacenes (
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
  UNIQUE KEY uq_alm_codigo (id_empresa, codigo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- Catalogos simples ----------
CREATE TABLE IF NOT EXISTS colores (
  id INT AUTO_INCREMENT PRIMARY KEY, id_empresa INT NOT NULL,
  nombre VARCHAR(80) NOT NULL, hex VARCHAR(9) DEFAULT NULL,
  is_active ENUM('Si','No') NOT NULL DEFAULT 'Si',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_col_emp (id_empresa)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tallas (
  id INT AUTO_INCREMENT PRIMARY KEY, id_empresa INT NOT NULL,
  nombre VARCHAR(80) NOT NULL, orden INT NOT NULL DEFAULT 0,
  is_active ENUM('Si','No') NOT NULL DEFAULT 'Si',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_tal_emp (id_empresa)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS familias (
  id INT AUTO_INCREMENT PRIMARY KEY, id_empresa INT NOT NULL,
  nombre VARCHAR(120) NOT NULL, is_active ENUM('Si','No') NOT NULL DEFAULT 'Si',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_fam_emp (id_empresa)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS lineas (
  id INT AUTO_INCREMENT PRIMARY KEY, id_empresa INT NOT NULL,
  nombre VARCHAR(120) NOT NULL, is_active ENUM('Si','No') NOT NULL DEFAULT 'Si',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_lin_emp (id_empresa)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS colecciones (
  id INT AUTO_INCREMENT PRIMARY KEY, id_empresa INT NOT NULL,
  nombre VARCHAR(120) NOT NULL, is_active ENUM('Si','No') NOT NULL DEFAULT 'Si',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_colec_emp (id_empresa)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- catalogo de "cortes" de prenda (distinto de la tabla 'cortes' = cortes de caja)
CREATE TABLE IF NOT EXISTS cortes_catalogo (
  id INT AUTO_INCREMENT PRIMARY KEY, id_empresa INT NOT NULL,
  nombre VARCHAR(120) NOT NULL, is_active ENUM('Si','No') NOT NULL DEFAULT 'Si',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_cortecat_emp (id_empresa)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS marcas (
  id INT AUTO_INCREMENT PRIMARY KEY, id_empresa INT NOT NULL,
  nombre VARCHAR(120) NOT NULL, is_active ENUM('Si','No') NOT NULL DEFAULT 'Si',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_mar_emp (id_empresa)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS conceptos_gasto (
  id INT AUTO_INCREMENT PRIMARY KEY, id_empresa INT NOT NULL,
  nombre VARCHAR(120) NOT NULL, is_active ENUM('Si','No') NOT NULL DEFAULT 'Si',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_cg_emp (id_empresa)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- MultiTienda: motor de atributos + categorias (tienda general)
-- ============================================================

-- Atributos genericos de variante (generaliza colores/tallas)
CREATE TABLE IF NOT EXISTS atributos (
  id INT AUTO_INCREMENT PRIMARY KEY, id_empresa INT NOT NULL,
  nombre     VARCHAR(80) NOT NULL,
  tipo_valor ENUM('texto','color','numero') NOT NULL DEFAULT 'texto',
  orden      INT NOT NULL DEFAULT 0,
  is_active  ENUM('Si','No') NOT NULL DEFAULT 'Si',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_atr_emp (id_empresa)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Valores de cada atributo (Rojo, M, 26, Plata, ...)
CREATE TABLE IF NOT EXISTS atributo_valores (
  id INT AUTO_INCREMENT PRIMARY KEY, id_empresa INT NOT NULL,
  id_atributo INT NOT NULL,
  nombre     VARCHAR(80) NOT NULL,
  extra      VARCHAR(40) DEFAULT NULL,   -- hex si es color, o dato libre
  orden      INT NOT NULL DEFAULT 0,
  is_active  ENUM('Si','No') NOT NULL DEFAULT 'Si',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_atrval_atr (id_atributo), KEY idx_atrval_emp (id_empresa)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Categorias: definen los ejes de variante y la ficha de cada tipo de producto
CREATE TABLE IF NOT EXISTS categorias (
  id INT AUTO_INCREMENT PRIMARY KEY, id_empresa INT NOT NULL,
  nombre           VARCHAR(120) NOT NULL,
  prefijo_sku      VARCHAR(8) DEFAULT NULL,     -- ej. ROP, CAL, BIS, ELE...
  id_atributo_eje1 INT DEFAULT NULL,            -- eje de variante 1 (NULL = sin eje)
  id_atributo_eje2 INT DEFAULT NULL,            -- eje de variante 2 (NULL = sin eje)
  ficha_schema     JSON DEFAULT NULL,           -- [{key,label,tipo}] campos propios de la categoria
  is_active  ENUM('Si','No') NOT NULL DEFAULT 'Si',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_cat_emp (id_empresa)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- Articulos (prenda base) ----------
CREATE TABLE IF NOT EXISTS articulos (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  id_empresa    INT NOT NULL,
  codigo        VARCHAR(60)  NOT NULL,
  descripcion   VARCHAR(200) NOT NULL,
  ean           VARCHAR(40)  DEFAULT NULL,
  id_familia    INT DEFAULT NULL,
  id_linea      INT DEFAULT NULL,
  id_coleccion  INT DEFAULT NULL,
  id_corte      INT DEFAULT NULL,
  id_marca      INT DEFAULT NULL,
  id_categoria  INT DEFAULT NULL,             -- MultiTienda: define ejes de variante y ficha
  sku           VARCHAR(40) DEFAULT NULL,     -- codigo interno automatico (unico por empresa)
  unidad        VARCHAR(20) NOT NULL DEFAULT 'pieza',
  contenido_paquete INT NOT NULL DEFAULT 1,   -- piezas por paquete/set
  es_kit        TINYINT(1) NOT NULL DEFAULT 0,
  ficha         JSON DEFAULT NULL,            -- valores de los campos propios de la categoria
  costo         DECIMAL(12,2) NOT NULL DEFAULT 0,
  lista1        DECIMAL(12,2) NOT NULL DEFAULT 0,
  lista2        DECIMAL(12,2) NOT NULL DEFAULT 0,
  lista3        DECIMAL(12,2) NOT NULL DEFAULT 0,
  lista4        DECIMAL(12,2) NOT NULL DEFAULT 0,
  lista5        DECIMAL(12,2) NOT NULL DEFAULT 0,
  es_oferta     TINYINT(1) NOT NULL DEFAULT 0,
  precio_oferta DECIMAL(12,2) NOT NULL DEFAULT 0,
  piezas_por_caja INT NOT NULL DEFAULT 1,
  is_active     ENUM('Si','No') NOT NULL DEFAULT 'Si',
  created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_art_codigo (id_empresa, codigo),
  KEY idx_art_desc (descripcion)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- variantes disponibles: valores de eje1/eje2 del articulo (id_color=eje1, id_talla=eje2 -> atributo_valores)
CREATE TABLE IF NOT EXISTS articulo_colores (
  id_articulo INT NOT NULL, id_color INT NOT NULL,
  PRIMARY KEY (id_articulo, id_color)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS articulo_tallas (
  id_articulo INT NOT NULL, id_talla INT NOT NULL,
  PRIMARY KEY (id_articulo, id_talla)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- MultiTienda: componentes de un kit (F5)
CREATE TABLE IF NOT EXISTS articulo_componentes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  id_kit        INT NOT NULL,
  id_componente INT NOT NULL,
  cantidad      DECIMAL(12,2) NOT NULL DEFAULT 1,
  KEY idx_comp_kit (id_kit)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- MultiTienda: SKU/codigo de barras por variante (F4)
CREATE TABLE IF NOT EXISTS articulo_variante_codigos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  id_empresa    INT NOT NULL,
  id_articulo   INT NOT NULL,
  id_eje1       INT NOT NULL DEFAULT 0,
  id_eje2       INT NOT NULL DEFAULT 0,
  sku           VARCHAR(50) DEFAULT NULL,
  codigo_barras VARCHAR(50) DEFAULT NULL,
  KEY idx_vcod_art (id_articulo),
  KEY idx_vcod_bc (codigo_barras)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- fotos del articulo (archivo guardado en /uploads/articulos)
CREATE TABLE IF NOT EXISTS articulo_fotos (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  id_empresa  INT NOT NULL,
  id_articulo INT NOT NULL,
  imgkey      VARCHAR(120) DEFAULT NULL,
  url         VARCHAR(500) NOT NULL,
  nombre      VARCHAR(200) DEFAULT NULL,
  orden       INT NOT NULL DEFAULT 0,
  created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_artfoto_art (id_articulo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- Inventario por variante (articulo+color+talla+almacen) ----------
-- id_color / id_talla = 0 cuando el articulo no maneja color / talla.
CREATE TABLE IF NOT EXISTS inventario (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  id_empresa  INT NOT NULL,
  id_articulo INT NOT NULL,
  id_color    INT NOT NULL DEFAULT 0,
  id_talla    INT NOT NULL DEFAULT 0,
  id_almacen  INT NOT NULL,
  cantidad    DECIMAL(12,2) NOT NULL DEFAULT 0,
  reservado   DECIMAL(12,2) NOT NULL DEFAULT 0,
  created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_inv (id_articulo, id_color, id_talla, id_almacen),
  KEY idx_inv_alm (id_almacen),
  KEY idx_inv_emp (id_empresa)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- Kardex (movimientos de inventario) ----------
CREATE TABLE IF NOT EXISTS inventario_movimientos (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  id_empresa      INT NOT NULL,
  folio           VARCHAR(40) DEFAULT NULL,
  tipo            VARCHAR(30) NOT NULL,
  id_articulo     INT NOT NULL,
  id_color        INT NOT NULL DEFAULT 0,
  id_talla        INT NOT NULL DEFAULT 0,
  id_almacen      INT NOT NULL,
  cantidad        DECIMAL(12,2) NOT NULL,
  saldo_resultante DECIMAL(12,2) NOT NULL DEFAULT 0,
  motivo          VARCHAR(160) DEFAULT NULL,
  referencia_tipo VARCHAR(40)  DEFAULT NULL,
  id_referencia   INT DEFAULT NULL,
  id_usuario      INT DEFAULT NULL,
  created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_mov_art (id_articulo),
  KEY idx_mov_alm (id_almacen),
  KEY idx_mov_fecha (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- Clientes ----------
CREATE TABLE IF NOT EXISTS clientes (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  id_empresa    INT NOT NULL,
  nombre        VARCHAR(160) NOT NULL,
  telefono      VARCHAR(40)  DEFAULT NULL,
  rfc           VARCHAR(20)  DEFAULT NULL,
  razon_social  VARCHAR(200) DEFAULT NULL,
  contacto      JSON DEFAULT NULL,
  domicilio     JSON DEFAULT NULL,
  lista_precios INT NOT NULL DEFAULT 1,
  forma_pago    ENUM('Contado','Credito') NOT NULL DEFAULT 'Contado',
  plazo_dias    INT NOT NULL DEFAULT 0,
  limite_credito DECIMAL(12,2) NOT NULL DEFAULT 0,
  credito_autorizado_por INT DEFAULT NULL,
  es_publico_general TINYINT(1) NOT NULL DEFAULT 0,
  id_tienda     INT DEFAULT NULL,
  id_vendedor   INT DEFAULT NULL,
  saldo_credito DECIMAL(12,2) NOT NULL DEFAULT 0,
  saldo_favor   DECIMAL(12,2) NOT NULL DEFAULT 0,
  notas         TEXT DEFAULT NULL,
  is_active     ENUM('Si','No') NOT NULL DEFAULT 'Si',
  created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_cli_emp (id_empresa),
  KEY idx_cli_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- Empleados (vendedores) ----------
CREATE TABLE IF NOT EXISTS empleados (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  id_empresa  INT NOT NULL,
  nombre      VARCHAR(80) NOT NULL,
  apellido    VARCHAR(80) NOT NULL DEFAULT '',
  telefono    VARCHAR(40) DEFAULT NULL,
  email       VARCHAR(160) DEFAULT NULL,
  puesto      VARCHAR(80) DEFAULT NULL,
  area        VARCHAR(40) NOT NULL DEFAULT 'ventas',
  id_tienda   INT DEFAULT NULL,
  es_vendedor TINYINT(1) NOT NULL DEFAULT 0,
  pct_comision DECIMAL(6,2) NOT NULL DEFAULT 0,
  is_active   ENUM('Si','No') NOT NULL DEFAULT 'Si',
  created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_emp_emp (id_empresa)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- Proveedores (basico, para no romper la pagina) ----------
CREATE TABLE IF NOT EXISTS proveedores (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  id_empresa  INT NOT NULL,
  nombre      VARCHAR(160) NOT NULL,
  telefono    VARCHAR(40) DEFAULT NULL,
  rfc         VARCHAR(20) DEFAULT NULL,
  contacto    VARCHAR(160) DEFAULT NULL,
  tipo        VARCHAR(30) NOT NULL DEFAULT 'Mercancia',
  domicilio   JSON DEFAULT NULL,
  notas       TEXT DEFAULT NULL,
  is_active   ENUM('Si','No') NOT NULL DEFAULT 'Si',
  created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_prov_emp (id_empresa)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- Ventas ----------
CREATE TABLE IF NOT EXISTS ventas (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  id_empresa    INT NOT NULL,
  folio         VARCHAR(40) NOT NULL,
  fecha         DATETIME NOT NULL,
  id_almacen    INT NOT NULL,
  id_cliente    INT DEFAULT NULL,
  id_vendedor   INT DEFAULT NULL,
  id_usuario    INT DEFAULT NULL,
  total_prendas_lista INT NOT NULL DEFAULT 0,
  nivel_cantidad INT NOT NULL DEFAULT 1,
  lista_cliente INT NOT NULL DEFAULT 1,
  subtotal      DECIMAL(12,2) NOT NULL DEFAULT 0,
  iva           DECIMAL(12,2) NOT NULL DEFAULT 0,
  total         DECIMAL(12,2) NOT NULL DEFAULT 0,
  total_pagado  DECIMAL(12,2) NOT NULL DEFAULT 0,
  a_credito     TINYINT(1) NOT NULL DEFAULT 0,
  monto_credito DECIMAL(12,2) NOT NULL DEFAULT 0,
  saldo_favor_usado DECIMAL(12,2) NOT NULL DEFAULT 0,
  saldo_favor_generado DECIMAL(12,2) NOT NULL DEFAULT 0,
  cambio_efectivo DECIMAL(12,2) NOT NULL DEFAULT 0,
  estado        ENUM('completada','cancelada') NOT NULL DEFAULT 'completada',
  cancelada_por INT DEFAULT NULL,
  fecha_cancelacion DATETIME DEFAULT NULL,
  notas         TEXT DEFAULT NULL,
  created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_venta_emp (id_empresa),
  KEY idx_venta_alm_fecha (id_almacen, fecha),
  KEY idx_venta_cliente (id_cliente)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS venta_lineas (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  id_venta      INT NOT NULL,
  id_articulo   INT NOT NULL,
  codigo        VARCHAR(60) DEFAULT NULL,
  descripcion   VARCHAR(200) DEFAULT NULL,
  id_color      INT NOT NULL DEFAULT 0,
  id_talla      INT NOT NULL DEFAULT 0,
  cantidad      DECIMAL(12,2) NOT NULL DEFAULT 0,
  precio_unitario DECIMAL(12,2) NOT NULL DEFAULT 0,
  costo_unitario DECIMAL(12,2) NOT NULL DEFAULT 0,
  lista_aplicada VARCHAR(10) DEFAULT NULL,
  comisiona     TINYINT(1) NOT NULL DEFAULT 0,
  importe       DECIMAL(12,2) NOT NULL DEFAULT 0,
  KEY idx_vl_venta (id_venta)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS venta_pagos (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  id_venta   INT NOT NULL,
  forma      VARCHAR(20) NOT NULL,
  importe    DECIMAL(12,2) NOT NULL DEFAULT 0,
  id_banco   INT DEFAULT NULL,
  referencia VARCHAR(80) DEFAULT NULL,
  KEY idx_vp_venta (id_venta)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- Compras (entradas de mercancia) ----------
CREATE TABLE IF NOT EXISTS compras (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  id_empresa    INT NOT NULL,
  folio         VARCHAR(40) NOT NULL,
  fecha         DATETIME NOT NULL,
  id_proveedor  INT NOT NULL,
  id_almacen    INT NOT NULL,
  id_usuario    INT DEFAULT NULL,
  aplica_iva    TINYINT(1) NOT NULL DEFAULT 0,
  subtotal      DECIMAL(12,2) NOT NULL DEFAULT 0,
  iva           DECIMAL(12,2) NOT NULL DEFAULT 0,
  total         DECIMAL(12,2) NOT NULL DEFAULT 0,
  total_pagado  DECIMAL(12,2) NOT NULL DEFAULT 0,
  estado        ENUM('por_aprobar','aprobada','cancelada') NOT NULL DEFAULT 'por_aprobar',
  aprobada_por  INT DEFAULT NULL,
  fecha_aprobacion DATETIME DEFAULT NULL,
  notas         TEXT DEFAULT NULL,
  created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_compra_emp (id_empresa),
  KEY idx_compra_prov (id_proveedor),
  KEY idx_compra_alm (id_almacen)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS compra_lineas (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  id_compra     INT NOT NULL,
  id_articulo   INT NOT NULL,
  codigo        VARCHAR(60) DEFAULT NULL,
  descripcion   VARCHAR(200) DEFAULT NULL,
  id_color      INT NOT NULL DEFAULT 0,
  id_talla      INT NOT NULL DEFAULT 0,
  cantidad      DECIMAL(12,2) NOT NULL DEFAULT 0,
  costo_unitario DECIMAL(12,2) NOT NULL DEFAULT 0,
  importe       DECIMAL(12,2) NOT NULL DEFAULT 0,
  KEY idx_cl_compra (id_compra)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS compra_pagos (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  id_compra  INT NOT NULL,
  fecha      DATETIME NOT NULL,
  forma_pago VARCHAR(20) NOT NULL,
  importe    DECIMAL(12,2) NOT NULL DEFAULT 0,
  aplica_iva TINYINT(1) NOT NULL DEFAULT 0,
  referencia VARCHAR(80) DEFAULT NULL,
  id_usuario INT DEFAULT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_cp_compra (id_compra)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- Traspasos entre almacenes ----------
CREATE TABLE IF NOT EXISTS traspasos (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  id_empresa    INT NOT NULL,
  folio         VARCHAR(40) NOT NULL,
  fecha         DATETIME NOT NULL,
  id_almacen_origen  INT NOT NULL,
  id_almacen_destino INT NOT NULL,
  id_usuario    INT DEFAULT NULL,
  estado        ENUM('pendiente','aceptado','rechazado') NOT NULL DEFAULT 'pendiente',
  aceptado_por  INT DEFAULT NULL,
  fecha_aceptacion DATETIME DEFAULT NULL,
  notas         TEXT DEFAULT NULL,
  created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_tras_emp (id_empresa),
  KEY idx_tras_origen (id_almacen_origen),
  KEY idx_tras_destino (id_almacen_destino)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS traspaso_lineas (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  id_traspaso   INT NOT NULL,
  id_articulo   INT NOT NULL,
  codigo        VARCHAR(60) DEFAULT NULL,
  descripcion   VARCHAR(200) DEFAULT NULL,
  id_color      INT NOT NULL DEFAULT 0,
  id_talla      INT NOT NULL DEFAULT 0,
  cantidad      DECIMAL(12,2) NOT NULL DEFAULT 0,
  KEY idx_tl_traspaso (id_traspaso)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- Finanzas: Bancos ----------
CREATE TABLE IF NOT EXISTS bancos (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  id_empresa    INT NOT NULL,
  nombre        VARCHAR(120) NOT NULL,
  moneda        ENUM('MXN','USD') NOT NULL DEFAULT 'MXN',
  cuenta        VARCHAR(60) DEFAULT NULL,
  clabe         VARCHAR(40) DEFAULT NULL,
  saldo_actual  DECIMAL(14,2) NOT NULL DEFAULT 0,
  is_active     ENUM('Si','No') NOT NULL DEFAULT 'Si',
  created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_banco_emp (id_empresa)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- Finanzas: ledger de clientes (CxC) ----------
CREATE TABLE IF NOT EXISTS cliente_movimientos (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  id_empresa    INT NOT NULL,
  id_cliente    INT NOT NULL,
  fecha         DATETIME NOT NULL,
  tipo          VARCHAR(20) NOT NULL,           -- venta|abono|devolucion|cambio|bonificacion|cargo|monedero
  concepto      VARCHAR(200) DEFAULT NULL,
  monto         DECIMAL(14,2) NOT NULL DEFAULT 0,
  efecto        ENUM('cargo','abono','info') NOT NULL DEFAULT 'info',
  moneda        VARCHAR(5) NOT NULL DEFAULT 'MXN',
  id_banco      INT DEFAULT NULL,
  anulado       TINYINT(1) NOT NULL DEFAULT 0,
  ref_tipo      VARCHAR(30) DEFAULT NULL,
  id_referencia INT DEFAULT NULL,
  created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_climov_cli (id_cliente),
  KEY idx_climov_emp (id_empresa)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- Finanzas: monedero (saldo a favor) ----------
CREATE TABLE IF NOT EXISTS monedero_movimientos (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  id_empresa    INT NOT NULL,
  id_cliente    INT NOT NULL,
  tipo          VARCHAR(20) NOT NULL,           -- deposito|gasto|ajuste
  importe       DECIMAL(14,2) NOT NULL DEFAULT 0,  -- con signo
  origen        VARCHAR(200) DEFAULT NULL,
  ref_tipo      VARCHAR(30) DEFAULT NULL,
  id_referencia INT DEFAULT NULL,
  created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_monmov_cli (id_cliente),
  KEY idx_monmov_emp (id_empresa)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- Finanzas: ledger de proveedores (CxP) ----------
CREATE TABLE IF NOT EXISTS proveedor_movimientos (
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
  KEY idx_provmov_prov (id_proveedor),
  KEY idx_provmov_emp (id_empresa)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- Apartados ----------
CREATE TABLE IF NOT EXISTS apartados (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  id_empresa    INT NOT NULL,
  folio         VARCHAR(40) NOT NULL,
  fecha         DATETIME NOT NULL,
  fecha_limite  DATE DEFAULT NULL,
  id_cliente    INT NOT NULL,
  id_almacen    INT NOT NULL,
  id_vendedor   INT DEFAULT NULL,
  id_usuario    INT DEFAULT NULL,
  total         DECIMAL(14,2) NOT NULL DEFAULT 0,
  anticipo      DECIMAL(14,2) NOT NULL DEFAULT 0,
  estado        ENUM('vigente','con_anticipo','vencido','liquidado','cancelado') NOT NULL DEFAULT 'vigente',
  id_venta      INT DEFAULT NULL,               -- venta generada al liquidar
  notas         TEXT DEFAULT NULL,
  created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_apt_emp (id_empresa),
  KEY idx_apt_cli (id_cliente)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS apartado_lineas (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  id_apartado   INT NOT NULL,
  id_articulo   INT NOT NULL,
  codigo        VARCHAR(60) DEFAULT NULL,
  descripcion   VARCHAR(200) DEFAULT NULL,
  id_color      INT NOT NULL DEFAULT 0,
  id_talla      INT NOT NULL DEFAULT 0,
  cantidad      DECIMAL(12,2) NOT NULL DEFAULT 0,
  precio_unitario DECIMAL(12,2) NOT NULL DEFAULT 0,
  importe       DECIMAL(12,2) NOT NULL DEFAULT 0,
  KEY idx_aptl_apt (id_apartado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS apartado_anticipos (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  id_apartado   INT NOT NULL,
  fecha         DATETIME NOT NULL,
  forma         VARCHAR(20) NOT NULL DEFAULT 'efectivo',
  importe       DECIMAL(12,2) NOT NULL DEFAULT 0,
  id_usuario    INT DEFAULT NULL,
  KEY idx_apta_apt (id_apartado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- Devoluciones ----------
CREATE TABLE IF NOT EXISTS devoluciones (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  id_empresa    INT NOT NULL,
  folio         VARCHAR(40) NOT NULL,
  fecha         DATETIME NOT NULL,
  id_venta      INT DEFAULT NULL,
  folio_venta   VARCHAR(40) DEFAULT NULL,
  id_cliente    INT DEFAULT NULL,
  id_almacen    INT DEFAULT NULL,
  total         DECIMAL(14,2) NOT NULL DEFAULT 0,
  destino_saldo ENUM('monedero','cxc') NOT NULL DEFAULT 'monedero',
  id_usuario    INT DEFAULT NULL,
  created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_dev_emp (id_empresa),
  KEY idx_dev_venta (id_venta)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS devolucion_lineas (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  id_devolucion INT NOT NULL,
  id_articulo   INT NOT NULL,
  codigo        VARCHAR(60) DEFAULT NULL,
  descripcion   VARCHAR(200) DEFAULT NULL,
  id_color      INT NOT NULL DEFAULT 0,
  id_talla      INT NOT NULL DEFAULT 0,
  cantidad      DECIMAL(12,2) NOT NULL DEFAULT 0,
  precio_unitario DECIMAL(12,2) NOT NULL DEFAULT 0,
  importe       DECIMAL(12,2) NOT NULL DEFAULT 0,
  KEY idx_devl_dev (id_devolucion)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- Cambios ----------
CREATE TABLE IF NOT EXISTS cambios (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  id_empresa    INT NOT NULL,
  folio         VARCHAR(40) NOT NULL,
  fecha         DATETIME NOT NULL,
  id_venta      INT DEFAULT NULL,
  folio_venta   VARCHAR(40) DEFAULT NULL,
  id_cliente    INT DEFAULT NULL,
  id_almacen    INT DEFAULT NULL,
  total_devuelto DECIMAL(14,2) NOT NULL DEFAULT 0,
  total_nuevo   DECIMAL(14,2) NOT NULL DEFAULT 0,
  diferencia    DECIMAL(14,2) NOT NULL DEFAULT 0,
  pago_diferencia DECIMAL(14,2) NOT NULL DEFAULT 0,
  id_usuario    INT DEFAULT NULL,
  created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_camb_emp (id_empresa)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cambio_lineas (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  id_cambio     INT NOT NULL,
  rol           ENUM('devuelta','nueva') NOT NULL,
  id_articulo   INT NOT NULL,
  codigo        VARCHAR(60) DEFAULT NULL,
  descripcion   VARCHAR(200) DEFAULT NULL,
  id_color      INT NOT NULL DEFAULT 0,
  id_talla      INT NOT NULL DEFAULT 0,
  cantidad      DECIMAL(12,2) NOT NULL DEFAULT 0,
  precio_unitario DECIMAL(12,2) NOT NULL DEFAULT 0,
  importe       DECIMAL(12,2) NOT NULL DEFAULT 0,
  KEY idx_cambl_camb (id_cambio)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- Comisiones (por venta) ----------
CREATE TABLE IF NOT EXISTS comisiones (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  id_empresa    INT NOT NULL,
  id_empleado   INT NOT NULL,
  id_venta      INT DEFAULT NULL,
  folio_venta   VARCHAR(40) DEFAULT NULL,
  base          DECIMAL(14,2) NOT NULL DEFAULT 0,
  porcentaje    DECIMAL(6,2) NOT NULL DEFAULT 0,
  importe       DECIMAL(14,2) NOT NULL DEFAULT 0,
  pagada        ENUM('Si','No') NOT NULL DEFAULT 'No',
  fecha_pago    DATETIME DEFAULT NULL,
  anulada       TINYINT(1) NOT NULL DEFAULT 0,
  created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_comis_emp (id_empresa),
  KEY idx_comis_empleado (id_empleado),
  KEY idx_comis_venta (id_venta)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- Bitacora / auditoria ----------
CREATE TABLE IF NOT EXISTS audit_log (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  id_empresa    INT NOT NULL,
  id_usuario    INT DEFAULT NULL,
  usuario       VARCHAR(160) DEFAULT NULL,
  accion        VARCHAR(60) NOT NULL,
  entidad       VARCHAR(60) DEFAULT NULL,
  id_entidad    VARCHAR(40) DEFAULT NULL,
  descripcion   VARCHAR(255) DEFAULT NULL,
  ip            VARCHAR(50) DEFAULT NULL,
  created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_audit_emp (id_empresa),
  KEY idx_audit_fecha (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- Cortes de caja (snapshot del dia) ----------
CREATE TABLE IF NOT EXISTS cortes (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  id_empresa  INT NOT NULL,
  folio       VARCHAR(40) NOT NULL,
  fecha       DATE NOT NULL,
  id_almacen  INT NOT NULL,
  id_usuario  INT DEFAULT NULL,
  fondo       DECIMAL(12,2) NOT NULL DEFAULT 0,
  efectivo_contado DECIMAL(12,2) NOT NULL DEFAULT 0,
  diferencia  DECIMAL(12,2) NOT NULL DEFAULT 0,
  resumen     JSON DEFAULT NULL,
  detalle_notas JSON DEFAULT NULL,
  notas       TEXT DEFAULT NULL,
  created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_corte_alm_fecha (id_almacen, fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- RELACIONES (FOREIGN KEYS)
-- Se agregan al final para no depender del orden de creacion.
-- NOTA: id_color / id_talla usan el centinela 0 (= "sin variante") y NO
-- referencian atributo_valores, por eso NO llevan FK. Las columnas
-- id_referencia (+ ref_tipo) son polimorficas y tampoco pueden llevar FK.
-- Maestros con borrado logico (is_active) usan RESTRICT: nunca se borran en
-- fisico, la FK solo blinda contra inserciones invalidas. El detalle de cada
-- documento va con CASCADE (muere con su encabezado).
-- ============================================================

ALTER TABLE users
  ADD CONSTRAINT fk_users_empresa FOREIGN KEY (id_empresa) REFERENCES empresas(id) ON DELETE CASCADE,
  ADD CONSTRAINT fk_users_tienda  FOREIGN KEY (id_tienda)  REFERENCES almacenes(id) ON DELETE SET NULL;

ALTER TABLE almacenes
  ADD CONSTRAINT fk_alm_empresa FOREIGN KEY (id_empresa) REFERENCES empresas(id) ON DELETE CASCADE;

ALTER TABLE colores          ADD CONSTRAINT fk_col_empresa    FOREIGN KEY (id_empresa) REFERENCES empresas(id) ON DELETE CASCADE;
ALTER TABLE tallas           ADD CONSTRAINT fk_tal_empresa    FOREIGN KEY (id_empresa) REFERENCES empresas(id) ON DELETE CASCADE;
ALTER TABLE familias         ADD CONSTRAINT fk_fam_empresa    FOREIGN KEY (id_empresa) REFERENCES empresas(id) ON DELETE CASCADE;
ALTER TABLE lineas           ADD CONSTRAINT fk_lin_empresa    FOREIGN KEY (id_empresa) REFERENCES empresas(id) ON DELETE CASCADE;
ALTER TABLE colecciones      ADD CONSTRAINT fk_colec_empresa  FOREIGN KEY (id_empresa) REFERENCES empresas(id) ON DELETE CASCADE;
ALTER TABLE cortes_catalogo  ADD CONSTRAINT fk_cortecat_empresa FOREIGN KEY (id_empresa) REFERENCES empresas(id) ON DELETE CASCADE;
ALTER TABLE marcas           ADD CONSTRAINT fk_mar_empresa    FOREIGN KEY (id_empresa) REFERENCES empresas(id) ON DELETE CASCADE;
ALTER TABLE conceptos_gasto  ADD CONSTRAINT fk_cg_empresa     FOREIGN KEY (id_empresa) REFERENCES empresas(id) ON DELETE CASCADE;
ALTER TABLE atributos        ADD CONSTRAINT fk_atr_empresa    FOREIGN KEY (id_empresa) REFERENCES empresas(id) ON DELETE CASCADE;

ALTER TABLE atributo_valores
  ADD CONSTRAINT fk_atrval_empresa FOREIGN KEY (id_empresa)  REFERENCES empresas(id)   ON DELETE CASCADE,
  ADD CONSTRAINT fk_atrval_atr     FOREIGN KEY (id_atributo) REFERENCES atributos(id)  ON DELETE CASCADE;

ALTER TABLE categorias
  ADD CONSTRAINT fk_cat_empresa FOREIGN KEY (id_empresa)       REFERENCES empresas(id)  ON DELETE CASCADE,
  ADD CONSTRAINT fk_cat_eje1    FOREIGN KEY (id_atributo_eje1) REFERENCES atributos(id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_cat_eje2    FOREIGN KEY (id_atributo_eje2) REFERENCES atributos(id) ON DELETE SET NULL;

ALTER TABLE articulos
  ADD CONSTRAINT fk_art_empresa   FOREIGN KEY (id_empresa)   REFERENCES empresas(id)        ON DELETE CASCADE,
  ADD CONSTRAINT fk_art_familia   FOREIGN KEY (id_familia)   REFERENCES familias(id)         ON DELETE SET NULL,
  ADD CONSTRAINT fk_art_linea     FOREIGN KEY (id_linea)     REFERENCES lineas(id)           ON DELETE SET NULL,
  ADD CONSTRAINT fk_art_coleccion FOREIGN KEY (id_coleccion) REFERENCES colecciones(id)      ON DELETE SET NULL,
  ADD CONSTRAINT fk_art_corte     FOREIGN KEY (id_corte)     REFERENCES cortes_catalogo(id)  ON DELETE SET NULL,
  ADD CONSTRAINT fk_art_marca     FOREIGN KEY (id_marca)     REFERENCES marcas(id)           ON DELETE SET NULL,
  ADD CONSTRAINT fk_art_categoria FOREIGN KEY (id_categoria) REFERENCES categorias(id)       ON DELETE SET NULL;

ALTER TABLE articulo_colores
  ADD CONSTRAINT fk_artcol_art FOREIGN KEY (id_articulo) REFERENCES articulos(id)        ON DELETE CASCADE,
  ADD CONSTRAINT fk_artcol_val FOREIGN KEY (id_color)    REFERENCES atributo_valores(id) ON DELETE CASCADE;

ALTER TABLE articulo_tallas
  ADD CONSTRAINT fk_arttal_art FOREIGN KEY (id_articulo) REFERENCES articulos(id)        ON DELETE CASCADE,
  ADD CONSTRAINT fk_arttal_val FOREIGN KEY (id_talla)    REFERENCES atributo_valores(id) ON DELETE CASCADE;

ALTER TABLE articulo_componentes
  ADD CONSTRAINT fk_artcomp_kit  FOREIGN KEY (id_kit)        REFERENCES articulos(id) ON DELETE CASCADE,
  ADD CONSTRAINT fk_artcomp_comp FOREIGN KEY (id_componente) REFERENCES articulos(id) ON DELETE RESTRICT;

ALTER TABLE articulo_variante_codigos
  ADD CONSTRAINT fk_avc_empresa FOREIGN KEY (id_empresa)  REFERENCES empresas(id)  ON DELETE CASCADE,
  ADD CONSTRAINT fk_avc_art     FOREIGN KEY (id_articulo) REFERENCES articulos(id) ON DELETE CASCADE;

ALTER TABLE articulo_fotos
  ADD CONSTRAINT fk_artfoto_empresa FOREIGN KEY (id_empresa)  REFERENCES empresas(id)  ON DELETE CASCADE,
  ADD CONSTRAINT fk_artfoto_art     FOREIGN KEY (id_articulo) REFERENCES articulos(id) ON DELETE CASCADE;

ALTER TABLE inventario
  ADD CONSTRAINT fk_inv_empresa FOREIGN KEY (id_empresa)  REFERENCES empresas(id)  ON DELETE CASCADE,
  ADD CONSTRAINT fk_inv_art     FOREIGN KEY (id_articulo) REFERENCES articulos(id) ON DELETE CASCADE,
  ADD CONSTRAINT fk_inv_alm     FOREIGN KEY (id_almacen)  REFERENCES almacenes(id) ON DELETE CASCADE;

ALTER TABLE inventario_movimientos
  ADD CONSTRAINT fk_mov_empresa FOREIGN KEY (id_empresa)  REFERENCES empresas(id)  ON DELETE CASCADE,
  ADD CONSTRAINT fk_mov_art     FOREIGN KEY (id_articulo) REFERENCES articulos(id) ON DELETE RESTRICT,
  ADD CONSTRAINT fk_mov_alm     FOREIGN KEY (id_almacen)  REFERENCES almacenes(id) ON DELETE RESTRICT,
  ADD CONSTRAINT fk_mov_user    FOREIGN KEY (id_usuario)  REFERENCES users(id)     ON DELETE SET NULL;

ALTER TABLE clientes
  ADD CONSTRAINT fk_cli_empresa  FOREIGN KEY (id_empresa)             REFERENCES empresas(id)  ON DELETE CASCADE,
  ADD CONSTRAINT fk_cli_tienda   FOREIGN KEY (id_tienda)              REFERENCES almacenes(id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_cli_vend     FOREIGN KEY (id_vendedor)            REFERENCES empleados(id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_cli_autoriza FOREIGN KEY (credito_autorizado_por) REFERENCES users(id)     ON DELETE SET NULL;

ALTER TABLE empleados
  ADD CONSTRAINT fk_empl_empresa FOREIGN KEY (id_empresa) REFERENCES empresas(id)  ON DELETE CASCADE,
  ADD CONSTRAINT fk_empl_tienda  FOREIGN KEY (id_tienda)  REFERENCES almacenes(id) ON DELETE SET NULL;

ALTER TABLE proveedores
  ADD CONSTRAINT fk_prov_empresa FOREIGN KEY (id_empresa) REFERENCES empresas(id) ON DELETE CASCADE;

ALTER TABLE ventas
  ADD CONSTRAINT fk_venta_empresa FOREIGN KEY (id_empresa)    REFERENCES empresas(id)  ON DELETE CASCADE,
  ADD CONSTRAINT fk_venta_alm     FOREIGN KEY (id_almacen)    REFERENCES almacenes(id) ON DELETE RESTRICT,
  ADD CONSTRAINT fk_venta_cli     FOREIGN KEY (id_cliente)    REFERENCES clientes(id)  ON DELETE SET NULL,
  ADD CONSTRAINT fk_venta_vend    FOREIGN KEY (id_vendedor)   REFERENCES empleados(id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_venta_user    FOREIGN KEY (id_usuario)    REFERENCES users(id)     ON DELETE SET NULL,
  ADD CONSTRAINT fk_venta_cancela FOREIGN KEY (cancelada_por) REFERENCES users(id)     ON DELETE SET NULL;

ALTER TABLE venta_lineas
  ADD CONSTRAINT fk_vl_venta FOREIGN KEY (id_venta)    REFERENCES ventas(id)    ON DELETE CASCADE,
  ADD CONSTRAINT fk_vl_art   FOREIGN KEY (id_articulo) REFERENCES articulos(id) ON DELETE RESTRICT;

ALTER TABLE venta_pagos
  ADD CONSTRAINT fk_vp_venta FOREIGN KEY (id_venta) REFERENCES ventas(id) ON DELETE CASCADE,
  ADD CONSTRAINT fk_vp_banco FOREIGN KEY (id_banco) REFERENCES bancos(id) ON DELETE SET NULL;

ALTER TABLE compras
  ADD CONSTRAINT fk_compra_empresa FOREIGN KEY (id_empresa)   REFERENCES empresas(id)    ON DELETE CASCADE,
  ADD CONSTRAINT fk_compra_prov    FOREIGN KEY (id_proveedor) REFERENCES proveedores(id) ON DELETE RESTRICT,
  ADD CONSTRAINT fk_compra_alm     FOREIGN KEY (id_almacen)   REFERENCES almacenes(id)   ON DELETE RESTRICT,
  ADD CONSTRAINT fk_compra_user    FOREIGN KEY (id_usuario)   REFERENCES users(id)       ON DELETE SET NULL,
  ADD CONSTRAINT fk_compra_aprueba FOREIGN KEY (aprobada_por) REFERENCES users(id)       ON DELETE SET NULL;

ALTER TABLE compra_lineas
  ADD CONSTRAINT fk_cl_compra FOREIGN KEY (id_compra)   REFERENCES compras(id)   ON DELETE CASCADE,
  ADD CONSTRAINT fk_cl_art    FOREIGN KEY (id_articulo) REFERENCES articulos(id) ON DELETE RESTRICT;

ALTER TABLE compra_pagos
  ADD CONSTRAINT fk_cp_compra FOREIGN KEY (id_compra)  REFERENCES compras(id) ON DELETE CASCADE,
  ADD CONSTRAINT fk_cp_user   FOREIGN KEY (id_usuario) REFERENCES users(id)   ON DELETE SET NULL;

ALTER TABLE traspasos
  ADD CONSTRAINT fk_tras_empresa FOREIGN KEY (id_empresa)         REFERENCES empresas(id)  ON DELETE CASCADE,
  ADD CONSTRAINT fk_tras_origen  FOREIGN KEY (id_almacen_origen)  REFERENCES almacenes(id) ON DELETE RESTRICT,
  ADD CONSTRAINT fk_tras_destino FOREIGN KEY (id_almacen_destino) REFERENCES almacenes(id) ON DELETE RESTRICT,
  ADD CONSTRAINT fk_tras_user    FOREIGN KEY (id_usuario)         REFERENCES users(id)     ON DELETE SET NULL,
  ADD CONSTRAINT fk_tras_acepta  FOREIGN KEY (aceptado_por)       REFERENCES users(id)     ON DELETE SET NULL;

ALTER TABLE traspaso_lineas
  ADD CONSTRAINT fk_tl_traspaso FOREIGN KEY (id_traspaso) REFERENCES traspasos(id) ON DELETE CASCADE,
  ADD CONSTRAINT fk_tl_art      FOREIGN KEY (id_articulo) REFERENCES articulos(id) ON DELETE RESTRICT;

ALTER TABLE bancos
  ADD CONSTRAINT fk_banco_empresa FOREIGN KEY (id_empresa) REFERENCES empresas(id) ON DELETE CASCADE;

ALTER TABLE cliente_movimientos
  ADD CONSTRAINT fk_climov_empresa FOREIGN KEY (id_empresa) REFERENCES empresas(id) ON DELETE CASCADE,
  ADD CONSTRAINT fk_climov_cli     FOREIGN KEY (id_cliente) REFERENCES clientes(id) ON DELETE RESTRICT,
  ADD CONSTRAINT fk_climov_banco   FOREIGN KEY (id_banco)   REFERENCES bancos(id)   ON DELETE SET NULL;

ALTER TABLE monedero_movimientos
  ADD CONSTRAINT fk_monmov_empresa FOREIGN KEY (id_empresa) REFERENCES empresas(id) ON DELETE CASCADE,
  ADD CONSTRAINT fk_monmov_cli     FOREIGN KEY (id_cliente) REFERENCES clientes(id) ON DELETE RESTRICT;

ALTER TABLE proveedor_movimientos
  ADD CONSTRAINT fk_provmov_empresa FOREIGN KEY (id_empresa)   REFERENCES empresas(id)    ON DELETE CASCADE,
  ADD CONSTRAINT fk_provmov_prov    FOREIGN KEY (id_proveedor) REFERENCES proveedores(id) ON DELETE RESTRICT,
  ADD CONSTRAINT fk_provmov_banco   FOREIGN KEY (id_banco)     REFERENCES bancos(id)      ON DELETE SET NULL;

ALTER TABLE apartados
  ADD CONSTRAINT fk_apt_empresa FOREIGN KEY (id_empresa)  REFERENCES empresas(id)  ON DELETE CASCADE,
  ADD CONSTRAINT fk_apt_cli     FOREIGN KEY (id_cliente)  REFERENCES clientes(id)  ON DELETE RESTRICT,
  ADD CONSTRAINT fk_apt_alm     FOREIGN KEY (id_almacen)  REFERENCES almacenes(id) ON DELETE RESTRICT,
  ADD CONSTRAINT fk_apt_vend    FOREIGN KEY (id_vendedor) REFERENCES empleados(id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_apt_user    FOREIGN KEY (id_usuario)  REFERENCES users(id)     ON DELETE SET NULL,
  ADD CONSTRAINT fk_apt_venta   FOREIGN KEY (id_venta)    REFERENCES ventas(id)    ON DELETE SET NULL;

ALTER TABLE apartado_lineas
  ADD CONSTRAINT fk_aptl_apt FOREIGN KEY (id_apartado) REFERENCES apartados(id) ON DELETE CASCADE,
  ADD CONSTRAINT fk_aptl_art FOREIGN KEY (id_articulo) REFERENCES articulos(id) ON DELETE RESTRICT;

ALTER TABLE apartado_anticipos
  ADD CONSTRAINT fk_apta_apt  FOREIGN KEY (id_apartado) REFERENCES apartados(id) ON DELETE CASCADE,
  ADD CONSTRAINT fk_apta_user FOREIGN KEY (id_usuario)  REFERENCES users(id)     ON DELETE SET NULL;

ALTER TABLE devoluciones
  ADD CONSTRAINT fk_dev_empresa FOREIGN KEY (id_empresa) REFERENCES empresas(id)  ON DELETE CASCADE,
  ADD CONSTRAINT fk_dev_venta   FOREIGN KEY (id_venta)   REFERENCES ventas(id)    ON DELETE SET NULL,
  ADD CONSTRAINT fk_dev_cli     FOREIGN KEY (id_cliente) REFERENCES clientes(id)  ON DELETE SET NULL,
  ADD CONSTRAINT fk_dev_alm     FOREIGN KEY (id_almacen) REFERENCES almacenes(id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_dev_user    FOREIGN KEY (id_usuario) REFERENCES users(id)     ON DELETE SET NULL;

ALTER TABLE devolucion_lineas
  ADD CONSTRAINT fk_devl_dev FOREIGN KEY (id_devolucion) REFERENCES devoluciones(id) ON DELETE CASCADE,
  ADD CONSTRAINT fk_devl_art FOREIGN KEY (id_articulo)   REFERENCES articulos(id)    ON DELETE RESTRICT;

ALTER TABLE cambios
  ADD CONSTRAINT fk_camb_empresa FOREIGN KEY (id_empresa) REFERENCES empresas(id)  ON DELETE CASCADE,
  ADD CONSTRAINT fk_camb_venta   FOREIGN KEY (id_venta)   REFERENCES ventas(id)    ON DELETE SET NULL,
  ADD CONSTRAINT fk_camb_cli     FOREIGN KEY (id_cliente) REFERENCES clientes(id)  ON DELETE SET NULL,
  ADD CONSTRAINT fk_camb_alm     FOREIGN KEY (id_almacen) REFERENCES almacenes(id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_camb_user    FOREIGN KEY (id_usuario) REFERENCES users(id)     ON DELETE SET NULL;

ALTER TABLE cambio_lineas
  ADD CONSTRAINT fk_cambl_camb FOREIGN KEY (id_cambio)   REFERENCES cambios(id)   ON DELETE CASCADE,
  ADD CONSTRAINT fk_cambl_art  FOREIGN KEY (id_articulo) REFERENCES articulos(id) ON DELETE RESTRICT;

ALTER TABLE comisiones
  ADD CONSTRAINT fk_comis_empresa FOREIGN KEY (id_empresa)  REFERENCES empresas(id)  ON DELETE CASCADE,
  ADD CONSTRAINT fk_comis_empl    FOREIGN KEY (id_empleado) REFERENCES empleados(id) ON DELETE RESTRICT,
  ADD CONSTRAINT fk_comis_venta   FOREIGN KEY (id_venta)    REFERENCES ventas(id)    ON DELETE SET NULL;

ALTER TABLE audit_log
  ADD CONSTRAINT fk_audit_empresa FOREIGN KEY (id_empresa) REFERENCES empresas(id) ON DELETE CASCADE,
  ADD CONSTRAINT fk_audit_user    FOREIGN KEY (id_usuario) REFERENCES users(id)    ON DELETE SET NULL;

ALTER TABLE cortes
  ADD CONSTRAINT fk_corte_empresa FOREIGN KEY (id_empresa) REFERENCES empresas(id)  ON DELETE CASCADE,
  ADD CONSTRAINT fk_corte_alm     FOREIGN KEY (id_almacen) REFERENCES almacenes(id) ON DELETE RESTRICT,
  ADD CONSTRAINT fk_corte_user    FOREIGN KEY (id_usuario) REFERENCES users(id)     ON DELETE SET NULL;

SET FOREIGN_KEY_CHECKS = 1;
