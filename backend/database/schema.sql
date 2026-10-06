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
  id_marca      INT DEFAULT NULL,
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

-- variantes disponibles (colores/tallas que aplican al articulo)
CREATE TABLE IF NOT EXISTS articulo_colores (
  id_articulo INT NOT NULL, id_color INT NOT NULL,
  PRIMARY KEY (id_articulo, id_color)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS articulo_tallas (
  id_articulo INT NOT NULL, id_talla INT NOT NULL,
  PRIMARY KEY (id_articulo, id_talla)
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
  puesto      VARCHAR(80) DEFAULT NULL,
  id_tienda   INT DEFAULT NULL,
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

SET FOREIGN_KEY_CHECKS = 1;
