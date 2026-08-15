# Contexto del proyecto

SaaS de gestión de citas para negocios que venden tiempo de personas
(barberías, salones, clínicas). Mercado peruano: soles, DNI, WhatsApp,
Yape/Plin. Este repo es el **backend: Laravel 12 como API pura**.

## Repos y documentos

- Frontend en repo separado: `F:\PERSONAL_JEAN\mi-saas` (Next.js 16 + React 19
  + MUI 7 + TanStack Query). Consume esta API a través de un BFF propio.
- **`docs/discrepancias.md` está CONGELADO**: sus divergencias y decisiones
  cerradas **prevalecen sobre los SQL de referencia** cuando difieran.
  Ninguna sesión puede reabrirlas por seguir los SQL al pie de la letra.
- El contrato de la API vive en `F:\PERSONAL_JEAN\mi-saas\docs\api-contract.md`
  — **LEERLO SIEMPRE antes de diseñar endpoints.** Es el original único:
  nunca copiarlo a este repo ni editarlo desde aquí.
- La especificación por pantalla está en `F:\PERSONAL_JEAN\mi-saas\docs\vistas\`.
- Plan de sprints: `F:\PERSONAL_JEAN\mi-saas\docs\plan-sprints.md` — leerlo al
  arrancar cada sesión para saber qué módulo toca. Solo lectura.
- Lo que este repo necesite del contrato se anota en
  `docs/pendientes-contrato.md`; nunca se edita api-contract.md desde aquí.

**Jerarquía ante conflicto**, de mayor a menor:
1. `docs/discrepancias.md` (congelado) — las decisiones ya cerradas
2. `api-contract.md` — la forma de los datos que el cliente ya espera
3. Los SQL de `docs/` — reglas de negocio e integridad
4. Las fichas de `vistas/` — el porqué de cada campo

## Arquitectura decidida

- Multi-tenancy con **stancl/tenancy v3**, modo **MULTI-BASE** (una BD por tenant)
- Laravel 12 API pura: `routes/api.php`, API Resources, JSON. Sin Blade ni Inertia
- Auth: **Sanctum por tokens Bearer**. El navegador nunca ve el token: lo
  gestiona el BFF de Next en cookie httpOnly. El middleware de tenancy corre
  **ANTES** de `auth:sanctum` (los tokens viven en la BD central)
- Identificación de tenant: el BFF envía header `X-Tenant` (sale de
  `usuario.negocio.id`, guardado en cookie al login). El header es una PISTA
  de enrutamiento, **jamás autorización**: SIEMPRE validar que coincide con el
  tenant del usuario dueño del token. Si conviene derivarlo del propio token,
  la cabecera puede volverse redundante — decidirlo al implementar auth
- MySQL 8 / MariaDB. Zona horaria por tenant en `tenants.zona_horaria`

## Contratos ya fijados por el frontend (cumplir tal cual)

- `POST {API_URL}/login` → `{ "data": { "token": "...", "usuario": {...} } }`
  — el BFF ya está cableado para leer exactamente esa forma
- `register` NO abre sesión ni devuelve token: tras registrarse, el usuario
  va al login (flujo: registro → verificar email → **provisioning** → login →
  panel con checklist de onboarding no bloqueante)
- Logout revoca el token; el BFF borra la cookie aunque la revocación falle
- Respuestas: `{ data: ... }` individual, `{ data: [...], meta: {...} }`
  paginado. Paginar SIEMPRE los index. Búsqueda con `search` (nunca `buscar`).
  Filtros como query params. 422 con `errors` por campo

## Decisiones de producto cerradas

- **Clientes finales SIN cuenta**: no hay login de cliente. La reserva pública
  pide nombre y teléfono; la ficha en `clientes` se crea o se encuentra por
  teléfono (`firstOrCreate`). La gestión posterior de la cita va por el
  `codigo` enviado por WhatsApp, no por sesión. `cliente` sale del ENUM
  `users.rol`.
- **Auth solo email + contraseña.** Nada de OAuth por ahora (el esquema no lo
  bloquea: añadir `google_id` nullable después es trivial).
- **Todo el staff tiene fila en `profesionales`**, con flag `atiende` (bool,
  default 1) que controla si aparece en agenda y en la tienda pública. El
  dueño la recibe desde el **job de provisioning**, no en la transacción de
  registro: esa fila vive en la BD del tenant, que aún no existe al registrar.
  El cupo del plan cuenta el staff con independencia de `atiende`.
- **`tenants.id` y `tenants.slug` son cosas DISTINTAS** (cerrado en el
  contrato, Sprint 0):
  - `id`: inmutable, aleatorio, nace en el registro, nombra la BD
    (`tenant_a7f3k2`). Nunca se muestra al usuario.
  - `slug`: columna UNIQUE que fija el **paso 1 del onboarding**, forma el
    subdominio público y puede cambiar sin tocar infraestructura.
  - La tabla `domains` de stancl apunta al **id**, no al slug.

  Esto ANULA el comentario de `01_bd_central.sql` («id VARCHAR(63) -- slug:
  'aster-hair-salon'»): los SQL son referencia histórica; CLAUDE.md y
  discrepancias.md mandan.
- **El registro NO lleva nombre del negocio.** Payload cerrado:
  `tipo_negocio_id`, `rango_profesionales`, `nombre`, `apellido`, `email`,
  `telefono` (normalizado `+51…`) y `password`. El nombre del negocio — y con
  él el slug — lo fija el paso 1 del onboarding.
- **La tienda pública responde 404** mientras el paso 1 del onboarding no
  haya fijado el nombre/slug (el slug temporal jamás circula).
- **Pagos QR sin pasarela**: el tenant sube su QR de Yape/Plin y sus
  instrucciones (columnas en `tenants`, BD central, porque la tienda pública
  las necesita sin tocar la BD del tenant). El cliente sube comprobante, el
  dueño verifica. Modelado con tabla **`cita_pagos` (1-N)**, no columnas
  planas en `citas`. En `citas` solo `metodo_pago_eleccion` y `estado_pago`
  (materializado, escrito solo por el service). Ver §6 de discrepancias.md.
- **Sin caducidad de reserva impaga en v1**: expirar citas crea carreras con
  el anti-solape. Se revisa con datos reales (métricas definidas en §6.2.1).
- **Verificar un pago genera `caja_movimientos`** con `metodo=yape|plin`
  automáticamente. Si la caja está cerrada, `caja_cierre_id` queda NULL y
  `POST /caja/abrir` los adopta en la sesión nueva.
- **Horario de locales**: el JSON es la fuente de verdad (permite "sábado
  hasta la 1, domingo cerrado"); el Resource deriva las columnas planas que
  pide el contrato.
- **Las migraciones de tenant se escriben COMPLETAS en el Sprint 0**, aunque
  sus endpoints lleguen en sprints posteriores: el job de provisioning migra
  la BD entera una vez, y añadir tablas después obliga a re-migrar cada tenant.

## Base de datos (esquemas de referencia en docs/)

- `docs/01_bd_central.sql` — BD central (landlord): tenants, domains, users,
  platform_admins, planes, pagos, soporte, notificaciones,
  tenant_metricas_diarias, infraestructura Laravel
- `docs/02_bd_tenant.sql` — BD por tenant: citas, cita_servicio, cita_producto,
  cita_pagos, profesionales, clientes, servicios, productos, locales, grupos,
  caja, inventario, plantillas whatsapp
- **Convertir estos SQL en migraciones** (`database/migrations/` para la
  central y `database/migrations/tenant/` para tenants). NO ejecutar el SQL
  crudo. Aplicar encima las divergencias del §5 de `discrepancias.md`.

### Decisiones clave del esquema

- BD tenant: **NO existe negocio_id** en ninguna tabla (la base ES el negocio)
- `users` vive en la BD CENTRAL con `tenant_id NOT NULL`. Superadmin/soporte
  van en `platform_admins` (guard propio) — nunca users con tenant NULL
- **`users.email` es UNIQUE GLOBAL** (no compuesto). La columna
  **`users.usuario` se ELIMINA**: con email único global no aporta, y su
  unique compuesto cae con ella
- `profesionales` y `clientes` son perfiles locales del tenant; referencian
  `central_user_id` SIN foreign key (MySQL no permite FK entre bases; la
  integridad la garantiza la aplicación)
- `citas`: `starts_at`/`ends_at` DATETIME, `cliente_id NOT NULL`,
  CHECK ends_at > starts_at. `cita_servicio` es la ÚNICA fuente de verdad de
  los servicios de una cita (no existe `citas.servicio_id`)

## Reglas no negociables

1. **Anti-solape de citas**: en el service de reserva, transacción +
   `SELECT ... FOR UPDATE` sobre las citas del profesional en la ventana
   horaria, validar conflicto, recién insertar (MySQL no tiene EXCLUDE)
2. **Lazy provisioning**: la BD del tenant se crea vía job en cola al
   **VERIFICAR EL CORREO** — nunca en el registro, y sin esperar al
   onboarding (el usuario entraría a un panel sin BD). Por eso la BD se
   nombra con el `id` inmutable: el slug aún no existe al provisionar
3. **Lifecycle** por scheduled command diario:
   prueba vence → aviso → suspendida → aviso de purga → backup + drop + soft delete
4. **Tests de aislación obligatorios**: un usuario del tenant A recibe **404**
   (no 403) al pedir recursos del tenant B. Cubrir cada endpoint de recursos
5. **Métricas**: job nocturno agrega números de cada tenant activo hacia
   `tenant_metricas_diarias` en la central (no hay JOINs cross-tenant)
6. Todo timestamp de negocio se interpreta en `tenants.zona_horaria`
7. **Subida de comprobantes**: es el único endpoint de escritura de archivos
   SIN sesión de toda la API. Autorización por posesión del `codigo` de la
   cita (nunca id numérico enumerable), MIME real por firma + re-encode,
   límite de tamaño, renombrado UUID, disco privado, servido por URL firmada
   detrás del auth del panel, y rate limit por IP y por código

## Referencia de mercado

- Competidor de referencia: AgendaPro (modelo self-service, trial 7 días sin
  tarjeta, pricing por número de profesionales). Replicamos el modelo de
  negocio con diferenciación local: Yape/Plin como métodos de pago nativos,
  WhatsApp como canal principal, precios en soles para el mercado norteño.
- Registro mínimo: tipo de negocio, rango de profesionales y datos del dueño
  — SIN nombre del negocio (lo fija el paso 1 del onboarding). Poblar
  `business_categories` con taxonomía amplia: belleza, salud, fitness,
  veterinaria, clases.

## Convenciones

- PHP 8.3+, Pest para tests, PSR-12
- Form Requests para validación, Services para lógica de negocio,
  API Resources para salida
- Nombres de BD de tenant: `tenant_{id}` (el id inmutable y aleatorio del
  tenant, NUNCA el slug)
- Los colores y las etiquetas los pone el frontend, no el backend