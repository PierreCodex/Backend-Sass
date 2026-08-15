-- ============================================================================
-- BD CENTRAL (LANDLORD) — saas_central
-- Arquitectura: stancl/tenancy v3, multi-database (una BD por tenant)
-- Motor: MySQL 8 / MariaDB 10.6+  |  Laravel 12 como API (Sanctum por tokens)
--
-- Contiene TODO lo que es "de la plataforma":
--   identidad (users), tenants, planes/pagos, soporte, notificaciones,
--   infraestructura de Laravel (jobs, cache, tokens de Sanctum).
-- Las tablas de negocio (citas, servicios, caja...) viven en la BD del tenant.
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------------------------------------------------------
-- 1. TENANTS (reemplaza a `negocios`)
--    stancl usa `id` string. Usamos el slug como id del tenant: legible,
--    aparece en el nombre de la BD (tenant_aster_hair_salon) y en el subdominio.
--    Columnas "de negocio" del antiguo `negocios` que son de PLATAFORMA se
--    quedan aquí; las de personalización visual también (las lee el sitio
--    público antes de tocar la BD del tenant).
-- ----------------------------------------------------------------------------
CREATE TABLE `tenants` (
  `id` VARCHAR(63) NOT NULL,                       -- slug: 'aster-hair-salon'
  `plan_id` BIGINT UNSIGNED NOT NULL,
  `business_category_id` BIGINT UNSIGNED NULL,
  `categoria_otro_detalle` VARCHAR(255) NULL,

  -- Identidad pública del negocio
  `nombre` VARCHAR(150) NOT NULL,
  `descripcion` TEXT NULL,
  `email` VARCHAR(150) NULL,
  `telefono` VARCHAR(30) NULL,
  `whatsapp` VARCHAR(30) NULL,
  `direccion` VARCHAR(255) NULL,
  `latitud` DECIMAL(10,8) NULL,
  `longitud` DECIMAL(11,8) NULL,
  `zona_horaria` VARCHAR(64) NOT NULL DEFAULT 'America/Lima',  -- ya NO nullable

  -- Marca / sitio público
  `logo` VARCHAR(255) NULL,
  `cover` VARCHAR(255) NULL,
  `color_primario` CHAR(7) NOT NULL DEFAULT '#4f46e5',
  `color_secundario` CHAR(7) NOT NULL DEFAULT '#06b6d4',
  `sitio_publico_activo` TINYINT(1) NOT NULL DEFAULT 1,
  `mostrar_en_marketplace` TINYINT(1) NOT NULL DEFAULT 0,
  `terminos_servicio` TEXT NULL,
  `mapa_embed` TEXT NULL,

  -- Ciclo de vida SaaS (lazy provisioning + purga automatizada)
  `estado` ENUM('registrada','prueba','activa','suspendida','purga_pendiente','eliminada')
           NOT NULL DEFAULT 'registrada',
  `db_provisionada` TINYINT(1) NOT NULL DEFAULT 0, -- 0 hasta completar onboarding
  `onboarding_completado` TINYINT(1) NOT NULL DEFAULT 0,
  `onboarding_pasos` JSON NULL,
  `suscripcion_vence_el` DATE NULL,
  `suspendida_el` TIMESTAMP NULL,
  `aviso_purga_enviado_el` TIMESTAMP NULL,          -- correo "se eliminará en 15 días"
  `purga_programada_el` DATE NULL,

  -- Límites y consumo del plan
  `extra_profesionales` INT UNSIGNED NOT NULL DEFAULT 0,
  `extra_whatsapp` INT UNSIGNED NOT NULL DEFAULT 0,
  `whatsapp_mensajes_enviados_mes` INT UNSIGNED NOT NULL DEFAULT 0,
  `whatsapp_mes_periodo` DATE NULL,

  `configuracion` JSON NULL,
  `data` JSON NULL,                                 -- requerido por stancl (VirtualColumn)

  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  `deleted_at` TIMESTAMP NULL,

  PRIMARY KEY (`id`),
  KEY `tenants_estado_idx` (`estado`, `suscripcion_vence_el`),
  CONSTRAINT `tenants_plan_fk` FOREIGN KEY (`plan_id`) REFERENCES `planes` (`id`),
  CONSTRAINT `tenants_categoria_fk` FOREIGN KEY (`business_category_id`)
    REFERENCES `business_categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 2. DOMAINS (stancl) — resolución del tenant por subdominio o dominio propio
--    Reemplaza a negocios.slug + negocios.dominio_personalizado.
--    'aster-hair-salon.tuapp.pe' y 'reservas.asterhair.pe' → mismo tenant.
-- ----------------------------------------------------------------------------
CREATE TABLE `domains` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `domain` VARCHAR(255) NOT NULL,
  `tenant_id` VARCHAR(63) NOT NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `domains_domain_unique` (`domain`),
  KEY `domains_tenant_idx` (`tenant_id`),
  CONSTRAINT `domains_tenant_fk` FOREIGN KEY (`tenant_id`)
    REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 3. PLANES Y PAGOS (idénticos en espíritu al esquema actual)
-- ----------------------------------------------------------------------------
CREATE TABLE `planes` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nombre` VARCHAR(50) NOT NULL,
  `slug` VARCHAR(50) NOT NULL,
  `precio_mensual` DECIMAL(10,2) NOT NULL DEFAULT 0,
  `precio_anual` DECIMAL(10,2) NULL,
  `max_profesionales` INT UNSIGNED NULL,            -- NULL = ilimitado
  `max_locales` INT UNSIGNED NULL,
  `whatsapp_mensajes_mes` INT UNSIGNED NOT NULL DEFAULT 0,
  `permite_subdominio` TINYINT(1) NOT NULL DEFAULT 0,
  `permite_dominio_propio` TINYINT(1) NOT NULL DEFAULT 0,
  `permite_agenda` TINYINT(1) NOT NULL DEFAULT 1,
  `permite_promociones` TINYINT(1) NOT NULL DEFAULT 0,
  `features` JSON NULL,
  `activo` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `planes_slug_unique` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `pagos` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` VARCHAR(63) NOT NULL,
  `plan_id` BIGINT UNSIGNED NOT NULL,
  `monto` DECIMAL(10,2) NOT NULL,
  `periodo` ENUM('mensual','anual') NOT NULL DEFAULT 'mensual',
  `fecha_pago` DATE NOT NULL,
  `metodo` VARCHAR(50) NULL,
  `estado` ENUM('pagado','pendiente','vencido','reembolsado') NOT NULL DEFAULT 'pagado',
  `referencia_externa` VARCHAR(120) NULL,           -- id de Culqi/Niubiz/Stripe
  `nota` VARCHAR(255) NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  KEY `pagos_tenant_fecha_idx` (`tenant_id`, `fecha_pago`),
  CONSTRAINT `pagos_tenant_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`),
  CONSTRAINT `pagos_plan_fk` FOREIGN KEY (`plan_id`) REFERENCES `planes` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `business_categories` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nombre` VARCHAR(100) NOT NULL,
  `slug` VARCHAR(100) NOT NULL,
  `icono` VARCHAR(100) NULL,
  `activo` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `business_categories_slug_unique` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 4. IDENTIDAD — decisión clave: usuarios en la BD CENTRAL
--
--    Se separa en DOS tablas (corrige el `users` monolítico actual):
--    a) platform_admins : superadmin y soporte. SIN tenant. Guard propio.
--       Elimina el problema del tenant_id NULL "mágico".
--    b) users           : dueño / admin / profesional / cliente de UN tenant.
--       tenant_id NOT NULL siempre.
--
--    El PERFIL laboral del profesional (comisión, sueldo, horario) ya NO vive
--    aquí: vive en `profesionales` dentro de la BD del tenant. Aquí solo
--    queda identidad y credenciales.
-- ----------------------------------------------------------------------------
CREATE TABLE `platform_admins` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nombre` VARCHAR(150) NOT NULL,
  `email` VARCHAR(150) NOT NULL,
  `password` VARCHAR(255) NOT NULL,
  `rol` ENUM('superadmin','soporte') NOT NULL DEFAULT 'soporte',
  `activo` TINYINT(1) NOT NULL DEFAULT 1,
  `two_factor_secret` TEXT NULL,
  `remember_token` VARCHAR(100) NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  `deleted_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `platform_admins_email_unique` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `users` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` VARCHAR(63) NOT NULL,                 -- NOT NULL: sin excepciones
  `nombre` VARCHAR(150) NOT NULL,
  `usuario` VARCHAR(100) NOT NULL,
  `email` VARCHAR(150) NULL,
  `email_verified_at` TIMESTAMP NULL,               -- requisito p/ provisionar BD
  `password` VARCHAR(255) NOT NULL,
  `rol` ENUM('dueno','admin','profesional','cliente') NOT NULL DEFAULT 'cliente',
  `foto` VARCHAR(255) NULL,
  `telefono` VARCHAR(30) NULL,
  `activo` TINYINT(1) NOT NULL DEFAULT 1,
  `remember_token` VARCHAR(100) NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  `deleted_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  -- Corrige los 2 bugs encontrados en el dump:
  UNIQUE KEY `users_tenant_usuario_unique` (`tenant_id`, `usuario`), -- 'admin' libre en cada negocio
  UNIQUE KEY `users_tenant_email_unique` (`tenant_id`, `email`),     -- sin emails duplicados por tenant
  KEY `users_email_idx` (`email`),                  -- login por email cross-tenant
  CONSTRAINT `users_tenant_fk` FOREIGN KEY (`tenant_id`)
    REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Reset de contraseña: se agrega tenant_id porque el email ya no es único global
CREATE TABLE `password_reset_tokens` (
  `email` VARCHAR(150) NOT NULL,
  `tenant_id` VARCHAR(63) NOT NULL DEFAULT '_platform',  -- '_platform' = admins
  `token` VARCHAR(255) NOT NULL,
  `created_at` TIMESTAMP NULL,
  PRIMARY KEY (`email`, `tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sanctum: los tokens de la API viven en la central (auth ANTES de tenancy)
CREATE TABLE `personal_access_tokens` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tokenable_type` VARCHAR(255) NOT NULL,
  `tokenable_id` BIGINT UNSIGNED NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `token` VARCHAR(64) NOT NULL,
  `abilities` TEXT NULL,
  `last_used_at` TIMESTAMP NULL,
  `expires_at` TIMESTAMP NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `pat_token_unique` (`token`),
  KEY `pat_tokenable_idx` (`tokenable_type`, `tokenable_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `login_alertas` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NULL,
  `platform_admin_id` BIGINT UNSIGNED NULL,
  `ip` VARCHAR(45) NULL,
  `user_agent` VARCHAR(255) NULL,
  `token` VARCHAR(100) NULL,
  `resuelta` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `login_alertas_token_unique` (`token`),
  CONSTRAINT `login_alertas_user_fk` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `login_alertas_admin_fk` FOREIGN KEY (`platform_admin_id`)
    REFERENCES `platform_admins` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 5. SOPORTE Y COMUNICACIÓN — central: soporte ve todo sin abrir N bases
-- ----------------------------------------------------------------------------
CREATE TABLE `soporte_tickets` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` VARCHAR(63) NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `soporte_admin_id` BIGINT UNSIGNED NULL,
  `asunto` VARCHAR(200) NOT NULL,
  `descripcion` TEXT NULL,
  `prioridad` ENUM('baja','media','alta','critica') NOT NULL DEFAULT 'media',
  `estado` ENUM('abierto','en_proceso','resuelto','cerrado') NOT NULL DEFAULT 'abierto',
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  KEY `tickets_tenant_idx` (`tenant_id`, `estado`),
  CONSTRAINT `tickets_tenant_fk` FOREIGN KEY (`tenant_id`)
    REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `tickets_user_fk` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `tickets_admin_fk` FOREIGN KEY (`soporte_admin_id`)
    REFERENCES `platform_admins` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `soporte_acciones` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ticket_id` BIGINT UNSIGNED NULL,
  `tenant_id` VARCHAR(63) NULL,
  `soporte_admin_id` BIGINT UNSIGNED NOT NULL,
  `accion` VARCHAR(100) NOT NULL,
  `detalle` TEXT NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `acciones_ticket_fk` FOREIGN KEY (`ticket_id`)
    REFERENCES `soporte_tickets` (`id`) ON DELETE SET NULL,
  CONSTRAINT `acciones_tenant_fk` FOREIGN KEY (`tenant_id`)
    REFERENCES `tenants` (`id`) ON DELETE SET NULL,
  CONSTRAINT `acciones_admin_fk` FOREIGN KEY (`soporte_admin_id`)
    REFERENCES `platform_admins` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `notificaciones` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NULL,
  `platform_admin_id` BIGINT UNSIGNED NULL,
  `tipo` VARCHAR(100) NOT NULL,
  `titulo` VARCHAR(255) NOT NULL,
  `mensaje` TEXT NOT NULL,
  `url` VARCHAR(255) NULL,
  `leida_el` TIMESTAMP NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  KEY `notif_user_idx` (`user_id`, `leida_el`),
  CONSTRAINT `notif_user_fk` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `notif_admin_fk` FOREIGN KEY (`platform_admin_id`)
    REFERENCES `platform_admins` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `anuncios` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `platform_admin_id` BIGINT UNSIGNED NOT NULL,
  `titulo` VARCHAR(200) NOT NULL,
  `contenido` TEXT NOT NULL,
  `audiencia` ENUM('todos','duenos','profesionales') NOT NULL DEFAULT 'todos',
  `publicado_el` TIMESTAMP NULL,
  `expira_el` TIMESTAMP NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `anuncios_admin_fk` FOREIGN KEY (`platform_admin_id`)
    REFERENCES `platform_admins` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 6. MÉTRICAS AGREGADAS — el reemplazo del "SELECT COUNT(*) global"
--    Un job nocturno recorre los tenants activos y vuelca aquí sus números.
--    Es la respuesta al problema de reportes cross-tenant en multi-BD.
-- ----------------------------------------------------------------------------
CREATE TABLE `tenant_metricas_diarias` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` VARCHAR(63) NOT NULL,
  `fecha` DATE NOT NULL,
  `citas_creadas` INT UNSIGNED NOT NULL DEFAULT 0,
  `citas_completadas` INT UNSIGNED NOT NULL DEFAULT 0,
  `citas_canceladas` INT UNSIGNED NOT NULL DEFAULT 0,
  `ingresos` DECIMAL(12,2) NOT NULL DEFAULT 0,
  `clientes_nuevos` INT UNSIGNED NOT NULL DEFAULT 0,
  `profesionales_activos` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `metricas_tenant_fecha_unique` (`tenant_id`, `fecha`),
  CONSTRAINT `metricas_tenant_fk` FOREIGN KEY (`tenant_id`)
    REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 7. INFRAESTRUCTURA LARAVEL (colas centrales, cache, fallos)
--    stancl prefija los jobs con el tenant automáticamente vía middleware.
-- ----------------------------------------------------------------------------
CREATE TABLE `jobs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `queue` VARCHAR(255) NOT NULL,
  `payload` LONGTEXT NOT NULL,
  `attempts` TINYINT UNSIGNED NOT NULL,
  `reserved_at` INT UNSIGNED NULL,
  `available_at` INT UNSIGNED NOT NULL,
  `created_at` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  KEY `jobs_queue_idx` (`queue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `job_batches` (
  `id` VARCHAR(255) NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `total_jobs` INT NOT NULL,
  `pending_jobs` INT NOT NULL,
  `failed_jobs` INT NOT NULL,
  `failed_job_ids` LONGTEXT NOT NULL,
  `options` MEDIUMTEXT NULL,
  `cancelled_at` INT NULL,
  `created_at` INT NOT NULL,
  `finished_at` INT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `failed_jobs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` VARCHAR(255) NOT NULL,
  `connection` TEXT NOT NULL,
  `queue` TEXT NOT NULL,
  `payload` LONGTEXT NOT NULL,
  `exception` LONGTEXT NOT NULL,
  `failed_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `cache` (
  `key` VARCHAR(255) NOT NULL,
  `value` MEDIUMTEXT NOT NULL,
  `expiration` INT NOT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `cache_locks` (
  `key` VARCHAR(255) NOT NULL,
  `owner` VARCHAR(255) NOT NULL,
  `expiration` INT NOT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
