# Pendientes de contrato (backend → mi-saas)

Lo que este backend necesita del contrato y **no puede editar desde aquí**
(`api-contract.md` y `vistas/` solo se tocan desde el repo `mi-saas`, en el
ritual de cierre de cada sprint — plan-sprints §4).

Formato de entrada:

```
## [Sprint N] Título corto
- **Qué**: el cambio o la aclaración que se pide.
- **Por qué**: qué se rompió, faltó o contradijo al implementar.
- **Ref**: § de discrepancias.md si aplica.
- **Estado**: pendiente | aceptado (fecha, sprint) | rechazado (motivo).
```

---

## [Sprint 0] Decisión X-Tenant: el tenant se deriva del token

- **Qué**: el backend deriva el tenant del dueño del token; el header
  `X-Tenant` quedó como pista redundante que SIEMPRE se valida (si llega y
  no coincide → 404). El contrato dice «si el backend prefiere resolver el
  inquilino desde el token, la cabecera sobra y se quita del proxy» — puede
  quitarse del BFF o dejarse: ambas funcionan.
- **Por qué**: tarea 4 del Sprint 0.B pedía tomar y documentar la decisión.
- **Ref**: CLAUDE.md § Arquitectura; middleware `tenant.token`.
- **Estado**: pendiente (reflejar en api-contract.md § Autenticación).

## [Sprint 0] El registro deja `slug = NULL` (no «slug temporal aleatorio»)

- **Qué**: con id y slug desacoplados, el tenant nace con `slug NULL` en vez
  del «slug temporal aleatorio» que menciona el contrato. Efecto observable
  idéntico: la tienda responde 404 hasta que el paso 1 fija el slug.
- **Por qué**: un slug basura no aporta nada si el id ya nombra la BD.
- **Ref**: CLAUDE.md § Decisiones de producto (id/slug).
- **Estado**: **aceptado** (2026-08-16, Sprint 0) — api-contract.md,
  registro.md y plan-sprints.md ya dicen `slug NULL`.

## [Sprint 0] Login con credenciales malas → 422 `errors.email`

- **Qué**: el login fallido responde 422 con `errors.email` («Las
  credenciales no coinciden…»), no 401 — el 401 queda para peticiones sin
  token o con token revocado, que es lo que el interceptor redirige a
  /login. Confirmar que el formulario de login pinta ese 422.
- **Por qué**: el contrato no fija el código del login fallido.
- **Estado**: **aceptado** (2026-08-16, Sprint 0) — documentado en
  api-contract.md § Autenticación; el formulario de login pinta ese 422 bajo
  el campo email (verificado en el navegador).

## [Sprint 0] Texto comercial del plan Pro

- **Qué**: confirmar la `descripcion` del plan Pro. La ficha
  `vistas/mi-plan.md` la trunca («Integraciones…»); el seeder usa un texto
  provisional: «Integraciones y herramientas avanzadas para negocios en
  crecimiento».
- **Por qué**: el Blade viejo la generaba con un ternario por slug y la ficha
  no copió el texto completo de Pro.
- **Ref**: §1.5 de discrepancias (descripcion pasó a columna de `planes`).
- **Estado**: pendiente.

## [Sprint 0] `POST /email/reenviar` tiene cooldown por correo → 429 con `retry_after`

- **Qué**: el contrato solo documenta `200` para `/email/reenviar`. La
  implementación añade un límite **por correo** (1 cada 60 s, 5 por hora)
  que responde `429 { message, retry_after }`, con `retry_after` en
  segundos. El `200` también devuelve `retry_after: 60` para que el botón
  «Reenviar» del panel «Revisa tu correo» arranque su cuenta atrás sin
  adivinar el valor.
- **Por qué**: el `throttle` de la ruta es por IP y no impide usar el
  formulario para reventar la bandeja de un tercero, ni protege la cuota de
  Resend. El límite se cuenta antes de mirar si el usuario existe, así que
  sigue sin filtrar qué correos están registrados.
- **Ref**: `VerificacionCorreoController::reenviar`, `vistas/registro.md` §
  «Revisa tu correo».
- **Estado**: **aceptado** (2026-08-16, Sprint 0) — documentado en
  api-contract.md § Autenticación, y el botón «Reenviar» ya usa el
  `retry_after` de la respuesta.


## [Sprint 0] Suscripción vencida: `usuario.negocio.estado` y `403 suscripcion_vencida`

- **Qué**: tres cosas que el contrato no cubre.
  1. `Usuario.negocio` gana **`estado`** (`prueba` | `activa` | `vencida`),
     con el mapeo de §1.6 de discrepancias. Va en la respuesta de `/login` y
     de `GET /user`.
  2. Con el negocio suspendido, **todo el panel responde
     `403 { message, codigo: "suscripcion_vencida" }`**, salvo `GET /user`,
     `POST /logout` y —cuando existan— Mi Plan y Soporte.
  3. Con el negocio en purga, **`/login` responde
     `403 { message, codigo: "cuenta_dada_de_baja" }`** y no emite token.
- **Por qué**: traspaso FE → BE del 2026-08-22 — el login no miraba
  `tenants.estado`, así que un negocio suspendido usaba el panel completo y
  no había puerta de cobro. Decisión de producto (2026-08-22): el suspendido
  **entra y paga**; bloquearle el login lo deja sin forma de regularizar y
  convierte cada renovación en una conversación de WhatsApp. El `codigo`
  existe para que el frontend distinga esta pared de un permiso insuficiente
  y pinte el aviso con el botón de renovar; el `estado` del usuario le
  permite pintarlo sin esperar a que falle una petición.
- **Ref**: §1.6 de discrepancias (mapeo de los 6 estados de lifecycle a los 3
  del contrato), `SuscripcionActiva`, `AuthController::login`,
  `UsuarioResource`.
- **Estado**: pendiente.


## [Sprint 0] Mi perfil: `PUT /user`, `PUT /user/password` y el `Usuario` completo

- **Qué**: implementados los dos endpoints del contrato, más los campos que
  `GET /user` no emitía: `nombre` y `apellido` **sueltos**, `telefono`,
  `documento` y `negocio.slug`. Nueva columna `users.documento`
  (VARCHAR 20, nullable, sin UNIQUE).
- **Por qué**: la pantalla de Mi perfil estaba maquetada y leyendo real, pero
  guardaba contra un mock. Sin `nombre`/`apellido` sueltos, el formulario
  tenía que partir `name` por el espacio, que es lossy: «Ana María Quispe»
  se guardaba como nombre «Ana» y apellido «María Quispe». Sin
  `negocio.slug` el panel no puede construir el enlace a la tienda.
- **Divergencias que conviene fijar en el contrato**:
  1. `PUT /user/password` responde `200 { message }` y queda **fuera** de la
     puerta de suscripción vencida: cambiar la contraseña es una acción de
     seguridad y no debe bloquearse por deber una cuota. `PUT /user` sí va
     detrás de la puerta.
  2. `password` valida `different:password_actual`.
  3. `telefono` exige el mismo `+51` + 9 dígitos que el registro; `documento`
     acepta hasta 20 caracteres alfanuméricos con guiones (un carné de
     extranjería no tiene 8 dígitos).
- **Nota de planificación**: **Mi perfil no figura en ningún módulo de
  `plan-sprints.md`**. Se coló entre el Sprint 0 (auth) y el 2.B
  (Configuración, que es del negocio y no de la persona). Conviene añadirlo
  al plan como parte del 0.B, que es donde acabó implementándose.
- **Ref**: `vistas/perfil.md`, contrato § Autenticación y § Usuario,
  `PerfilService` (propagación a `profesionales`).
- **Estado**: pendiente.

---

## [Sprint 2] Roles del negocio: los tres de sistema y los que crea el dueño

- **Qué**: el negocio gestiona sus propios roles. Esquema ya escrito (tabla
  `roles` en la BD del tenant + `profesionales.rol_id`); faltan endpoints,
  pantalla y resolución de permisos, que van con Empleados en el Sprint 2.

  **Modelo cerrado (2026-08-27):**

  | Rol | Editar | Borrar | Duplicar |
  |---|---|---|---|
  | Dueño (`clave=dueno`) | ✗ | ✗ | ✗ |
  | Administrador (`admin`) | ✓ | ✗ | ✓ |
  | Profesional (`profesional`) | ✓ | ✗ | ✓ |
  | Los que cree el dueño | ✓ | ✓ | ✓ |

  - **Permisos: dos niveles por módulo**, `ver` y `gestionar` (`null` = sin
    acceso). NO verbos CRUD por entidad: nadie en una barbería quiere «puede
    crear clientes pero no borrarlos», y multiplica los tests por cuatro.
    Guardados en JSON, así que añadir módulos no migra N bases.
  - **`solo_propios`** es del rol, no de cada vista: un profesional que «ve
    citas» ve las suyas; un administrador, las de todos. *Qué* puede tocar y
    *sobre quién* son preguntas distintas.
  - **`editado_at`** NULL mientras el negocio no toque el rol. Cuando se lance
    un módulo nuevo y haya que añadirlo a los presets, el script solo pisa los
    que siguen en NULL: a quien personalizó su rol no se le deshace la
    decisión a su espalda.
  - **`cargo`** (ya existe en `profesionales`) es texto libre y NO es permiso:
    «Recepcionista» es un puesto, no un nivel de acceso. Los roles son pocos y
    los pone el sistema; los nombres son infinitos y los pone el dueño.
  - **Duplicar** = «guardar como»: copia las casillas de un rol para partir de
    algo, con nombre nuevo (único por negocio). No es un segundo rol con el
    mismo nombre. El de Dueño ni se duplica: sería fabricar un segundo
    superusuario.
  - **Barandillas**: solo el dueño gestiona roles (si pudiera un admin, se
    crearía uno con todo y se lo asignaría — escalada en dos clics); nadie se
    concede lo que no tiene; un rol en uso no se borra (lo impone la FK con
    `restrictOnDelete`, no solo el service).
  - **Facturación no se delega**: el preset de Administrador la trae en
    `null`. Es lo único que separa al dueño de su mano derecha.

- **Por qué**: el frontend está maquetando Administración y necesita saber si
  el select de rol es fijo o dinámico. AgendaPro (competidor de referencia)
  usa roles fijos del proveedor más permisos extra por persona; sus variantes
  «(Sin edición)» **son** el nivel `ver`, presentado como roles aparte. Aquí
  se modela con un solo campo, que da las mismas combinaciones con la mitad
  de matriz.

- **Lo que el contrato necesita**: `Usuario` debería emitir las capacidades ya
  resueltas (`permisos` + `solo_propios`), no solo `rol`. Si el menú de Next
  deriva los permisos por su cuenta, acaba habiendo dos matrices que divergen.
  Ocultar una opción del menú NO es autorización: el backend responde 403
  igual.

- **Pendiente de decidir**: **alcance por local**. AgendaPro lo repite
  («permisos sobre uno o más locales»), y `local_profesional` ya existe en las
  migraciones, así que no haría falta tabla nueva. Pero `vistas/empleados.md`
  lo descartó explícitamente («el empleado no se asigna a una sede en este
  formulario»). Resolver en el Sprint 3, con Locales.

- **Ref**: `vistas/empleados.md` § `rol` — valores (deja abierto «¿qué
  permisos tiene cada uno?»), `app/Support/RolesSistema.php` (matriz de
  presets), migración `tenant/2026_08_27_000001_create_roles_table.php`.
- **Estado**: pendiente.
