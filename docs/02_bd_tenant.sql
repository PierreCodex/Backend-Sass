-- ============================================================================
-- BD DE TENANT — tenant_{slug}  (una por negocio, creada por stancl/tenancy)
-- Se genera con `php artisan tenants:migrate` desde migrations/tenant/.
--
-- Reglas de este esquema:
--   1. NO existe negocio_id: la base ES el negocio.
--   2. Los usuarios viven en la BD CENTRAL. Aquí se referencian por
--      *_user_id SIN foreign key (MySQL no permite FK entre bases).
--      La integridad la garantiza la aplicación.
--   3. `profesionales` y `clientes` son PERFILES locales del tenant que
--      apuntan a un user central (o a nadie, en el caso de clientes walk-in).
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------------------------------------------------------
-- 1. PROFESIONALES — perfil laboral local (antes mezclado en users)
--    Saca de la tabla central: cargo, tipo_pago, comisión, sueldo, horario.
--    central_user_id apunta a users.id de la BD central (sin FK).
-- ----------------------------------------------------------------------------
CREATE TABLE `profesionales` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `central_user_id` BIGINT UNSIGNED NOT NULL,       -- → central.users.id (sin FK)
  `nombre` VARCHAR(150) NOT NULL,                   -- denormalizado p/ mostrar sin ir a central
  `cargo` VARCHAR(100) NULL,
  `foto` VARCHAR(255) NULL,
  `telefono` VARCHAR(30) NULL,
  `tipo_pago` ENUM('comision','sueldo','mixto') NOT NULL DEFAULT 'comision',
  `comision_pct` DECIMAL(5,2) NOT NULL DEFAULT 50.00,
  `sueldo_monto` DECIMAL(10,2) NULL,
  `sueldo_periodo` ENUM('mensual','quincenal','semanal') NULL,
  `horario` JSON NULL,                              -- {dias:{...}, excepciones:[...]}
  `activo` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  `deleted_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `profesionales_central_user_unique` (`central_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 2. CLIENTES — la entidad que HOY NO EXISTE (cliente_id era NULL en todas
--    las citas del dump). Permite historial, recompra y ficha del cliente.
--    central_user_id se llena solo si el cliente creó cuenta.
-- ----------------------------------------------------------------------------
CREATE TABLE `clientes` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `central_user_id` BIGINT UNSIGNED NULL,           -- → central.users.id (sin FK)
  `nombre` VARCHAR(150) NOT NULL,
  `apellido` VARCHAR(150) NULL,
  `telefono` VARCHAR(30) NULL,
  `email` VARCHAR(150) NULL,
  `documento` VARCHAR(30) NULL,
  `fecha_nacimiento` DATE NULL,
  `notas` TEXT NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  `deleted_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  KEY `clientes_telefono_idx` (`telefono`),
  KEY `clientes_documento_idx` (`documento`),
  KEY `clientes_email_idx` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 3. LOCALES Y SU RELACIÓN CON PROFESIONALES
-- ----------------------------------------------------------------------------
CREATE TABLE `locales` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nombre` VARCHAR(150) NOT NULL,
  `direccion` VARCHAR(255) NULL,
  `telefono` VARCHAR(30) NULL,
  `latitud` DECIMAL(10,8) NULL,
  `longitud` DECIMAL(11,8) NULL,
  `horario` JSON NULL,
  `activo` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  `deleted_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `local_profesional` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `local_id` BIGINT UNSIGNED NOT NULL,
  `profesional_id` BIGINT UNSIGNED NOT NULL,        -- antes user_id; ahora FK local REAL
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `local_prof_unique` (`local_id`, `profesional_id`),
  CONSTRAINT `lp_local_fk` FOREIGN KEY (`local_id`)
    REFERENCES `locales` (`id`) ON DELETE CASCADE,
  CONSTRAINT `lp_prof_fk` FOREIGN KEY (`profesional_id`)
    REFERENCES `profesionales` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 4. CATÁLOGO: CATEGORÍAS, SERVICIOS, PRODUCTOS
--    Los uniques ya no llevan negocio_id: la base entera es del negocio.
-- ----------------------------------------------------------------------------
CREATE TABLE `categoria_servicios` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nombre` VARCHAR(100) NOT NULL,
  `orden` INT UNSIGNED NOT NULL DEFAULT 0,
  `activo` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `catserv_nombre_unique` (`nombre`)      -- antes (negocio_id, nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `servicios` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `categoria_servicio_id` BIGINT UNSIGNED NULL,
  `nombre` VARCHAR(150) NOT NULL,
  `descripcion` TEXT NULL,
  `precio` DECIMAL(10,2) NOT NULL DEFAULT 0,
  `duracion_min` INT UNSIGNED NOT NULL DEFAULT 30,
  `visible_publico` TINYINT(1) NOT NULL DEFAULT 1,
  `activo` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  `deleted_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `servicios_nombre_unique` (`nombre`),
  CONSTRAINT `serv_cat_fk` FOREIGN KEY (`categoria_servicio_id`)
    REFERENCES `categoria_servicios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `servicio_imagenes` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `servicio_id` BIGINT UNSIGNED NOT NULL,
  `ruta` VARCHAR(255) NOT NULL,
  `orden` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `simg_serv_fk` FOREIGN KEY (`servicio_id`)
    REFERENCES `servicios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `servicio_profesional` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `servicio_id` BIGINT UNSIGNED NOT NULL,
  `profesional_id` BIGINT UNSIGNED NOT NULL,        -- FK local REAL (antes user_id cross-DB)
  `precio_override` DECIMAL(10,2) NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `serv_prof_unique` (`servicio_id`, `profesional_id`),
  CONSTRAINT `sp_serv_fk` FOREIGN KEY (`servicio_id`)
    REFERENCES `servicios` (`id`) ON DELETE CASCADE,
  CONSTRAINT `sp_prof_fk` FOREIGN KEY (`profesional_id`)
    REFERENCES `profesionales` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `productos` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nombre` VARCHAR(150) NOT NULL,
  `descripcion` TEXT NULL,
  `precio` DECIMAL(10,2) NOT NULL DEFAULT 0,
  `costo` DECIMAL(10,2) NULL,
  `stock` INT NOT NULL DEFAULT 0,
  `stock_minimo` INT UNSIGNED NOT NULL DEFAULT 0,
  `activo` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  `deleted_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `productos_nombre_unique` (`nombre`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 5. CITAS — el corazón, con los 3 arreglos importantes:
--    a) starts_at/ends_at DATETIME (antes fecha+hora separadas). Se guardan
--       en la zona horaria del tenant (tenants.zona_horaria en la central).
--    b) UNA fuente de verdad para servicios: cita_servicio. Se ELIMINA
--       citas.servicio_id (la dualidad detectada en el dump).
--    c) cliente_id obligatorio hacia la nueva tabla clientes. Los datos
--       denormalizados desaparecen: el walk-in se registra como cliente.
--
--    Anti-solape: MySQL no tiene EXCLUDE (eso es Postgres). Estrategia aquí:
--       - Índice (profesional_id, starts_at) para búsquedas de conflicto
--       - En el service de reserva: transacción + SELECT ... FOR UPDATE
--         sobre las citas del profesional en la ventana, validar, insertar.
--       Si algún día migran a Postgres, cambiar por EXCLUDE USING gist.
-- ----------------------------------------------------------------------------
CREATE TABLE `citas` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `codigo` CHAR(8) NOT NULL,                        -- código público p/ el cliente (ABC12345)
  `local_id` BIGINT UNSIGNED NULL,
  `profesional_id` BIGINT UNSIGNED NOT NULL,        -- antes user_id
  `cliente_id` BIGINT UNSIGNED NOT NULL,            -- YA NO NULL: siempre hay cliente
  `starts_at` DATETIME NOT NULL,
  `ends_at` DATETIME NOT NULL,
  `estado` ENUM('pendiente','confirmada','en_curso','completada','cancelada','no_asistio')
           NOT NULL DEFAULT 'pendiente',
  `notas` VARCHAR(500) NULL,
  `fuente` ENUM('publica','admin','whatsapp','api') NOT NULL DEFAULT 'publica',
  `monto_total` DECIMAL(10,2) NOT NULL DEFAULT 0,   -- suma de cita_servicio + cita_producto
  `cancelada_motivo` VARCHAR(255) NULL,
  `cancelada_el` TIMESTAMP NULL,
  `confirmada_el` TIMESTAMP NULL,
  `completada_el` TIMESTAMP NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `citas_codigo_unique` (`codigo`),
  KEY `citas_prof_inicio_idx` (`profesional_id`, `starts_at`),   -- consulta de solapes
  KEY `citas_cliente_idx` (`cliente_id`, `starts_at`),           -- historial del cliente
  KEY `citas_estado_idx` (`estado`, `starts_at`),
  CONSTRAINT `citas_local_fk` FOREIGN KEY (`local_id`)
    REFERENCES `locales` (`id`) ON DELETE SET NULL,
  CONSTRAINT `citas_prof_fk` FOREIGN KEY (`profesional_id`)
    REFERENCES `profesionales` (`id`),
  CONSTRAINT `citas_cliente_fk` FOREIGN KEY (`cliente_id`)
    REFERENCES `clientes` (`id`),
  CONSTRAINT `citas_horario_chk` CHECK (`ends_at` > `starts_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `cita_servicio` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `cita_id` BIGINT UNSIGNED NOT NULL,
  `servicio_id` BIGINT UNSIGNED NOT NULL,
  `cantidad` INT UNSIGNED NOT NULL DEFAULT 1,
  `precio` DECIMAL(10,2) NOT NULL DEFAULT 0,        -- precio congelado al reservar
  `duracion_min` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `cs_cita_serv_unique` (`cita_id`, `servicio_id`),
  CONSTRAINT `cs_cita_fk` FOREIGN KEY (`cita_id`)
    REFERENCES `citas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `cs_serv_fk` FOREIGN KEY (`servicio_id`)
    REFERENCES `servicios` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `cita_producto` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `cita_id` BIGINT UNSIGNED NOT NULL,
  `producto_id` BIGINT UNSIGNED NOT NULL,
  `cantidad` INT UNSIGNED NOT NULL DEFAULT 1,
  `precio` DECIMAL(10,2) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `cp_cita_fk` FOREIGN KEY (`cita_id`)
    REFERENCES `citas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `cp_prod_fk` FOREIGN KEY (`producto_id`)
    REFERENCES `productos` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 6. GRUPOS (combos de locales/profesionales/servicios)
-- ----------------------------------------------------------------------------
CREATE TABLE `grupos` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nombre` VARCHAR(100) NOT NULL,
  `descripcion` VARCHAR(255) NULL,
  `activo` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `grupos_nombre_unique` (`nombre`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `grupo_local` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `grupo_id` BIGINT UNSIGNED NOT NULL,
  `local_id` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `gl_unique` (`grupo_id`, `local_id`),
  CONSTRAINT `gl_grupo_fk` FOREIGN KEY (`grupo_id`) REFERENCES `grupos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `gl_local_fk` FOREIGN KEY (`local_id`) REFERENCES `locales` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `grupo_profesional` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `grupo_id` BIGINT UNSIGNED NOT NULL,
  `profesional_id` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `gp_unique` (`grupo_id`, `profesional_id`),
  CONSTRAINT `gp_grupo_fk` FOREIGN KEY (`grupo_id`) REFERENCES `grupos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `gp_prof_fk` FOREIGN KEY (`profesional_id`) REFERENCES `profesionales` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `grupo_servicio` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `grupo_id` BIGINT UNSIGNED NOT NULL,
  `servicio_id` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `gs_unique` (`grupo_id`, `servicio_id`),
  CONSTRAINT `gs_grupo_fk` FOREIGN KEY (`grupo_id`) REFERENCES `grupos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `gs_serv_fk` FOREIGN KEY (`servicio_id`) REFERENCES `servicios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 7. CAJA E INVENTARIO
--    *_profesional_id o registrado_por_profesional_id: FK local. Cuando la
--    acción la hace el dueño/admin (que no es profesional), se usa
--    registrado_por_user_id (id central, sin FK) — por eso ambos nullable
--    con un CHECK de que al menos uno exista.
-- ----------------------------------------------------------------------------
CREATE TABLE `caja_cierres` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `fecha` DATE NOT NULL,
  `monto_apertura` DECIMAL(12,2) NOT NULL DEFAULT 0,
  `monto_cierre_esperado` DECIMAL(12,2) NOT NULL DEFAULT 0,
  `monto_cierre_real` DECIMAL(12,2) NULL,
  `diferencia` DECIMAL(12,2) NULL,
  `registrado_por_user_id` BIGINT UNSIGNED NOT NULL, -- → central.users.id (sin FK)
  `nota` VARCHAR(255) NULL,
  `cerrada_el` TIMESTAMP NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `caja_cierres_fecha_unique` (`fecha`)   -- antes (negocio_id, fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `caja_movimientos` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `caja_cierre_id` BIGINT UNSIGNED NULL,
  `cita_id` BIGINT UNSIGNED NULL,
  `tipo` ENUM('ingreso','egreso') NOT NULL,
  `concepto` VARCHAR(150) NOT NULL,
  `monto` DECIMAL(12,2) NOT NULL,
  `metodo` ENUM('efectivo','tarjeta','yape','plin','transferencia','otro') NOT NULL DEFAULT 'efectivo',
  `registrado_por_user_id` BIGINT UNSIGNED NOT NULL, -- → central.users.id (sin FK)
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  KEY `cm_fecha_idx` (`created_at`),
  CONSTRAINT `cm_cierre_fk` FOREIGN KEY (`caja_cierre_id`)
    REFERENCES `caja_cierres` (`id`) ON DELETE SET NULL,
  CONSTRAINT `cm_cita_fk` FOREIGN KEY (`cita_id`)
    REFERENCES `citas` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `inventario_movimientos` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `producto_id` BIGINT UNSIGNED NOT NULL,
  `tipo` ENUM('entrada','salida','ajuste','venta') NOT NULL,
  `cantidad` INT NOT NULL,
  `motivo` VARCHAR(150) NULL,
  `cita_id` BIGINT UNSIGNED NULL,
  `registrado_por_user_id` BIGINT UNSIGNED NOT NULL, -- → central.users.id (sin FK)
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  KEY `im_prod_idx` (`producto_id`, `created_at`),
  CONSTRAINT `im_prod_fk` FOREIGN KEY (`producto_id`)
    REFERENCES `productos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `im_cita_fk` FOREIGN KEY (`cita_id`)
    REFERENCES `citas` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 8. PLANTILLAS WHATSAPP
-- ----------------------------------------------------------------------------
CREATE TABLE `plantilla_whatsapps` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `evento` VARCHAR(50) NOT NULL,                    -- cita_creada, recordatorio, etc.
  `mensaje` TEXT NOT NULL,
  `activo` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `pw_evento_unique` (`evento`)          -- antes (negocio_id, evento)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
