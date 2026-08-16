-- =============================================================================
-- BD CENTRAL (landlord) — esquema real
--
-- GENERADO desde las migraciones — no editar a mano.
-- Última actualización: 2026-08-15
--
-- Tenants, usuarios, planes, pagos, soporte, notificaciones, metricas e infraestructura de Laravel. Una sola base compartida por toda la plataforma.
--
-- Para regenerarlo: migrar sobre una base limpia y volcar con
--   mysqldump -u root --no-data --skip-add-drop-table --skip-set-charset
-- (ver el ritual en CLAUDE.md § Base de datos).
--
-- La jerarquía ante conflicto NO cambia: docs/discrepancias.md (congelado)
-- manda sobre este archivo; este archivo solo describe lo que existe hoy.
-- =============================================================================

-- Tabla `anuncios`
CREATE TABLE `anuncios` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `platform_admin_id` bigint unsigned NOT NULL,
  `titulo` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `contenido` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `audiencia` enum('todos','duenos','profesionales') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'todos',
  `publicado_el` timestamp NULL DEFAULT NULL,
  `expira_el` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `anuncios_platform_admin_id_foreign` (`platform_admin_id`),
  CONSTRAINT `anuncios_platform_admin_id_foreign` FOREIGN KEY (`platform_admin_id`) REFERENCES `platform_admins` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla `business_categories`
CREATE TABLE `business_categories` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `nombre` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `icono` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `business_categories_slug_unique` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla `cache`
CREATE TABLE `cache` (
  `key` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `value` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `expiration` int NOT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla `cache_locks`
CREATE TABLE `cache_locks` (
  `key` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `owner` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `expiration` int NOT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla `domains`
CREATE TABLE `domains` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `domain` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tenant_id` varchar(63) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `domains_domain_unique` (`domain`),
  KEY `domains_tenant_id_foreign` (`tenant_id`),
  CONSTRAINT `domains_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla `failed_jobs`
CREATE TABLE `failed_jobs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `connection` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `queue` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `exception` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla `job_batches`
CREATE TABLE `job_batches` (
  `id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `total_jobs` int NOT NULL,
  `pending_jobs` int NOT NULL,
  `failed_jobs` int NOT NULL,
  `failed_job_ids` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `options` mediumtext COLLATE utf8mb4_unicode_ci,
  `cancelled_at` int DEFAULT NULL,
  `created_at` int NOT NULL,
  `finished_at` int DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla `jobs`
CREATE TABLE `jobs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `queue` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `attempts` tinyint unsigned NOT NULL,
  `reserved_at` int unsigned DEFAULT NULL,
  `available_at` int unsigned NOT NULL,
  `created_at` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `jobs_queue_index` (`queue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla `login_alertas`
CREATE TABLE `login_alertas` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned DEFAULT NULL,
  `platform_admin_id` bigint unsigned DEFAULT NULL,
  `ip` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `token` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `resuelta` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `login_alertas_token_unique` (`token`),
  KEY `login_alertas_user_id_foreign` (`user_id`),
  KEY `login_alertas_platform_admin_id_foreign` (`platform_admin_id`),
  CONSTRAINT `login_alertas_platform_admin_id_foreign` FOREIGN KEY (`platform_admin_id`) REFERENCES `platform_admins` (`id`) ON DELETE CASCADE,
  CONSTRAINT `login_alertas_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla `migrations`
CREATE TABLE `migrations` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `batch` int NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla `notificaciones`
CREATE TABLE `notificaciones` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned DEFAULT NULL,
  `platform_admin_id` bigint unsigned DEFAULT NULL,
  `tipo` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `titulo` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `mensaje` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `url` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `leida_el` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `notificaciones_platform_admin_id_foreign` (`platform_admin_id`),
  KEY `notificaciones_user_id_leida_el_index` (`user_id`,`leida_el`),
  CONSTRAINT `notificaciones_platform_admin_id_foreign` FOREIGN KEY (`platform_admin_id`) REFERENCES `platform_admins` (`id`) ON DELETE CASCADE,
  CONSTRAINT `notificaciones_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla `pagos`
CREATE TABLE `pagos` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` varchar(63) COLLATE utf8mb4_unicode_ci NOT NULL,
  `plan_id` bigint unsigned NOT NULL,
  `monto` decimal(10,2) NOT NULL,
  `periodo` enum('mensual','anual') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'mensual',
  `fecha_pago` date NOT NULL,
  `metodo` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `estado` enum('pagado','pendiente','vencido','reembolsado') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pagado',
  `referencia_externa` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nota` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `pagos_plan_id_foreign` (`plan_id`),
  KEY `pagos_tenant_id_fecha_pago_index` (`tenant_id`,`fecha_pago`),
  CONSTRAINT `pagos_plan_id_foreign` FOREIGN KEY (`plan_id`) REFERENCES `planes` (`id`),
  CONSTRAINT `pagos_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla `password_reset_tokens`
CREATE TABLE `password_reset_tokens` (
  `email` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla `personal_access_tokens`
CREATE TABLE `personal_access_tokens` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tokenable_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tokenable_id` bigint unsigned NOT NULL,
  `name` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `abilities` text COLLATE utf8mb4_unicode_ci,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `personal_access_tokens_token_unique` (`token`),
  KEY `personal_access_tokens_tokenable_type_tokenable_id_index` (`tokenable_type`,`tokenable_id`),
  KEY `personal_access_tokens_expires_at_index` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla `planes`
CREATE TABLE `planes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `nombre` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `descripcion` text COLLATE utf8mb4_unicode_ci,
  `precio_mensual` decimal(10,2) NOT NULL DEFAULT '0.00',
  `precio_anual` decimal(10,2) DEFAULT NULL,
  `precio_promo` decimal(10,2) DEFAULT NULL,
  `promo_duracion_meses` int unsigned NOT NULL DEFAULT '0',
  `promo_activa` tinyint(1) NOT NULL DEFAULT '0',
  `max_profesionales` int unsigned NOT NULL DEFAULT '999',
  `max_sucursales` int unsigned NOT NULL DEFAULT '999',
  `max_whatsapp_mes` int unsigned NOT NULL DEFAULT '0',
  `precio_profesional_extra` decimal(10,2) NOT NULL DEFAULT '0.00',
  `precio_whatsapp_extra` decimal(10,2) NOT NULL DEFAULT '0.00',
  `mensajes_whatsapp_extra` int unsigned NOT NULL DEFAULT '50',
  `destacado` tinyint(1) NOT NULL DEFAULT '0',
  `features` json DEFAULT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `planes_slug_unique` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla `platform_admins`
CREATE TABLE `platform_admins` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `nombre` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `password` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `rol` enum('superadmin','soporte') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'soporte',
  `activo` tinyint(1) NOT NULL DEFAULT '1',
  `two_factor_secret` text COLLATE utf8mb4_unicode_ci,
  `remember_token` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `platform_admins_email_unique` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla `soporte_acciones`
CREATE TABLE `soporte_acciones` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `ticket_id` bigint unsigned DEFAULT NULL,
  `tenant_id` varchar(63) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `soporte_admin_id` bigint unsigned NOT NULL,
  `accion` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `detalle` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `soporte_acciones_ticket_id_foreign` (`ticket_id`),
  KEY `soporte_acciones_soporte_admin_id_foreign` (`soporte_admin_id`),
  KEY `soporte_acciones_tenant_id_foreign` (`tenant_id`),
  CONSTRAINT `soporte_acciones_soporte_admin_id_foreign` FOREIGN KEY (`soporte_admin_id`) REFERENCES `platform_admins` (`id`) ON DELETE CASCADE,
  CONSTRAINT `soporte_acciones_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL,
  CONSTRAINT `soporte_acciones_ticket_id_foreign` FOREIGN KEY (`ticket_id`) REFERENCES `soporte_tickets` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla `soporte_tickets`
CREATE TABLE `soporte_tickets` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` varchar(63) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_id` bigint unsigned NOT NULL,
  `soporte_admin_id` bigint unsigned DEFAULT NULL,
  `asunto` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `descripcion` text COLLATE utf8mb4_unicode_ci,
  `respuesta` text COLLATE utf8mb4_unicode_ci,
  `prioridad` enum('baja','media','alta','critica') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'media',
  `estado` enum('abierto','en_proceso','resuelto','cerrado') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'abierto',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `soporte_tickets_user_id_foreign` (`user_id`),
  KEY `soporte_tickets_soporte_admin_id_foreign` (`soporte_admin_id`),
  KEY `soporte_tickets_tenant_id_estado_index` (`tenant_id`,`estado`),
  CONSTRAINT `soporte_tickets_soporte_admin_id_foreign` FOREIGN KEY (`soporte_admin_id`) REFERENCES `platform_admins` (`id`) ON DELETE SET NULL,
  CONSTRAINT `soporte_tickets_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `soporte_tickets_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla `tenant_metricas_diarias`
CREATE TABLE `tenant_metricas_diarias` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` varchar(63) COLLATE utf8mb4_unicode_ci NOT NULL,
  `fecha` date NOT NULL,
  `citas_creadas` int unsigned NOT NULL DEFAULT '0',
  `citas_completadas` int unsigned NOT NULL DEFAULT '0',
  `citas_canceladas` int unsigned NOT NULL DEFAULT '0',
  `ingresos` decimal(12,2) NOT NULL DEFAULT '0.00',
  `clientes_nuevos` int unsigned NOT NULL DEFAULT '0',
  `profesionales_activos` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tenant_metricas_diarias_tenant_id_fecha_unique` (`tenant_id`,`fecha`),
  CONSTRAINT `tenant_metricas_diarias_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla `tenants`
CREATE TABLE `tenants` (
  `id` varchar(63) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(63) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `plan_id` bigint unsigned NOT NULL,
  `business_category_id` bigint unsigned DEFAULT NULL,
  `categoria_otro_detalle` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `rango_profesionales` varchar(15) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nombre` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `descripcion` text COLLATE utf8mb4_unicode_ci,
  `email` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `telefono` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `whatsapp` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `direccion` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `latitud` decimal(10,8) DEFAULT NULL,
  `longitud` decimal(11,8) DEFAULT NULL,
  `zona_horaria` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'America/Lima',
  `logo` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cover` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `color_primario` char(7) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '#4f46e5',
  `color_secundario` char(7) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '#06b6d4',
  `sitio_publico_activo` tinyint(1) NOT NULL DEFAULT '1',
  `mostrar_en_marketplace` tinyint(1) NOT NULL DEFAULT '0',
  `terminos_servicio` text COLLATE utf8mb4_unicode_ci,
  `mapa_embed` text COLLATE utf8mb4_unicode_ci,
  `estado` enum('registrada','prueba','activa','suspendida','purga_pendiente','eliminada') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'registrada',
  `db_provisionada` tinyint(1) NOT NULL DEFAULT '0',
  `onboarding_completado` tinyint(1) NOT NULL DEFAULT '0',
  `onboarding_pasos` json DEFAULT NULL,
  `suscripcion_vence_el` date DEFAULT NULL,
  `suspendida_el` timestamp NULL DEFAULT NULL,
  `aviso_purga_enviado_el` timestamp NULL DEFAULT NULL,
  `purga_programada_el` date DEFAULT NULL,
  `extra_profesionales` int unsigned NOT NULL DEFAULT '0',
  `extra_whatsapp` int unsigned NOT NULL DEFAULT '0',
  `whatsapp_mensajes_enviados_mes` int unsigned NOT NULL DEFAULT '0',
  `whatsapp_mes_periodo` date DEFAULT NULL,
  `pagos_qr_activo` tinyint(1) NOT NULL DEFAULT '0',
  `qr_imagen` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `instrucciones_pago` text COLLATE utf8mb4_unicode_ci,
  `configuracion` json DEFAULT NULL,
  `data` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tenants_slug_unique` (`slug`),
  KEY `tenants_plan_id_foreign` (`plan_id`),
  KEY `tenants_business_category_id_foreign` (`business_category_id`),
  KEY `tenants_estado_suscripcion_vence_el_index` (`estado`,`suscripcion_vence_el`),
  CONSTRAINT `tenants_business_category_id_foreign` FOREIGN KEY (`business_category_id`) REFERENCES `business_categories` (`id`) ON DELETE SET NULL,
  CONSTRAINT `tenants_plan_id_foreign` FOREIGN KEY (`plan_id`) REFERENCES `planes` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla `users`
CREATE TABLE `users` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` varchar(63) COLLATE utf8mb4_unicode_ci NOT NULL,
  `nombre` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `apellido` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `rol` enum('dueno','admin','profesional') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'profesional',
  `foto` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `telefono` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT '1',
  `remember_token` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_email_unique` (`email`),
  KEY `users_tenant_id_index` (`tenant_id`),
  CONSTRAINT `users_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
