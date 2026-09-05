-- =============================================================================
-- BD POR TENANT — esquema real
--
-- GENERADO desde las migraciones — no editar a mano.
-- Última actualización: 2026-09-04
--
-- Una base por negocio (tenant_{id}). No existe negocio_id en ninguna tabla: la base ES el negocio. Creada por el job de provisioning al verificar el correo.
--
-- Para regenerarlo: migrar sobre una base limpia y volcar con
--   mysqldump -u root --no-data --skip-add-drop-table --skip-set-charset
-- (ver el ritual en CLAUDE.md § Base de datos).
--
-- La jerarquía ante conflicto NO cambia: docs/discrepancias.md (congelado)
-- manda sobre este archivo; este archivo solo describe lo que existe hoy.
-- =============================================================================

-- Tabla `caja_cierres`
CREATE TABLE `caja_cierres` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `fecha` date NOT NULL,
  `monto_apertura` decimal(12,2) NOT NULL DEFAULT '0.00',
  `monto_cierre_esperado` decimal(12,2) NOT NULL DEFAULT '0.00',
  `monto_cierre_real` decimal(12,2) DEFAULT NULL,
  `diferencia` decimal(12,2) DEFAULT NULL,
  `registrado_por_user_id` bigint unsigned NOT NULL,
  `nota` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cerrada_el` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `caja_cierres_fecha_unique` (`fecha`)
);

-- Tabla `caja_movimientos`
CREATE TABLE `caja_movimientos` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `caja_cierre_id` bigint unsigned DEFAULT NULL,
  `cita_id` bigint unsigned DEFAULT NULL,
  `tipo` enum('ingreso','egreso') COLLATE utf8mb4_unicode_ci NOT NULL,
  `concepto` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `monto` decimal(12,2) NOT NULL,
  `metodo` enum('efectivo','tarjeta','yape','plin','transferencia','otro') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'efectivo',
  `registrado_por_user_id` bigint unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `caja_movimientos_caja_cierre_id_foreign` (`caja_cierre_id`),
  KEY `caja_movimientos_cita_id_foreign` (`cita_id`),
  KEY `caja_movimientos_created_at_index` (`created_at`),
  CONSTRAINT `caja_movimientos_caja_cierre_id_foreign` FOREIGN KEY (`caja_cierre_id`) REFERENCES `caja_cierres` (`id`) ON DELETE SET NULL,
  CONSTRAINT `caja_movimientos_cita_id_foreign` FOREIGN KEY (`cita_id`) REFERENCES `citas` (`id`) ON DELETE SET NULL
);

-- Tabla `categoria_servicios`
CREATE TABLE `categoria_servicios` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `nombre` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `descripcion` text COLLATE utf8mb4_unicode_ci,
  `color` char(7) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `imagen` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `orden` int unsigned NOT NULL DEFAULT '0',
  `activo` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `categoria_servicios_nombre_unique` (`nombre`)
);

-- Tabla `cita_pagos`
CREATE TABLE `cita_pagos` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `cita_id` bigint unsigned NOT NULL,
  `monto` decimal(10,2) NOT NULL,
  `comprobante` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `estado` enum('comprobante_subido','verificado','rechazado') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'comprobante_subido',
  `verificado_por_user_id` bigint unsigned DEFAULT NULL,
  `verificado_el` timestamp NULL DEFAULT NULL,
  `rechazo_motivo` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `cita_pagos_cita_id_created_at_index` (`cita_id`,`created_at`),
  CONSTRAINT `cita_pagos_cita_id_foreign` FOREIGN KEY (`cita_id`) REFERENCES `citas` (`id`) ON DELETE CASCADE
);

-- Tabla `cita_producto`
CREATE TABLE `cita_producto` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `cita_id` bigint unsigned NOT NULL,
  `producto_id` bigint unsigned NOT NULL,
  `cantidad` int unsigned NOT NULL DEFAULT '1',
  `precio` decimal(10,2) NOT NULL DEFAULT '0.00',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `cita_producto_cita_id_foreign` (`cita_id`),
  KEY `cita_producto_producto_id_foreign` (`producto_id`),
  CONSTRAINT `cita_producto_cita_id_foreign` FOREIGN KEY (`cita_id`) REFERENCES `citas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `cita_producto_producto_id_foreign` FOREIGN KEY (`producto_id`) REFERENCES `productos` (`id`)
);

-- Tabla `cita_servicio`
CREATE TABLE `cita_servicio` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `cita_id` bigint unsigned NOT NULL,
  `servicio_id` bigint unsigned NOT NULL,
  `cantidad` int unsigned NOT NULL DEFAULT '1',
  `precio` decimal(10,2) NOT NULL DEFAULT '0.00',
  `duracion_min` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `cita_servicio_cita_id_servicio_id_unique` (`cita_id`,`servicio_id`),
  KEY `cita_servicio_servicio_id_foreign` (`servicio_id`),
  CONSTRAINT `cita_servicio_cita_id_foreign` FOREIGN KEY (`cita_id`) REFERENCES `citas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `cita_servicio_servicio_id_foreign` FOREIGN KEY (`servicio_id`) REFERENCES `servicios` (`id`)
);

-- Tabla `citas`
CREATE TABLE `citas` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `codigo` char(8) COLLATE utf8mb4_unicode_ci NOT NULL,
  `local_id` bigint unsigned DEFAULT NULL,
  `profesional_id` bigint unsigned NOT NULL,
  `cliente_id` bigint unsigned NOT NULL,
  `starts_at` datetime NOT NULL,
  `ends_at` datetime NOT NULL,
  `estado` enum('pendiente','confirmada','en_curso','completada','cancelada','no_asistio') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pendiente',
  `notas` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `fuente` enum('publica','admin','whatsapp','api') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'publica',
  `monto_total` decimal(10,2) NOT NULL DEFAULT '0.00',
  `metodo_pago_eleccion` enum('ahora','local') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `estado_pago` enum('pendiente','comprobante_subido','verificado','rechazado') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cancelada_motivo` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cancelada_el` timestamp NULL DEFAULT NULL,
  `confirmada_el` timestamp NULL DEFAULT NULL,
  `completada_el` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `citas_codigo_unique` (`codigo`),
  KEY `citas_local_id_foreign` (`local_id`),
  KEY `citas_profesional_id_starts_at_index` (`profesional_id`,`starts_at`),
  KEY `citas_cliente_id_starts_at_index` (`cliente_id`,`starts_at`),
  KEY `citas_estado_starts_at_index` (`estado`,`starts_at`),
  CONSTRAINT `citas_cliente_id_foreign` FOREIGN KEY (`cliente_id`) REFERENCES `clientes` (`id`),
  CONSTRAINT `citas_local_id_foreign` FOREIGN KEY (`local_id`) REFERENCES `locales` (`id`) ON DELETE SET NULL,
  CONSTRAINT `citas_profesional_id_foreign` FOREIGN KEY (`profesional_id`) REFERENCES `profesionales` (`id`),
  CONSTRAINT `citas_horario_chk` CHECK ((`ends_at` > `starts_at`))
);

-- Tabla `clientes`
CREATE TABLE `clientes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `central_user_id` bigint unsigned DEFAULT NULL,
  `nombre` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `apellido` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `telefono` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `telefono_normalizado` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `documento` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `fecha_nacimiento` date DEFAULT NULL,
  `notas` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `clientes_telefono_normalizado_unique` (`telefono_normalizado`),
  KEY `clientes_telefono_index` (`telefono`),
  KEY `clientes_documento_index` (`documento`),
  KEY `clientes_email_index` (`email`)
);

-- Tabla `grupo_local`
CREATE TABLE `grupo_local` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `grupo_id` bigint unsigned NOT NULL,
  `local_id` bigint unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `grupo_local_grupo_id_local_id_unique` (`grupo_id`,`local_id`),
  KEY `grupo_local_local_id_foreign` (`local_id`),
  CONSTRAINT `grupo_local_grupo_id_foreign` FOREIGN KEY (`grupo_id`) REFERENCES `grupos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `grupo_local_local_id_foreign` FOREIGN KEY (`local_id`) REFERENCES `locales` (`id`) ON DELETE CASCADE
);

-- Tabla `grupo_profesional`
CREATE TABLE `grupo_profesional` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `grupo_id` bigint unsigned NOT NULL,
  `profesional_id` bigint unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `grupo_profesional_grupo_id_profesional_id_unique` (`grupo_id`,`profesional_id`),
  KEY `grupo_profesional_profesional_id_foreign` (`profesional_id`),
  CONSTRAINT `grupo_profesional_grupo_id_foreign` FOREIGN KEY (`grupo_id`) REFERENCES `grupos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `grupo_profesional_profesional_id_foreign` FOREIGN KEY (`profesional_id`) REFERENCES `profesionales` (`id`) ON DELETE CASCADE
);

-- Tabla `grupo_servicio`
CREATE TABLE `grupo_servicio` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `grupo_id` bigint unsigned NOT NULL,
  `servicio_id` bigint unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `grupo_servicio_grupo_id_servicio_id_unique` (`grupo_id`,`servicio_id`),
  KEY `grupo_servicio_servicio_id_foreign` (`servicio_id`),
  CONSTRAINT `grupo_servicio_grupo_id_foreign` FOREIGN KEY (`grupo_id`) REFERENCES `grupos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `grupo_servicio_servicio_id_foreign` FOREIGN KEY (`servicio_id`) REFERENCES `servicios` (`id`) ON DELETE CASCADE
);

-- Tabla `grupos`
CREATE TABLE `grupos` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `nombre` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `descripcion` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `grupos_nombre_unique` (`nombre`)
);

-- Tabla `inventario_movimientos`
CREATE TABLE `inventario_movimientos` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `producto_id` bigint unsigned NOT NULL,
  `tipo` enum('entrada','salida','ajuste','venta') COLLATE utf8mb4_unicode_ci NOT NULL,
  `cantidad` int NOT NULL,
  `motivo` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cita_id` bigint unsigned DEFAULT NULL,
  `registrado_por_user_id` bigint unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `inventario_movimientos_cita_id_foreign` (`cita_id`),
  KEY `inventario_movimientos_producto_id_created_at_index` (`producto_id`,`created_at`),
  CONSTRAINT `inventario_movimientos_cita_id_foreign` FOREIGN KEY (`cita_id`) REFERENCES `citas` (`id`) ON DELETE SET NULL,
  CONSTRAINT `inventario_movimientos_producto_id_foreign` FOREIGN KEY (`producto_id`) REFERENCES `productos` (`id`) ON DELETE CASCADE
);

-- Tabla `local_profesional`
CREATE TABLE `local_profesional` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `local_id` bigint unsigned NOT NULL,
  `profesional_id` bigint unsigned NOT NULL,
  `habilitado` tinyint(1) NOT NULL DEFAULT '1',
  `nombre_publico` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `perfil` text COLLATE utf8mb4_unicode_ci,
  `horario_apertura` time DEFAULT NULL,
  `horario_cierre` time DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `local_profesional_local_id_profesional_id_unique` (`local_id`,`profesional_id`),
  KEY `local_profesional_profesional_id_foreign` (`profesional_id`),
  CONSTRAINT `local_profesional_local_id_foreign` FOREIGN KEY (`local_id`) REFERENCES `locales` (`id`) ON DELETE CASCADE,
  CONSTRAINT `local_profesional_profesional_id_foreign` FOREIGN KEY (`profesional_id`) REFERENCES `profesionales` (`id`) ON DELETE CASCADE
);

-- Tabla `locales`
CREATE TABLE `locales` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `nombre` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `direccion` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `descripcion_publica` text COLLATE utf8mb4_unicode_ci,
  `telefono` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `latitud` decimal(10,8) DEFAULT NULL,
  `longitud` decimal(11,8) DEFAULT NULL,
  `color` char(7) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `banner` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `logo` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `es_principal` tinyint(1) NOT NULL DEFAULT '0',
  `horario` json DEFAULT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
);

-- Tabla `migrations`
CREATE TABLE `migrations` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `batch` int NOT NULL,
  PRIMARY KEY (`id`)
);

-- Tabla `plantilla_whatsapps`
CREATE TABLE `plantilla_whatsapps` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `nombre` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `evento` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `mensaje` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `plantilla_whatsapps_evento_unique` (`evento`)
);

-- Tabla `productos`
CREATE TABLE `productos` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `nombre` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `descripcion` text COLLATE utf8mb4_unicode_ci,
  `precio` decimal(10,2) NOT NULL DEFAULT '0.00',
  `costo` decimal(10,2) DEFAULT NULL,
  `stock` int NOT NULL DEFAULT '0',
  `stock_minimo` int unsigned NOT NULL DEFAULT '5',
  `activo` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `productos_nombre_unique` (`nombre`)
);

-- Tabla `profesionales`
CREATE TABLE `profesionales` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `usuario_id` bigint unsigned DEFAULT NULL,
  `nombre` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `cargo` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `foto` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `telefono` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tipo_pago` enum('comision','sueldo','ambos') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'comision',
  `comision_pct` decimal(5,2) NOT NULL DEFAULT '50.00',
  `sueldo_monto` decimal(10,2) DEFAULT NULL,
  `sueldo_periodo` enum('semanal','quincenal','mensual') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `horario` json DEFAULT NULL,
  `atiende` tinyint(1) NOT NULL DEFAULT '1',
  `activo` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `profesionales_usuario_id_unique` (`usuario_id`),
  CONSTRAINT `profesionales_usuario_id_foreign` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL
);

-- Tabla `roles`
CREATE TABLE `roles` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `nombre` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `clave` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sistema` tinyint(1) NOT NULL DEFAULT '0',
  `permisos` json NOT NULL,
  `solo_propios` tinyint(1) NOT NULL DEFAULT '0',
  `editado_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `roles_nombre_unique` (`nombre`),
  UNIQUE KEY `roles_clave_unique` (`clave`)
);

-- Tabla `servicio_imagenes`
CREATE TABLE `servicio_imagenes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `servicio_id` bigint unsigned NOT NULL,
  `ruta` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `orden` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `servicio_imagenes_servicio_id_foreign` (`servicio_id`),
  CONSTRAINT `servicio_imagenes_servicio_id_foreign` FOREIGN KEY (`servicio_id`) REFERENCES `servicios` (`id`) ON DELETE CASCADE
);

-- Tabla `servicio_profesional`
CREATE TABLE `servicio_profesional` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `servicio_id` bigint unsigned NOT NULL,
  `profesional_id` bigint unsigned NOT NULL,
  `precio_override` decimal(10,2) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `servicio_profesional_servicio_id_profesional_id_unique` (`servicio_id`,`profesional_id`),
  KEY `servicio_profesional_profesional_id_foreign` (`profesional_id`),
  CONSTRAINT `servicio_profesional_profesional_id_foreign` FOREIGN KEY (`profesional_id`) REFERENCES `profesionales` (`id`) ON DELETE CASCADE,
  CONSTRAINT `servicio_profesional_servicio_id_foreign` FOREIGN KEY (`servicio_id`) REFERENCES `servicios` (`id`) ON DELETE CASCADE
);

-- Tabla `servicios`
CREATE TABLE `servicios` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `categoria_servicio_id` bigint unsigned DEFAULT NULL,
  `nombre` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `descripcion` text COLLATE utf8mb4_unicode_ci,
  `color` char(7) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '#4f46e5',
  `tipo` enum('normal','sesiones','clases','paquete') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'normal',
  `max_sesiones` int unsigned DEFAULT NULL,
  `precio` decimal(10,2) NOT NULL DEFAULT '0.00',
  `duracion_min` int unsigned NOT NULL DEFAULT '30',
  `visible_publico` tinyint(1) NOT NULL DEFAULT '1',
  `activo` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `servicios_nombre_unique` (`nombre`),
  KEY `servicios_categoria_servicio_id_foreign` (`categoria_servicio_id`),
  CONSTRAINT `servicios_categoria_servicio_id_foreign` FOREIGN KEY (`categoria_servicio_id`) REFERENCES `categoria_servicios` (`id`) ON DELETE SET NULL
);

-- Tabla `usuarios`
CREATE TABLE `usuarios` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `central_user_id` bigint unsigned NOT NULL,
  `rol_id` bigint unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `usuarios_central_user_id_unique` (`central_user_id`),
  KEY `usuarios_rol_id_foreign` (`rol_id`),
  CONSTRAINT `usuarios_rol_id_foreign` FOREIGN KEY (`rol_id`) REFERENCES `roles` (`id`) ON DELETE RESTRICT
);
