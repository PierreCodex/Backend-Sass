# Contexto del proyecto

SaaS de gestión de citas para negocios que venden tiempo de personas
(barberías, salones, clínicas). Mercado peruano: soles, DNI, WhatsApp,
Yape/Plin. Este repo es el **backend: Laravel 12 como API pura**.

## Repos y documentos

- Frontend en repo separado: `D:\PERSONAL_JEAN\Sass-ChiraFlow` (Next.js 16 + React 19
  + MUI 7 + TanStack Query). Consume esta API a través de un BFF propio.
- **`docs/discrepancias.md` está CONGELADO**: sus divergencias y decisiones
  cerradas **prevalecen sobre los SQL de referencia** cuando difieran.
  Ninguna sesión puede reabrirlas por seguir los SQL al pie de la letra.
- El contrato de la API vive en `D:\PERSONAL_JEAN\Sass-ChiraFlow\docs\api-contract.md`
  — **LEERLO SIEMPRE antes de diseñar endpoints.** Es el original único:
  nunca copiarlo a este repo ni editarlo desde aquí.
- La especificación por pantalla está en `D:\PERSONAL_JEAN\Sass-ChiraFlow\docs\vistas\`.
- Plan de sprints: `D:\PERSONAL_JEAN\Sass-ChiraFlow\docs\plan-sprints.md` — leerlo al
  arrancar cada sesión para saber qué módulo toca. Solo lectura.
- Lo que este repo necesite del contrato se anota en
  `docs/pendientes-contrato.md`; nunca se edita api-contract.md desde aquí.
- **Tablero de las dos sesiones**: `D:\PERSONAL_JEAN\Sass-ChiraFlow\docs\estado.md`
  — **LEERLO AL ARRANCAR CADA SESIÓN**, antes que el plan de sprints: dice
  qué está hecho y qué tiene en la mano la otra sesión ahora mismo. Reglas:
  - Desde aquí se edita **solo** la columna BACKEND, la sección «Sesión
    BACKEND» y las líneas de Traspasos dirigidas BE → FE. Lo demás se lee.
  - Un módulo pasa a ✅ **al cerrarlo**, no al empezarlo (definición de
    terminado: `plan-sprints.md` §2).
  - Lo que este lado necesite del frontend va en **Traspasos** con fecha;
    es el único sitio donde se le pide algo a la otra sesión.
- **`git` solo en este repo.** La sesión de frontend hace `git` solo en
  `Sass-ChiraFlow`. Nunca crear ramas ni commitear en el repo ajeno: el 2026-08-22
  las dos sesiones escribieron sobre `Backend-Sass` y no se perdió trabajo
  de milagro.
- **Una rama por módulo**: `sprint-N/modulo` (p. ej. `sprint-1/categorias`),
  salida de `main`. No se mergea hasta que los tests del módulo estén en
  verde, incluido el de aislación entre tenants. Merge con `--no-ff` para
  que cada módulo sea revertible de un tirón.

## Flujo de trabajo con BMAD Method (desde 2026-09-19)

BMAD v6 está instalado como skills de usuario (`~/.claude/skills/bmad-*`) y
configurado en `_bmad/`. **La planificación pasa a BMAD y vive en UN solo
sitio**: `D:\PERSONAL_JEAN\Sass-ChiraFlow\_bmad-output\planning-artifacts\`
(este repo la lee por ruta, como antes el contrato):

- `prds/prd-ChiraFlow-2026-09-19/prd.md` — **qué** hace el producto (FR-1 a
  FR-76, con estado real). Las decisiones A-1 a A-7 del 2026-09-19 están ahí.
- `propuesta-decisiones-2026-09-19.md` — matriz de roles, planes,
  notificaciones y el porqué de A-1…A-7.
- `diagnostico-2026-09-19.md` — estado real verificado y fallos abiertos.
- La arquitectura, las épicas y `sprint-status.yaml` se generan a
  continuación y **sustituirán** a `plan-sprints.md` y a `estado.md`.
- `api-contract.md` **no se toca**: sigue siendo el original único.

Los documentos caseros (`plan-sprints.md`, `estado.md`, fichas de `vistas/`,
`pendientes-contrato.md`) siguen existiendo hasta verificar que no se pierde
nada; mientras tanto se leen como historial, y **ante conflicto manda el
PRD**.

- **Una historia**: `bmad-build` sobre la historia, en su rama
  `sprint-N/modulo`.
- **Un arreglo pequeño**: `bmad-build` directo.
- **Antes de mergear**: `bmad-code-review`. **Al cerrar una épica**:
  `bmad-retrospective`.
- Las reglas del proyecto entran en cada ejecución por
  `_bmad/custom/bmad-{build,spec,code-review,retrospective}.toml`
  (`persistent_facts`). Si cambia una regla que BMAD deba conocer, se cambia
  aquí **y** allí. Los overrides personales van en `*.user.toml` (ignorados).
- `_bmad/render/` es caché generada (ignorada). uv necesita usar los
  certificados de Windows o no consigue bajar `jinja2` (`invalid peer
  certificate: UnknownIssuer`). Desde el 2026-09-28 está en la config de
  usuario de uv, `%APPDATA%\uv\uv.toml` (`system-certs = true`), que vale para
  cualquier agente; antes solo lo tenía Claude Code como variable de entorno y
  Codex fallaba.

**Jerarquía ante conflicto**, de mayor a menor:
0. El **PRD de BMAD** en los puntos que sustituye expresamente (A-2, A-3,
   A-4; tabla al principio del PRD). Discrepancias los marca como superados
1. `docs/discrepancias.md` (congelado) — las decisiones ya cerradas
2. `api-contract.md` — la forma de los datos que el cliente ya espera
3. Los SQL de `docs/` — reglas de negocio e integridad; desde el Sprint 0 son
   un volcado generado de las migraciones, así que ante duda mandan estas
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
- Correo por **Resend** (`resend/resend-laravel`, `MAIL_MAILER=resend`,
  `RESEND_API_KEY`). Verificación y reset se envían **encolados**
  (`ShouldQueue`): sin worker no sale ningún correo
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
  teléfono (`firstOrCreate`). `cliente` sale del ENUM `users.rol`.
  **La gestión posterior de la cita va por un ENLACE SEGURO enviado por
  correo** (decisión A-4, 2026-09-19; antes era el `codigo` por WhatsApp):
  token aleatorio por cita, se guarda solo su hash, caduca 7 días después de
  la cita y es revocable. Abre UNA cita (ver, reprogramar, cancelar); abrirlo
  no cambia nada, toda acción se confirma en la página. El `codigo` de 8
  caracteres queda como referencia legible y como llave de la subida del
  comprobante (regla 7).
- **Auth solo email + contraseña.** Nada de OAuth por ahora (el esquema no lo
  bloquea: añadir `google_id` nullable después es trivial).
- **Usuarios y profesionales son cosas DISTINTAS** (separado el 2026-09-04;
  antes todo el staff tenía fila en `profesionales`). Son dos tablas del
  tenant y ninguna implica la otra:
  - **`usuarios`**: quien ENTRA al panel. `central_user_id` (sin FK, la
    credencial vive en `users` de la central) + `rol_id` con foreign key real
    contra `roles`. Una recepcionista es esto y nada más.
  - **`profesionales`**: quien PRESTA los servicios. `usuario_id` **nullable**
    con `nullOnDelete` es la unión opcional: un barbero puede no tener cuenta,
    y quitarle el acceso a quien la tiene no se lleva su ficha ni sus citas.

  El modelo anterior obligaba a declarar `tipo_pago` para dar de alta a una
  recepcionista, que entraba con un 50% de comisión sobre servicios que no
  presta. Cuando el modelo obliga a rellenar campos sin sentido, el modelo
  está mal.

  El dueño recibe su fila de `usuarios` en el **job de provisioning** (no en
  la transacción de registro: esa base aún no existe), y la de
  `profesionales` **solo si se registró como `independiente`** — quien dijo
  «solo yo» es su propio profesional; a los demás se la pide el checklist de
  onboarding.

  **El cupo del plan cuenta filas activas de `profesionales`**, sin
  excepciones ni flags: quien está en esa tabla presta servicios, y punto. Los
  usuarios del panel son ilimitados; los profesionales, no — el coste escala
  con citas, no con logins.

  **`atiende` significa UNA sola cosa**: si aparece en la tienda pública. No
  decide el cupo ni si alguien es staff.
- **Las reglas de seguridad viven en el SERVICE, no en el controlador.** Un
  candado en un controlador protege un endpoint; el mismo candado en el service
  protege todos los caminos. Lo aprendimos con una escalada real (2026-09-05):
  «solo un administrador general» estaba en `UsuarioController`, y el alta de
  cuenta desde `/profesionales` entraba por debajo — un administrador local se
  ascendía en dos peticiones. Y nunca dos copias de la misma regla: la que se
  olvida es la que no se prueba.

  **Pasó TRES veces en una semana.** La tercera (2026-09-06) fue la misma regla
  escrita en `RolRequest::authorize()` y en el controlador: divergieron, y solo
  una llevaba el `codigo` del 403. Por eso el rango vive ahora en
  **`App\Support\Rango`**, un único sitio que los dos guardias llaman. Que haya
  dos PUNTOS DE LLAMADA está bien —el del Form Request corta antes de validar—;
  lo que no puede haber es dos copias de la regla
- **Los permisos se aplican con el middleware `puede:modulo,nivel`** (desde el
  2026-09-05). Se pregunta por CAPACIDAD y nunca por rol: es lo que deja que el
  negocio invente sus propios roles sin tocar un endpoint. `gestionar` incluye
  `ver`; falla cerrado; 403 con `codigo: sin_permiso`, y la pared de cobro
  (`suscripcion_vencida`) gana cuando concurren. `GET /capacidades` devuelve la
  matriz ya resuelta para que el panel arme su menú — pero esconder una opción
  NO es autorización, el backend responde 403 igual
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
- **El slug nace la PRIMERA vez que hay nombre, venga por donde venga**
  (2026-09-06). Hay dos caminos que escriben `tenants.nombre` —el paso 1 del
  onboarding y `PUT /configuracion`— y solo el primero derivaba el slug: un
  negocio que se ponía el nombre desde Configuración quedaba con nombre y **sin
  enlace**, con la tienda pública en 404 para siempre y sin nada que lo dijera.
  Renombrar después mueve solo el nombre: el slug queda fijo, porque forma el
  enlace que el negocio ya repartió. `OnboardingService::derivarSlug()` es de
  los dos caminos.
- **Pagos QR sin pasarela**: el tenant sube su QR de Yape/Plin y sus
  instrucciones (columnas en `tenants`, BD central, porque la tienda pública
  las necesita sin tocar la BD del tenant). El cliente sube comprobante, el
  dueño verifica. Modelado con tabla **`cita_pagos` (1-N)**, no columnas
  planas en `citas`. En `citas` solo `metodo_pago_eleccion` y `estado_pago`
  (materializado, escrito solo por el service). Ver §6 de discrepancias.md.
- **Caducidad de la reserva impaga SOLO con pago obligatorio** (decisión A-3,
  2026-09-19; sustituye «sin caducidad en v1» de discrepancias §6.2.1): el
  negocio elige pago no pedido / opcional / obligatorio. Con obligatorio, si
  no llega la evidencia en el plazo (por defecto 60 min, 15–240), la cita pasa
  a `cancelada` y libera el hueco. La caducidad se hace con el MISMO
  `FOR UPDATE` que una reserva, así que no hay carrera con el anti-solape. Con
  evidencia subida ya no caduca. La cita sigue `pendiente` hasta que alguien
  con `pagos: gestionar` verifica; mostrar el QR no confirma nada.
- **Verificar un pago genera `caja_movimientos` SOLO cuando Caja esté
  lanzada** (A-3). Antes no se crean: la regla de adopción metería meses de
  pagos de golpe en la primera caja que se abriera.
- **Canal y autor de la cita son dos cosas** (decisión A-2, 2026-09-19;
  sustituye discrepancias §2.11): `canal` = `panel | tienda` (solo lo que
  existe; WhatsApp o API se añaden cuando existan) y `creada_por_usuario_id`
  = quién la registró (NULL si la reservó el cliente). La columna `fuente`
  (`admin`) se migra con una migración NUEVA, sin editar la de agosto.
- **Horario de locales**: el JSON es la fuente de verdad (permite "sábado
  hasta la 1, domingo cerrado"); el Resource deriva las columnas planas que
  pide el contrato.
- **El stock solo se mueve con MOVIMIENTOS** (Sprint 3.B). `productos.stock` es
  un saldo materializado y `inventario_movimientos` el porqué; nunca se escribe
  uno sin el otro, y el stock inicial anota su propia entrada para que el libro
  cuadre desde la primera línea. `PUT /inventario/{id}` **no acepta `stock`** —
  la clave no está en las reglas del Form Request, así que no llega a
  `validated()`. Una salida a mano **no puede dejar el saldo en negativo** (422);
  una **venta de cita sí**, y es deliberado: la salida a mano es alguien
  tecleando un número, la venta ya ocurrió y negarse a registrarla dejaría la
  cita sin cerrar por un inventario que ya estaba mal.
- **La disponibilidad se calcula en UN solo sitio**: `App\Services\Disponibilidad`
  (Sprint 4.A), para el panel y para la tienda. En el backend anterior vivía solo
  en la reserva pública y por eso desde el panel se creaban citas solapadas.
  Reproduce `features/calendario/disponibilidad.ts` **a propósito**: son dos
  copias deliberadas —el selector del panel no puede llamar al backend por cada
  tecla— y si una cambia, la otra también. El caso dorado de `vistas/citas.md`
  es un test.
- **Citas** (Sprint 4.B): precio y duración se **congelan** en `cita_servicio`;
  el `monto` editable reescribe el precio de esa línea (§2.6) y por eso el
  Resource emite `monto` = suma de servicios y `monto_total` = con productos.
  El **stock baja al COMPLETAR**, no al agendar, y deshacerlo lo devuelve
  anotando la entrada — el libro no se reescribe nunca. Sin teléfono, el cliente
  se crea nuevo aunque el nombre se repita: fusionar por nombre juntaría a dos
  «María» y separar historiales mezclados es peor que dos fichas repetidas.
  `DELETE` **borra de verdad**; cancelar es un ESTADO y es lo que conserva el
  historial.
- **`solo_propios` filtra desde el Sprint 4.B**: la cita de otro responde **404**
  y no 403, igual que una sede fuera de alcance — para esa persona no existe.
- **Las migraciones de tenant se escribieron COMPLETAS en el Sprint 0**, aunque
  sus endpoints lleguen en sprints posteriores: el job de provisioning migra
  la BD entera una vez, y añadir tablas después obliga a re-migrar cada tenant.
  **Desde el 2026-09-19 hay migraciones de tenant nuevas aprobadas** (canal y
  autor de la cita, `local_servicio`, accesos por enlace, envíos de correo…).
  Reglas: **nunca editar ni renombrar una migración que ya corrió** (la lección
  del 2026-09-04), cada cambio es un archivo nuevo, y se despliega con
  `tenants:migrar-provisionados`, nunca con `tenants:migrate` a secas.

## Base de datos (esquema real en docs/)

La fuente de verdad son las **migraciones** (`database/migrations/` para la
central, `database/migrations/tenant/` para tenants). Desde el Sprint 0 los
SQL de `docs/` ya no son el original histórico sino un **volcado generado**
del esquema real — se leen, nunca se editan a mano ni se ejecutan crudos:

- `docs/01_bd_central.sql` — BD central (landlord): tenants, domains, users,
  platform_admins, planes, pagos, soporte, notificaciones,
  tenant_metricas_diarias, infraestructura Laravel
- `docs/02_bd_tenant.sql` — BD por tenant: citas, cita_servicio, cita_producto,
  cita_pagos, profesionales, clientes, servicios, productos, locales, grupos,
  caja, inventario, plantillas whatsapp

**Ritual al cerrar un sprint que toque migraciones**: migrar sobre una base
desechable (nunca la de desarrollo), provisionar un tenant, volcar con
`mysqldump --no-data`, actualizar la fecha de la cabecera y borrar la base.

### Decisiones clave del esquema

- BD tenant: **NO existe negocio_id** en ninguna tabla (la base ES el negocio)
- `users` vive en la BD CENTRAL con `tenant_id NOT NULL`. Superadmin/soporte
  van en `platform_admins` (guard propio) — nunca users con tenant NULL
- **Los tres roles de sistema son `admin_general`, `admin_local` y
  `profesional`** (renombrados el 2026-09-04; antes `dueno` y `admin`). El
  general y el local no se distinguían por permisos —una fila, facturación— y
  eso no es un rol distinto: lo que los separa es el **alcance**, uno manda en
  la empresa y el otro en su sede. Por eso el local tampoco toca Configuración,
  que es de la empresa y no de un local. El alcance por sedes en sí llega con
  Locales (Sprint 3)
- **`users.email` es UNIQUE GLOBAL** (no compuesto). La columna
  **`users.usuario` se ELIMINA**: con email único global no aporta, y su
  unique compuesto cae con ella
- `usuarios` y `clientes` referencian `central_user_id` SIN foreign key (MySQL
  no permite FK entre bases; la integridad la garantiza la aplicación).
  `profesionales` ya NO lo hace: cuelga de `usuarios.id` con una FK de verdad,
  que es la ventaja de haber metido las cuentas en la base del negocio
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