# Discrepancias: api-contract.md ↔ esquemas SQL (docs/01, docs/02)

Fecha de análisis: 2026-08-14.
Estado: **CONGELADO** (2026-08-14) — todas las decisiones abiertas quedaron
cerradas; el siguiente paso son las migraciones.
Fuentes: `D:\PERSONAL_JEAN\Sass-ChiraFlow\docs\api-contract.md` (contrato, solo lectura),
`docs/01_bd_central.sql`, `docs/02_bd_tenant.sql`.

**Jerarquía aplicada (CLAUDE.md):**

- Forma de datos (qué campos viajan y con qué nombre) → gana **el contrato**.
- Reglas de negocio e integridad (qué se guarda y cómo) → ganan **los SQL**.
- El porqué de un campo → `vistas/`.

Convención de los veredictos: *«gana contrato»* = el SQL o el Resource deben
producir esa forma; *«gana SQL»* = el backend impone la regla y el contrato la
absorbe (normalmente sin romper la forma); *«ambos»* = hay que tocar el SQL
**y** pedir un cambio en el repo del frontend (el contrato no se edita desde
aquí — se anota como pendiente para `Sass-ChiraFlow`).

---

## 1. Campos que el cliente espera y la BD no contempla

### 1.1 `servicios` (tenant) — faltan 3 columnas

| Campo del contrato | En el SQL | Veredicto |
|---|---|---|
| `color` (obligatorio: punto en tabla y bloque de calendario) | no existe | gana contrato → **añadir columna** `color CHAR(7)` |
| `tipo` (`normal\|sesiones\|clases\|paquete`) | no existe | gana contrato → **añadir** `tipo ENUM(...) DEFAULT 'normal'` |
| `max_sesiones` (solo `sesiones` y `paquete`) | no existe | gana contrato → **añadir** `max_sesiones INT UNSIGNED NULL` |
| `imagen_principal` + `galeria` (máx. 4) | `servicio_imagenes(ruta, orden)` | compatible: `orden = 0` es la principal, resto galería. Resource mapea. Sin cambio de SQL |
| `empleados: {id,nombre}[]` | `servicio_profesional` | compatible ✔ |

Cambio: **SQL** (migración de `servicios`).

### 1.2 `categoria_servicios` (tenant) — faltan 3 columnas

El contrato pide `descripcion`, `color` e `imagen_url`; la tabla solo tiene
`nombre`, `orden`, `activo`. `servicios_count` es `withCount`, no necesita
columna.

Cambio: **SQL** — añadir `descripcion TEXT NULL`, `color CHAR(7) NULL`,
`imagen VARCHAR(255) NULL`. Gana contrato.

### 1.3 `locales` (tenant) — la tabla más incompleta

| Campo del contrato | En el SQL | Veredicto |
|---|---|---|
| `descripcion_publica` | no existe | gana contrato → añadir |
| `email` | no existe | gana contrato → añadir |
| `color` | no existe | gana contrato → añadir |
| `banner_url` / `logo_url` | no existen | gana contrato → añadir `banner`, `logo` |
| `es_principal` (no se borra; se edita desde Configuración) | no existe | gana contrato → añadir `es_principal TINYINT(1) DEFAULT 0` + regla en el service |
| `horario_desde` / `horario_hasta` (un solo rango, **no** por día) | `horario JSON` | gana contrato en la forma: dos columnas `TIME NULL` son más honestas que un JSON que nunca tendrá más de un rango. Recomendado: reemplazar el JSON |

Estos campos también los necesita la tienda pública (`LocalPublico`), así que
no son opcionales. Cambio: **SQL**.

### 1.4 `local_profesional` (tenant) — el pivote no tiene payload

El contrato define un `PUT /locales/{id}/profesionales/{profId}` con
`habilitado`, `nombre_publico`, `perfil`, `horario.apertura`, `horario.cierre`
— y la tienda pública lee `nombre_publico`, `perfil` y el filtro `habilitado`.
El pivote del SQL solo tiene `local_id` + `profesional_id`.

Cambio: **SQL** — añadir `habilitado TINYINT(1) DEFAULT 1`,
`nombre_publico VARCHAR(150) NULL`, `perfil TEXT NULL`,
`horario_apertura TIME NULL`, `horario_cierre TIME NULL`. Gana contrato.

Nota de semántica: el contrato dice que el `id` de `LocalProfesional` es «el
`users.id` del profesional». En el esquema nuevo el id natural del panel es
`profesionales.id` (tenant). Lo que importa es la **consistencia**: el mismo id
que devuelve `/empleados` debe servir en `/locales/{id}/profesionales/{profId}`
y en `citas.empleado.id`. Decisión: usar `profesionales.id` en todo el API y
anotar la aclaración en el contrato (**ambos**, solo texto en el contrato).

### 1.5 `planes` (central) — faltan los campos comerciales

| Campo del contrato | En el SQL | Veredicto |
|---|---|---|
| `descripcion` (texto comercial) | no existe | añadir |
| `precio_promo`, `promo_duracion_meses`, `promo_activa` | no existen | añadir |
| `precio_profesional_extra`, `precio_whatsapp_extra`, `mensajes_whatsapp_extra` | no existen | añadir (sin ellos `POST /plan/{id}/solicitar` no puede desglosar el ticket) |
| `destacado` | no existe | añadir |
| `max_sucursales` con **999 = ilimitadas** | `max_locales NULL = ilimitado` | choque de centinelas: la forma la fija el contrato (999). Recomendado alinear el SQL a `NOT NULL DEFAULT 999` y eliminar el NULL mágico; como mínimo, el Resource mapea NULL→999 |
| `max_whatsapp_mes` | `whatsapp_mensajes_mes` | naming: mapea el Resource, sin cambio |
| `max_profesionales` | existe pero `NULL = ilimitado` | mismo problema de centinela que sucursales: `/empleados/resumen` promete `limite_profesionales: int`. Alinear con el mismo criterio (999 o el número real) |

Cambio: **SQL** (migración de `planes`). Los flags `permite_*` del SQL no los
pide ninguna pantalla — ver §4.

### 1.6 `tenants` / suscripción (central)

| Contrato | En el SQL | Veredicto |
|---|---|---|
| `Suscripcion.elegible_promo: bool` | no hay columna ni regla | gana contrato → decidir la regla (p.ej. `tenants.promo_usada` o derivarlo de `pagos`) y **añadir soporte en SQL** |
| `Suscripcion.estado: prueba\|activa\|vencida\|cancelada` | `tenants.estado` tiene 6 valores de lifecycle | gana SQL en el almacenamiento (el lifecycle manda); el Resource **mapea**: `registrada/prueba→prueba`, `activa→activa`, `suspendida/purga_pendiente→vencida`. **`cancelada` no tiene origen**: no existe estado de baja voluntaria. Decidir: o se añade al ENUM del tenant, o se pide quitar del contrato (**ambos**) |
| `Configuracion.informacion_adicional` | no hay columna | va dentro de `tenants.configuracion` JSON — sin cambio de SQL, lo resuelve el Resource (el propio contrato lo permite) |
| `Configuracion.agenda {modo_intervalo, intervalo_min}` | no hay columna | ídem: JSON `configuracion`. El contrato ya lo marca como nuevo ✔ |
| `Configuracion.slug` no editable | `tenants.id` **es** el slug (PK) | **SUPERADO (2026-08-15, contrato)**: id y slug se desacoplan — `id` inmutable aleatorio nombra la BD; `slug` es columna UNIQUE que fija el paso 1 del onboarding y forma el subdominio. `slug` sigue sin aceptarse en el PUT de configuración. Ver CLAUDE.md § Decisiones de producto |
| `horario_apertura/cierre` del negocio (respaldo 09:00–20:00) | JSON `configuracion` | sin cambio; default en la capa de aplicación |

Nota arquitectónica: `GET/PUT /configuracion` lee y escribe la **BD central**
(tabla `tenants`), no la del tenant. No es discrepancia, pero hay que tenerlo
presente al implementar (y al testear aislación).

### 1.7 `soporte_tickets` (central)

| Contrato | En el SQL | Veredicto |
|---|---|---|
| `respuesta?` (string) | no existe; hay `soporte_acciones` | gana contrato → **añadir columna** `respuesta TEXT NULL` (una respuesta visible por ticket; `soporte_acciones` queda como bitácora interna) |
| `mensaje` | `descripcion` | naming: mapea el Resource |
| `estado: abierto\|en_proceso\|cerrado` | ENUM añade `resuelto` | gana SQL en almacenamiento; el Resource mapea `resuelto→cerrado` **o** se pide ampliar el union del contrato. Recomendado: mapear (menos fricción) |
| `prioridad: baja\|media\|alta` | ENUM añade `critica` | el negocio nunca la envía, pero si soporte la pone, el GET devolvería un valor fuera del union del cliente. Recomendado: pedir añadir `critica` al tipo del frontend (**ambos**, cambio trivial) |
| `autor`, `respondido_por` | `user_id`, `soporte_admin_id` | el Resource resuelve los nombres ✔ |

### 1.8 `plantilla_whatsapps` (tenant)

| Contrato | En el SQL | Veredicto |
|---|---|---|
| `nombre` | no existe | gana contrato → **añadir columna** |
| `contenido` | `mensaje` | naming: mapea el Resource (o renombrar la columna, da igual) |
| `evento` con claves `confirmacion, recordatorio, cancelacion, finalizado, bienvenida, pago_linea, redes_sociales, cumpleanos, personalizado` | comentario del SQL sugiere `cita_creada, ...` | gana contrato: las claves del ENUM/validación deben ser **exactamente** las del frontend (él pone las etiquetas). Corregir el comentario y validar contra esa lista |
| `POST` = `updateOrCreate` por evento | `UNIQUE(evento)` ✔ | compatible — y confirma que solo cabe **una** plantilla `personalizado` |

### 1.9 `profesionales` + `users` (el Empleado del contrato cruza dos BD)

La entidad `Empleado` mezcla identidad central (`usuario`, `email`, `rol`,
`activo`, `password`, `foto`) con perfil laboral tenant (`cargo`, `tipo_pago`,
comisión, sueldo, `horario`, `excepciones`). El Resource compone ambas. Choques
concretos:

| Contrato | En el SQL | Veredicto |
|---|---|---|
| `tipo_pago: comision\|sueldo\|ambos` | ENUM `comision\|sueldo\|mixto` | gana contrato en la forma: el cliente recibe la clave del enum tal cual. **Cambiar el ENUM a `ambos`** (más simple que mapear para siempre) |
| `comision_porcentaje`, `monto_sueldo`, `periodo_pago` | `comision_pct`, `sueldo_monto`, `sueldo_periodo` | naming: mapea el Resource |
| `horario: DiaHorario[]` + `excepciones: []` como arrays separados | un solo JSON `{dias, excepciones}` | compatible: el Resource separa. Sin cambio |
| `rol` incluye `superadmin` | `users.rol` no lo tiene (vive en `platform_admins`) | gana SQL: un usuario de tenant jamás será superadmin. El union del cliente simplemente nunca recibirá ese valor; opcionalmente pedir limpiarlo del tipo |
| ¿dueño/admin aparecen en `/empleados`? | `profesionales` exige fila con `tipo_pago`, etc. | **DECIDIDO**: todo miembro del staff tiene fila en `profesionales` (el UNIQUE `central_user_id` lo permite). Se **añade columna `atiende TINYINT(1) NOT NULL DEFAULT 1`**: controla si el profesional aparece en la agenda del panel y en la tienda pública (dueño/admin de oficina → `atiende=0`). El cupo del plan sigue contándose por `rol='profesional'`, independiente de `atiende` — ⚠️ **esta última frase quedó SUPERADA el 2026-09-04; ver la nota bajo la tabla** |
| `usuario` (login con el que inicia sesión) | `users.usuario` **se elimina** (§2.9) | con email único global la columna no aporta y complica el registro. Mientras el tipo `Empleado` del contrato conserve el campo, el Resource emite el **email** en `usuario` (es el identificador real de login); pedir a `Sass-ChiraFlow` retirarlo o renombrarlo. La búsqueda «por usuario» del index pasa a buscar por email |

> ⚠️ **Superado el 2026-09-04 — cómo se cuenta el cupo del plan.**
>
> La tabla de arriba se conserva tal cual quedó cerrada; esto **no la reabre**,
> registra que el dueño del producto la cambió después con información nueva.
>
> El cupo **ya no se cuenta por rol**, sino por **agenda**: consume plaza quien
> esté `activo` **y** con `atiende = 1`. Dejó de sostenerse en cuanto el
> negocio pudo crear sus propios roles — bastaba inventar «Barbero senior» para
> que el límite del plan desapareciera— y al mirar cómo lo resuelve AgendaPro
> se vio que el eje correcto era otro: **los usuarios del panel son ilimitados,
> los profesionales no**. El coste del producto escala con citas, no con
> logins, así que una recepcionista no ocupa plaza y un barbero sí.
>
> Consecuencias: **el dueño deja de ser excepción** (si atiende, ocupa la
> suya, y un independiente consume 1), y no queda nada que vigilar — apagar
> `atiende` para no pagar quita justo aquello por lo que se pagaba, porque a
> esa persona deja de podérsele reservar. El cupo se valida también al
> ENCENDER el flag, que era la única rendija que quedaba.
>
> La regla vigente está en `CLAUDE.md` § Decisiones de producto y en
> `EmpleadoService::validarCupo`. El 422 del tope cae en `atiende`.

> ⚠️ **Superado también el 2026-09-04 — usuarios y profesionales se separan.**
>
> «Todo miembro del staff tiene fila en `profesionales`» dejó de ser cierto. Hay
> dos tablas del tenant y ninguna implica la otra: **`usuarios`** (quien entra
> al panel, con su `rol_id`) y **`profesionales`** (quien presta los servicios,
> con `usuario_id` nullable). Una recepcionista es lo primero y no lo segundo;
> un barbero que no toca el sistema, al revés.
>
> El motivo fue un síntoma concreto: dar de alta a una recepcionista obligaba a
> declarar `tipo_pago`, y entraba con un 50% de comisión sobre servicios que no
> presta. Cuando el modelo obliga a rellenar campos sin sentido, el modelo está
> mal. AgendaPro lo tiene separado y su guía lo confirma: el formulario de
> profesional no pide correo, y crear su usuario es una casilla opcional.
>
> Con esto **el cupo del plan vuelve a ser trivial**: filas activas de
> `profesionales`, sin excepciones. Y `atiende` recupera su único significado —
> si aparece en la tienda pública.
>
> La regla vigente está en `CLAUDE.md` § Decisiones de producto y el detalle en
> `docs/pendientes-contrato.md` § [Sprint 2] Usuarios y Profesionales.

### 1.10 `productos` (tenant)

| Contrato | En el SQL | Veredicto |
|---|---|---|
| `precio_venta` / `precio_compra` | `precio` / `costo` | naming: mapea el Resource |
| `stock_minimo` default 5 | default 0 | detalle: poner el 5 como default de la migración o del Form Request. Gana contrato |

### 1.11 Reseñas de la tienda pública

`TiendaLocal.resenas` y `ultimas_resenas` **no tienen tabla**. El propio
contrato lo reconoce y acepta `resenas: null`. Veredicto: sin cambio ahora;
cuando se aborde, el esquema propuesto está en `vistas/tienda-publica.md` y
habrá que añadir la tabla al SQL del tenant. Mientras tanto el backend devuelve
`resenas: null` y `ultimas_resenas: []`.

---

## 2. Reglas de la BD que el cliente ignora (gana el SQL; el Resource traduce)

### 2.1 `starts_at`/`ends_at` vs `fecha` + `hora_inicio`/`hora_fin`

La BD guarda DATETIME (integridad: `CHECK ends_at > starts_at`, índice de
solapes, anti-solape con `FOR UPDATE`). El cliente habla en
`fecha: YYYY-MM-DD` + `hora_inicio/hora_fin: HH:MM`.

- Gana **SQL** en almacenamiento; gana **contrato** en el JSON.
- El Form Request recibe `fecha + hora_inicio`, el service calcula `ends_at`
  (inicio + duración — el contrato ya declara que `hora_fin` la pone el
  backend) y el Resource vuelve a partir el DATETIME en dos campos, en la
  `zona_horaria` del tenant. Sin cambio de SQL ni de contrato.

### 2.2 `clientes` obligatoria vs `cliente_nombre` de texto libre

El SQL exige `citas.cliente_id NOT NULL` (regla de integridad: siempre hay
cliente, el walk-in también). El payload del panel manda `cliente_id` opcional
más `cliente_nombre/telefono/email` sueltos, y el tipo `Cita` declara
`cliente_id: int?`.

- Gana **SQL**: el service hace `firstOrCreate` de `clientes` con los datos
  sueltos cuando no llega `cliente_id`, y la cita **siempre** queda vinculada.
- La forma del contrato no se rompe: `cliente_id` nullable admite venir siempre
  informado, y `cliente_nombre/telefono/email` se leen de la relación.
- La reserva pública ya trae `apellido` y `documento` que solo existen en la
  tabla `clientes` — otro motivo por el que la entidad es obligatoria.
- **Decidido**: los clientes **no tienen cuenta ni login**. La clave natural
  del `firstOrCreate` es el **teléfono** (la reserva pública lo exige); nombre
  y demás datos se actualizan sobre la ficha encontrada. La gestión posterior
  de la cita (consultar, cancelar) va por el `codigo` de la cita enviado por
  WhatsApp, nunca por sesión. Consecuencia: `clientes.central_user_id` queda
  sin uso previsto (mantenerla NULL no estorba, pero no diseñar nada sobre
  ella).
- Sin cambio de SQL; sin cambio de contrato (a lo sumo anotar el
  comportamiento).

### 2.3 `profesionales` vs «empleados»

El recurso del contrato se llama `empleados` y sus ids eran `users.id` en el
backend viejo. En el esquema nuevo el actor de las citas es
`profesionales.id` (perfil local), y `users` central solo presta identidad.

- Gana **SQL** en el modelo; la **ruta y la forma** siguen siendo las del
  contrato (`/empleados`, campos de §1.9).
- Regla derivada: crear un empleado = crear `users` (central) **+**
  `profesionales` (tenant) en la misma operación; borrar/desactivar igual de
  acoplado. La integridad cross-DB es de la aplicación (no hay FK).
- `citas.empleado.id`, `/locales/{id}/profesionales/{profId}` y
  `servicio_profesional` usan todos `profesionales.id` (ver §1.4).

### 2.4 `cita_servicio` como única fuente de verdad — choca con `Cita.servicio` singular

Este es **el conflicto más serio del cruce**:

- El SQL eliminó `citas.servicio_id`: los servicios de una cita viven solo en
  `cita_servicio` (N por cita), y la reserva pública en `modo: "unica"` crea
  **una** cita con **varios** servicios encadenados.
- El tipo `Cita` del panel tiene `servicio` **singular**, y el payload manda
  `servicio_id` único.

Consecuencia: una cita creada desde la tienda con 3 servicios no cabe en el
tipo del panel — se perdería información al listarla en `/citas` y en el
calendario.

Veredicto: regla de negocio → gana **SQL** (`cita_servicio` se queda). La forma
→ hoy manda el contrato, pero el contrato es **internamente inconsistente**
(su propia reserva pública produce citas multi-servicio). Hay que cambiar
**ambos**... y solo uno de verdad:

- **SQL: sin cambios.** El panel que crea con `servicio_id` único inserta una
  sola fila en `cita_servicio` (precio y duración congelados).
- **Contrato (pendiente en `Sass-ChiraFlow`)**: evolucionar `Cita.servicio` a
  `servicios: []` (o añadir `servicios[]` manteniendo `servicio` = primer
  servicio como puente durante la transición). Mientras no se cambie, el
  Resource emite como `servicio` el primero de `cita_servicio` y el panel verá
  una cita "parcial" en el caso multi-servicio — aceptable como estado
  transitorio, no como final.

### 2.5 Estados de cita: 4 en el cliente, 6 en la BD

Contrato: `pendiente|confirmada|completada|cancelada`. SQL añade `en_curso` y
`no_asistio`. Y el propio contrato delata que los necesita: el bloque
`Reporte.actual.inasistencias` es imposible sin `no_asistio`.

- Gana **SQL**: los 6 estados se quedan.
- **Ambos**: pedir ampliar el union del frontend con `en_curso` y `no_asistio`
  (el frontend pone etiquetas y colores, es su patrón declarado). Hasta
  entonces, el panel no ofrece esas transiciones y el backend evita emitirlas
  en `/citas`... pero cualquier clamp es temporal.

### 2.6 `monto` editable vs `monto_total` como suma

El contrato dice que `monto` es editable y arranca del precio del servicio. El
SQL define `monto_total` como «suma de cita_servicio + cita_producto».

- Gana **SQL** en la invariante: `monto_total` siempre es la suma.
- Cómo se concilia: editar el `monto` en el panel **reescribe
  `cita_servicio.precio`** (el precio congelado de la línea), y `monto_total`
  se recalcula. Así el campo sigue siendo "editable" para el usuario sin romper
  la integridad. Sin cambio de SQL ni de contrato; regla de service.

### 2.7 Caja: sesión por día y dos montos

El contrato ya está alineado con la corrección del SQL (dos columnas,
`monto_final: null` = abierta):

- `monto_inicial/monto_final` ↔ `monto_apertura/monto_cierre_real`: naming,
  mapea el Resource.
- `ingresos`/`egresos` acumulados: se calculan con SUM sobre
  `caja_movimientos` del día (o se cachean); no hay columna y no hace falta.
- `monto_cierre_esperado` y `diferencia` **existen en el SQL pero el contrato
  dice que el backend no las manda** (las calcula el frontend). No es
  conflicto: se guardan al cerrar como registro de auditoría y **no se emiten**.
  Gana SQL en guardar, gana contrato en no enviar.
- `abierta_por`/`abierta_en` ↔ `registrado_por_user_id` (resolver nombre en
  central) / `created_at` ✔.
- `Movimiento.fecha`: no hay columna; se deriva de `created_at` en la zona
  horaria del tenant.
- Regla de service: `POST /caja/movimientos` exige sesión abierta y setea
  `caja_cierre_id` (en el SQL es nullable, la obligación es aplicativa).
- ⚠️ El comentario de la sección 7 del SQL describe un diseño
  (`profesional_id`/`user_id` ambos nullable + CHECK) que el DDL **no
  implementa** — el DDL final usa solo `registrado_por_user_id NOT NULL`, que
  está bien. Corregir el comentario para que no confunda.

### 2.8 Inventario: el ENUM sabe más que la pantalla

`inventario_movimientos.tipo` tiene `entrada|salida|ajuste|venta`; la pantalla
solo manda `entrada|salida`. Gana SQL: `venta` lo genera la cita con productos
y `ajuste` queda para futuro. El Form Request del endpoint manual valida solo
`entrada|salida`. Sin cambios.

### 2.9 Registro: `users.tenant_id NOT NULL` obliga a crear el tenant al registrar

`POST /register` llega solo con `{name, email, password}`, pero el SQL exige
`users.tenant_id NOT NULL` («sin excepciones») y `tenants.plan_id NOT NULL`,
`tenants.nombre NOT NULL`, y el id del tenant **es** el slug.

- Gana **SQL**: nada de tenant_id nullable temporal.
- Consecuencia operativa: el registro crea **en la misma transacción** la fila
  `tenants` en `estado='registrada'`, `db_provisionada=0` (slug provisional
  derivado del nombre, plan = plan de prueba por defecto) **y** el `users`
  dueño. El onboarding posterior completa categoría/teléfono y dispara el
  provisioning (regla 2 del CLAUDE.md). Esto además garantiza que
  `usuario.negocio.id` exista en el login, que es de donde el BFF saca
  `X-Tenant` ✔.
- **Decidido**: `users.usuario` **se elimina del esquema**. Con email único
  global (§2.10) el login es por email, el registro no necesita inventar un
  username y el nombre para mostrar es `users.nombre`. Cae también el
  `UNIQUE(tenant_id, usuario)`. Nota: esto supera lo que CLAUDE.md lista como
  «uniques centrales» — actualizarlo al arrancar las migraciones.
- **Decidido**: `POST /register` es **solo para dueños** (y luego el dueño crea
  admins/profesionales desde `/empleados`). Los clientes finales jamás se
  registran ni tienen fila en `users` (ver §2.2): toda fila de `users` es de
  alguien que hace login. Con eso, el valor `cliente` del ENUM `users.rol`
  queda vestigial — recomendado quitarlo en la migración.

### 2.10 Login por email — DECISIÓN CERRADA: email único global

**Decidido**: el email es **único global** para todos los que hacen login
(dueños, admins, profesionales). Como los clientes finales no tienen cuenta
(§2.2), *toda* fila de `users` es de alguien que hace login → la unicidad
global aplica a la tabla entera. No hay desambiguación por tenant: `{email,
password}` identifica a una sola persona, y `forgot-password` con solo
`{email}` deja de ser ambiguo.

Consecuencias sobre el SQL de referencia (divergir en la migración):

- `UNIQUE users(tenant_id, email)` → **`UNIQUE users(email)`** global (MySQL
  admite múltiples NULL en un unique, así que el email nullable no estorba).
- El índice suelto `users_email_idx` («login por email cross-tenant») sobra:
  el unique ya lo cubre.
- La columna `usuario` y su `UNIQUE(tenant_id, usuario)` desaparecen (§2.9):
  el email es el único identificador de login.
- `password_reset_tokens.tenant_id` en la PK compuesta pierde su razón de ser
  para `users`; se mantiene solo para distinguir `'_platform'`
  (platform_admins) del resto, o se simplifica la PK a `email` + un
  discriminador de guard. Decidir al escribir la migración; ya no es tema de
  producto.
- El contrato no cambia: ya asumía login por email simple ✔.

### 2.11 `citas.fuente` vs `Reporte.fuentes`

SQL: `publica|admin|whatsapp|api`. Contrato: claves `web|panel|publica`.
Ninguna de las dos listas mapea limpia (¿`web` vs `publica`?, ¿`whatsapp` y
`api` dónde caen?).

- Almacenamiento → gana SQL; claves emitidas → gana contrato.
- Propuesta de mapeo para `/reportes`: `admin→panel`, `publica→publica`,
  `whatsapp|api→web`... es forzado. Mejor: **ambos** — pedir al frontend que
  sus claves sean las del ENUM (`publica`, `admin`, `whatsapp`, `api`); él solo
  pone etiquetas, el cambio es barato y elimina el mapeo. Hasta entonces,
  emitir las tres claves del contrato con `whatsapp+api` agrupadas fuera o en
  `web`, y documentarlo.

### 2.12 Otros detalles donde la BD manda y el Resource traduce

- `users.nombre` → `name`/`nombre` según el contexto (`Usuario.name` en auth,
  `Empleado.nombre` en empleados). Naming, sin cambio.
- `users.foto` → `avatar_url` / `foto_url`. Naming.
- `citas.codigo CHAR(8)`: el ejemplo del contrato (`"R-482913"`) son 8
  caracteres — compatible tal cual ✔.
- La cita del panel no manda `local_id` (nullable en SQL): con un solo local se
  asigna el principal; con varios, hoy el formulario no elige sede — carencia
  del contrato/pantalla, no del SQL. Anotar para `vistas/` (¿de qué local es
  una cita creada desde el panel?).

---

## 3. Endpoints del contrato sin tabla que los respalde

| Endpoint | Estado |
|---|---|
| `GET /publico/.../sucursal/{id}` → `resenas`, `ultimas_resenas` | **sin tabla** (§1.11). Emitir `null`/`[]` por ahora; tabla futura en tenant |
| `GET /empleados/resumen` | sin tabla propia y no la necesita, pero es **cross-DB**: cuenta `profesionales` activos (tenant) contra `planes.max_profesionales + tenants.extra_profesionales` (central). Ojo con el centinela NULL=ilimitado (§1.5) |
| `GET /dashboard`, `GET /reportes` | agregaciones sobre tablas existentes ✔ (`ocupacion` necesita horarios de profesionales + configuración de agenda; `inasistencias` necesita `no_asistio` §2.5) |
| `GET /caja` | composición de `caja_cierres` + `caja_movimientos` ✔ |
| `POST /plan/{id}/solicitar` | respaldado por `soporte_tickets` ✔, pero el desglose (`extra_profesionales`, `extra_whatsapp`) necesita los precios de extras que faltan en `planes` (§1.5) |
| `POST /forgot-password` | `password_reset_tokens` ✔, con la ambigüedad de email de §2.10 |
| Todo el CRUD del contrato restante | tiene tabla ✔ |

## 4. Tablas y campos sin pantalla en el contrato

Esperado en su mayoría: el contrato solo cubre el panel del negocio y la tienda
pública. Nada de esto bloquea; se lista para que nadie lo busque en vano:

**Central, sin pantalla en este frontend** (sirven al panel de plataforma, al
lifecycle o al onboarding aún sin maquetar): `platform_admins`, `pagos`,
`business_categories`, `domains`, `login_alertas`, `notificaciones`,
`anuncios`, `soporte_acciones`, `tenant_metricas_diarias`, y las columnas de
lifecycle/consumo de `tenants` (`estado`, `purga_*`, `whatsapp_mensajes_*`,
`onboarding_*`).

**Tenant, campos sin pantalla:**

- `servicios.visible_publico` — la tienda lo necesita, pero el formulario de
  Servicios del panel no lo expone. Anotar para `vistas/servicios.md`; mientras
  tanto default 1.
- `servicio_profesional.precio_override` — ninguna pantalla lo edita ni lo
  muestra. Mantener (útil a futuro) pero no emitirlo.
- `clientes.apellido`, `documento`, `fecha_nacimiento`, `notas` — los llena la
  reserva pública, pero la ficha de Clientes del panel no los muestra ni edita.
  Anotar para `vistas/clientes.md`.
- `caja_movimientos.metodo` (`efectivo|tarjeta|yape|plin|...`) — **DECIDIDO,
  prioridad alta**: el método de pago **sí entra** en la pantalla de Caja.
  Deja de ser "campo sin pantalla": el payload de `POST /caja/movimientos`
  incorporará `metodo` y la maqueta de Caja debe añadir el selector (cambio
  pendiente en `Sass-ChiraFlow`). Además conecta con el módulo de Pagos QR (§6): el
  pago verificado genera movimientos con `metodo = yape|plin`.
- `citas.codigo`, `fuente`, `cancelada_motivo`, `local_id` y los timestamps de
  transición — el panel no los pinta (el código sí sale en la reserva pública).
- `inventario_movimientos` como historial — hay POST de movimiento pero ningún
  GET que liste el kardex. Tabla con escritura y sin lectura; pantalla futura.
- `grupos.descripcion` — el contrato de Grupos no la incluye. Menor.

---

## 5. Resumen ejecutivo de cambios

**Migraciones que deben divergir del SQL de referencia (gana contrato):**

1. `servicios`: + `color`, `tipo`, `max_sesiones` (§1.1)
2. `categoria_servicios`: + `descripcion`, `color`, `imagen` (§1.2)
3. `locales`: + `descripcion_publica`, `email`, `color`, `banner`, `logo`,
   `es_principal`; `horario JSON` → `horario_desde`/`horario_hasta` (§1.3)
4. `local_profesional`: + `habilitado`, `nombre_publico`, `perfil`,
   `horario_apertura`, `horario_cierre` (§1.4)
5. `planes`: + `descripcion`, promo (3 campos), precios de extras (3 campos),
   `destacado`; resolver centinelas NULL→999 (§1.5)
6. `soporte_tickets`: + `respuesta` (§1.7)
7. `plantilla_whatsapps`: + `nombre`; claves de `evento` = las del contrato (§1.8)
8. `profesionales.tipo_pago`: `mixto` → `ambos` (§1.9)
9. `tenants`: soporte para `elegible_promo` (§1.6)
10. `productos.stock_minimo`: default 5 (§1.10)

**Cambios a pedir en el repo del frontend (`Sass-ChiraFlow`) — el contrato no se toca
desde aquí:**

- `Cita.servicio` → `servicios[]` (o campo adicional) — §2.4, el más urgente
- Union de `estado` de cita: + `en_curso`, `no_asistio` — §2.5
- Claves de `Reporte.fuentes` alineadas al ENUM de la BD — §2.11
- `prioridad` de ticket: + `critica` — §1.7
- `Suscripcion.estado`: resolver el caso `cancelada` — §1.6
- **Método de pago en la pantalla de Caja** (selector + `metodo` en el payload
  de `POST /caja/movimientos`) — §4, decidido, prioridad alta
- Menores/anotaciones: id de `LocalProfesional` = `profesionales.id` (§1.4),
  `local_id` en el formulario de citas (§2.12), retirar o renombrar
  `Empleado.usuario` — mientras exista, el backend emitirá el email en ese
  campo (§1.9)

**Migraciones adicionales derivadas de decisiones cerradas:**

11. `users`: `UNIQUE(email)` global en lugar de `UNIQUE(tenant_id, email)`;
    quitar `users_email_idx`; **eliminar la columna `usuario`** y su
    `UNIQUE(tenant_id, usuario)`; quitar `cliente` del ENUM `rol`
    (§2.10, §2.9)
12. `password_reset_tokens`: simplificar la PK (el email vuelve a ser único
    global) (§2.10)
13. `profesionales`: + `atiende TINYINT(1) NOT NULL DEFAULT 1` — dueño/admin
    también tienen fila; el flag controla agenda y tienda pública (§1.9)
14. Pagos QR (§6, esquema cerrado; contrato del frontend aún por maquetar):
    `tenants` + `pagos_qr_activo`, `qr_imagen`, `instrucciones_pago`;
    `citas` + `metodo_pago_eleccion`, `estado_pago` (derivado, materializado);
    tabla nueva **`cita_pagos`** (1-N: re-subida y seña sin migración futura)

**Decisiones cerradas (2026-08-14) — ninguna abierta:**

- Email único global para todo el que hace login; sin desambiguación por
  tenant (§2.10)
- `users.usuario` eliminado; el login es por email y el display es
  `users.nombre` (§2.9) — CLAUDE.md menciona aún el unique
  `users(tenant_id, usuario)`: actualizarlo al arrancar las migraciones
- Clientes sin cuenta ni login: `firstOrCreate` por teléfono, gestión por
  `codigo` vía WhatsApp (§2.2)
- Dueño/admin con fila en `profesionales` + flag `atiende` (§1.9)
- Pagos QR: v1 sin caducidad automática (riesgo documentado en §6.2.1),
  modelo `cita_pagos`, pago verificado genera `caja_movimientos` con
  garantía de adopción anti-huérfanos, v1 solo pago total (§6)

**Documento congelado** — siguiente paso: convertir los SQL de `docs/` más las
divergencias 1–14 en migraciones (`database/migrations/` central y
`database/migrations/tenant/`).

---

## 6. Pagos QR (módulo no contratado)

Boceto heredado del sistema anterior, **no maquetado** en el frontend React:
no aparece en `api-contract.md` ni en las 16 vistas. Sin pasarela de pagos: el
tenant sube su propio QR de Yape/Plin (Configuración → pestaña "Pagos QR":
checkbox de activación, imagen del QR, texto de instrucciones), y la reserva
pública gana un paso "Método de pago" con dos opciones: **"Pagar ahora"**
(muestra el QR al confirmar y el cliente sube su comprobante) o **"Pagar en el
local"**.

Estado: **diseño de esquema cerrado** (decisiones del 2026-08-14) — ya puede
convertirse en migración. Lo que sigue pendiente del frontend es la maqueta de
las pantallas y su entrada en `api-contract.md`: las formas JSON definitivas se
validarán contra el contrato cuando exista.

### 6.1 Columnas propuestas

**Configuración del tenant — en `tenants` (BD central), no en el tenant.**
Argumento: la tienda pública necesita saber si el módulo está activo y mostrar
el QR/instrucciones **antes de tocar la BD del tenant** — el mismo criterio por
el que `logo`, `cover`, `color_primario` y `terminos_servicio` ya son columnas
centrales (lo dice el propio encabezado de `01_bd_central.sql`). Además la
pestaña vive en Configuración, que ya lee/escribe la central (§1.6). Ir por
columnas y no por el JSON `configuracion` para mantener la coherencia con el
resto del bloque "Marca / sitio público":

| Columna (en `tenants`) | Tipo propuesto | Notas |
|---|---|---|
| `pagos_qr_activo` | `TINYINT(1) NOT NULL DEFAULT 0` | el checkbox |
| `qr_imagen` | `VARCHAR(255) NULL` | ruta de la imagen; **sí puede ser pública** (se muestra en la tienda) |
| `instrucciones_pago` | `TEXT NULL` | texto libre que acompaña al QR |

**Citas (BD tenant) — DECIDIDO: tabla `cita_pagos` 1-N, no columnas planas.**
Cada intento de pago es una fila: cubre la re-subida tras rechazo (un intento
por fila) y deja el adelanto/seña a un `monto < monto_total` de distancia, sin
migración futura.

En `citas` quedan solo dos columnas:

| Columna | Tipo | Notas |
|---|---|---|
| `metodo_pago_eleccion` | `ENUM('ahora','local') NULL` | NULL = módulo inactivo o cita creada desde el panel |
| `estado_pago` | `ENUM('pendiente','comprobante_subido','verificado','rechazado') NULL` | **derivado** del último intento en `cita_pagos` (`pendiente` = eligió "ahora" y aún no hay intento). Se materializa para que el filtro `?estado_pago=` de la tabla de Citas no haga subconsulta; lo mantiene el service en cada transición, nunca se escribe directo |

Tabla nueva `cita_pagos`:

| Columna | Tipo | Notas |
|---|---|---|
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT` | |
| `cita_id` | `BIGINT UNSIGNED NOT NULL` | FK → `citas` ON DELETE CASCADE |
| `monto` | `DECIMAL(10,2) NOT NULL` | v1: siempre = `monto_total`; con seña será parcial |
| `comprobante` | `VARCHAR(255) NOT NULL` | ruta del archivo (disco privado, §6.3) |
| `estado` | `ENUM('comprobante_subido','verificado','rechazado') NOT NULL DEFAULT 'comprobante_subido'` | |
| `verificado_por_user_id` | `BIGINT UNSIGNED NULL` | → central.users.id, **sin FK** (patrón `registrado_por_user_id`) |
| `verificado_el` | `TIMESTAMP NULL` | |
| `rechazo_motivo` | `VARCHAR(255) NULL` | solo con `estado='rechazado'` |
| `created_at` / `updated_at` | `TIMESTAMP NULL` | `created_at` = momento de subida del comprobante |

Índice: `(cita_id, created_at)`. Regla de service: solo puede existir **un**
intento en `comprobante_subido` a la vez por cita (verificar o rechazar antes
de aceptar otro).

### 6.2 Preguntas de producto (responder antes de implementar)

1. **Si el cliente elige "pagar ahora" y no sube comprobante, ¿la cita se
   reserva igual o se libera el cupo tras X minutos?**
   **CERRADO: v1 sin caducidad automática** — la cita se reserva igual. Sin
   pasarela no hay confirmación instantánea (el cliente paga por Yape y sube la
   captura minutos u horas después), y un job de expiración crea carreras con
   el anti-solape. La cita entra `pendiente` con `estado_pago='pendiente'`, la
   plantilla WhatsApp recuerda el pago, y liberar el cupo es decisión manual
   del negocio (cancelar desde el panel).
   **Riesgo aceptado, a revisar con datos reales**: un cliente puede bloquear
   agenda sin pagar (elegir "ahora", no subir nada y no asistir). Métricas a
   mirar cuando haya tráfico: % de citas con elección "ahora" que siguen sin
   comprobante a las 24 h, y tasa de `no_asistio` de citas sin pago frente a
   citas verificadas. Si los números lo justifican, la expiración se implementa
   como scheduled command con X configurable — alcance v2, no antes.

2. **¿Dónde verifica el dueño los comprobantes?** Es una pantalla que hoy no
   existe en las 16 vistas.
   Recomendación: **no crear una 17ª vista**: columna/badge de `estado_pago` en
   la tabla de Citas + filtro `?estado_pago=comprobante_subido`, y en el modal
   de la cita el comprobante con botones Verificar / Rechazar (motivo). Encaja
   en el patrón declarado del contrato (filtros como query params, nada de
   rutas nuevas) y reutiliza la pantalla con más tráfico. Una bandeja dedicada
   solo se justifica si el volumen lo pide — medible después.

3. **¿El pago verificado genera automáticamente un `caja_movimientos` con
   `metodo=yape|plin`?**
   **CERRADO: sí** — conexión directa con la decisión de §4. Al verificar:
   movimiento `ingreso` con su `metodo`, `cita_id`, concepto autogenerado y
   `registrado_por_user_id` = quien verificó. Si hay sesión abierta, nace con
   su `caja_cierre_id`; si la caja está cerrada, nace con `caja_cierre_id
   NULL` — y aquí aplica la garantía siguiente.
   **Garantía anti-huérfanos (decidida)**: un movimiento con `caja_cierre_id
   NULL` no puede quedar invisible. Regla de **adopción**: `POST /caja/abrir`
   asigna a la sesión recién abierta todos los movimientos pendientes
   (`caja_cierre_id IS NULL`), de modo que entran en los `ingresos` acumulados
   y en el arqueo de esa sesión. Mientras no se abra caja, `GET /caja` los
   incluye igualmente en `movimientos` (la forma del contrato lo permite:
   `sesion: null` + lista), y el reporte de caja del día los cuenta. Cubrir con
   test: verificar un pago con caja cerrada → abrir caja → el movimiento
   aparece adoptado y sumado.

4. **¿Pago total o adelanto/seña configurable por el negocio?**
   **CERRADO: v1 solo pago total** (`cita_pagos.monto` = `monto_total` de la
   cita). La elección de la tabla `cita_pagos` en §6.1 deja la seña a un paso:
   cuando se habilite, será `monto` parcial + un campo de configuración
   (`porcentaje_sena` en `tenants`), sin tocar el esquema de pagos.

### 6.3 Requisitos de seguridad de la subida de comprobantes

El upload del comprobante es **un POST desde internet sin autenticación** (los
clientes no tienen cuenta — §2.2). Requisitos no negociables para ese endpoint:

- **Autorización por posesión del `codigo`**: la subida solo se acepta contra
  una cita existente identificada por su `codigo`, con
  `metodo_pago_eleccion='ahora'` y `estado_pago` en `pendiente` o `rechazado`
  (la re-subida tras rechazo está contemplada por diseño: cada intento es una
  fila nueva en `cita_pagos`). El código actúa como capability token; nunca
  aceptar `cita_id` numérico enumerable.
- **Validar el MIME real, no la extensión**: inspección de contenido
  (`finfo`/detección de firma), whitelist corta (`image/jpeg`, `image/png`,
  `image/webp`; decidir si se admite `application/pdf`). Rechazar cualquier
  otra cosa con 422.
- **Re-encodificar la imagen** (Intervention/GD): mata payloads embebidos y
  metadatos EXIF de paso.
- **Límite de tamaño** estricto (propuesta: 5 MB) validado también a nivel de
  servidor web, no solo en el Form Request.
- **Renombrado total**: nombre aleatorio (UUID) + extensión derivada del MIME
  detectado. El nombre original del archivo no se conserva ni se refleja en
  ninguna respuesta.
- **Almacenamiento fuera del directorio público**: disco privado
  (`storage/app/...` del tenant), jamás `public/`. Nada de acceso por ruta
  directa.
- **Servido solo por URL firmada temporal**, generada para el panel
  (verificación del dueño) detrás de `auth:sanctum` + tenancy; el archivo se
  entrega vía controlador con `Content-Type` correcto y
  `Content-Disposition` que impida interpretarlo como HTML.
- **Rate limiting** por IP y por `codigo` de cita (p. ej. 5 intentos/hora):
  es el único endpoint de escritura de archivos sin sesión de toda la API.
- El QR del negocio (`tenants.qr_imagen`) es el caso contrario: contenido
  público por diseño, puede vivir en disco público sin firma.
