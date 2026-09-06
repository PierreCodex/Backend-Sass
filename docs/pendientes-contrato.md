# Pendientes de contrato (backend → Sass-ChiraFlow)

Lo que este backend necesita del contrato y **no puede editar desde aquí**
(`api-contract.md` y `vistas/` solo se tocan desde el repo `Sass-ChiraFlow`, en el
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

- **Ref**: `vistas/empleados.md` § `rol` — valores (deja abierto «qué
  permisos tiene cada uno»), `app/Support/RolesSistema.php` (matriz de
  presets), migración `tenant/2026_08_27_000001_create_roles_table.php`.
- **Estado**: **endpoints hechos** (2026-09-04, 20 tests). Falta la pantalla y
  falta que Empleados los use — ver el apartado siguiente.

### Los endpoints, tal como responden

`GET · POST · PUT · DELETE /api/roles` — recurso completo, dentro de
`tenancy.init` (los roles viven en la BD del negocio).

```json
{
  "data": [
    {
      "id": 2,
      "nombre": "Administrador",
      "clave": "admin",
      "sistema": true,
      "permisos": { "dashboard": "ver", "citas": "gestionar", "…": null },
      "solo_propios": false,
      "editable": true,
      "borrable": false,
      "duplicable": true,
      "usuarios_count": 3
    }
  ],
  "meta": { "…": "paginación de siempre" },
  "modulos": ["dashboard", "citas", "calendario", "…"]
}
```

Cinco cosas que conviene saber antes de maquetar:

1. **`permisos` trae SIEMPRE los 14 módulos**, con `null` donde no hay acceso,
   aunque el JSON guardado tenga cinco claves. Mismo criterio que el horario
   de empleados: si llegara con huecos, el formulario necesitaría su propia
   lista de módulos para rellenarlos y habría dos listas divergiendo. Al
   guardar da igual mandar los nulls: el backend los descarta.
2. **`modulos` viaja junto al listado**, fuera de `data`. Es la lista completa
   y ordenada para las filas de la matriz; no hay endpoint aparte porque son
   una consulta y una constante para pintar una sola tabla.
3. **`editable`, `borrable` y `duplicable` vienen resueltos.** No los deduzcas
   del `sistema` ni de la `clave`: si el formulario reimplementa la matriz,
   acaba divergiendo de la de aquí, que es la que manda. Sirven para
   deshabilitar botones; el 422 salta igual si se intenta.
4. **`clave` es de solo lectura y sobrevive al renombrado.** El negocio puede
   llamar «Encargada» a Administrador; la clave es lo que deja al backend
   seguir reconociéndolo. `clave` y `sistema` enviados en el payload se
   **ignoran** — no son 422, simplemente no existen para la validación.
5. **Leer sí, escribir solo el dueño.** `GET /roles` y `GET /roles/{id}` los
   puede pedir cualquier usuario del negocio; `POST`, `PUT` y `DELETE`
   responden **403** a quien no sea el dueño.

   El motivo de la mitad restrictiva: si un administrador pudiera crear roles,
   se haría uno con todo marcado y se lo asignaría — escalada en dos clics.
   Dar de alta gente y decidir qué puede hacer la gente son permisos distintos,
   aunque su preset traiga `empleados: gestionar`.

   Y el de la mitad abierta: el select de rol del formulario de empleados tiene
   que funcionarle al administrador, que sí puede dar altas. La lista tampoco
   es un secreto dentro del negocio — es su organigrama. Se deja abierta a
   cualquier usuario del tenant y no a `dueno|admin` para no inventar una
   segunda tabla de permisos por rol al lado de la que ya existe; cuando el
   backend resuelva capacidades de verdad, la lectura pasará a pedir
   `empleados.ver`.

**Payload de escritura**: `nombre` (requerido, único), `permisos` (objeto
módulo → `ver` | `gestionar` | `null`) y `solo_propios` (bool). Nada más.

**Los 422 con nombre propio**:

| Situación | Campo | Texto |
|---|---|---|
| Nombre repetido | `nombre` | Ya tienes un rol con ese nombre. |
| Módulo inexistente | `permisos` | Estos módulos no existen: … |
| Nivel inventado | `permisos.{modulo}` | El nivel de acceso solo puede ser «ver» o «gestionar». |
| Editar el rol de Dueño | `rol` | El rol del dueño no se puede editar. |
| Borrar uno de sistema | `rol` | Los roles del sistema no se pueden borrar… |
| Borrar uno en uso | `rol` | Este rol lo usan N persona(s)… |

**Duplicar no es un endpoint**: es leer un rol y hacer `POST` con sus permisos
y otro nombre. El de Dueño no se duplica (`duplicable: false`).

---

## [Sprint 3] Escalada de privilegios por `/profesionales` — cerrada

Reportada por la sesión de frontend el 2026-09-05, y el diagnóstico era exacto.

### Qué pasaba

`POST /profesionales` acepta el objeto opcional `usuario: {email, rol_id}` y
llama derecho a `UsuarioService::crear()`. El candado de «un solo administrador
general» vivía **solo en `UsuarioController`**, así que ese camino entraba por
debajo.

Lo decisivo es quién puede llamar a cada ruta:

| Ruta | Quién |
|---|---|
| `POST /usuarios` | solo el administrador general |
| `POST /profesionales` | `puede:empleados,gestionar` — que el **administrador local** tiene |

O sea: un administrador local se daba de alta como profesional con el `rol_id`
del general y se quedaba con facturación y con la capacidad de repartir roles.
Dos peticiones. Y la cuenta resultante **no se podía deshacer desde el panel**,
porque `destroy` se niega sobre un administrador general.

Es exactamente el escenario que nuestra propia documentación nombra al explicar
por qué roles y cuentas son solo del general: *«quien puede crear cuentas y
repartir roles puede fabricarse un segundo dueño»*. La regla estaba escrita;
este camino no pasaba por donde se aplica.

### La corrección

**El invariante vive ahora en `UsuarioService`**, que es por donde pasan los dos
caminos: `crear()` rechaza `admin_general` siempre, y `actualizar()` protege el
«ni se le quita a quien lo tiene, ni se le da a quien no».

Y se **quitaron las comprobaciones duplicadas del controlador**. Dos copias de
una regla de seguridad no son el doble de seguras: son dos sitios que divergen,
y el que se olvida es justo el que no se prueba.

Puesto ahí, el día que aparezca un tercer camino —una importación, un comando—
ya está cubierto. De hecho el comando de pruebas que se añadió el mismo día
tropieza con el mismo candado, y así debe ser.

### De cara al cliente

`POST /profesionales` con `usuario.rol_id` = el del administrador general
responde **422** en `usuario.rol_id`, con «Ya hay un administrador general en
este negocio». Nada queda creado: ni cuenta central, ni fila en el negocio, ni
ficha de profesional.

Vuestro formulario ya no lo ofrecía —asigna siempre el rol `profesional`— pero
eso es interfaz, no autorización, y la distinción es la misma que hacemos con
el menú: esconder una opción no cierra la puerta.

### El apunte menor, que resultó no serlo tanto

Cuando el alta de la cuenta fallaba **después** de crear la fila, la cuenta se
quedaba. Al frontend le dejó seis huérfanas con el 500 de `invitacion_tokens`.

No lo dejamos como está: **si la invitación falla, se deshace el alta entera.**
El razonamiento es el que ellos dieron — el alta responde 500, el dueño cree
que no se creó, lo reintenta y se come un «correo ya registrado» sin entender
por qué, porque el email es único global y ya lo ocupa una fila que no ve.
Compensando, el fallo es atómico y el reintento funciona. El botón de reenviar
sigue cubriendo el caso común, que es que el correo salga y no llegue.

### Y un comando para probar el panel de verdad

`php artisan tenant:cuenta-de-prueba {tenant} --rol=profesional`

Crea una cuenta con contraseña conocida (`secreta123` por defecto). Existe
porque el alta normal manda una invitación y la contraseña la elige el
empleado — que es lo correcto, y que hace imposible probar a mano lo que ve una
recepcionista sin pasar por el correo.

**Solo corre en `local`**, y **se niega a crear un administrador general**. Un
atajo de desarrollo que se salta una regla de seguridad es exactamente como se
cuelan.

- **Estado**: hecho (2026-09-05). Tres tests nuevos, incluido el escenario
  completo con un administrador local. Suite: 237 tests, 1068 aserciones.

---

## [Sprint 3] Los permisos ya se aplican: middleware de capacidades

Hasta hoy `roles.permisos` se guardaba y **no lo leía nadie**. Un profesional
cuyo rol decía «clientes: ver» podía crear, editar y borrar clientes igual que
el titular; lo único que existía era el candado de administrador general sobre
roles y cuentas. Teníamos tres ejes de permisos construidos —qué, sobre quién,
dónde— y ninguno hacía nada.

Eso se acabó. Y llega antes del Sprint 4 a propósito: las citas son el primer
sitio donde a alguien le importa de verdad quién ve qué.

### Cómo funciona

Cada endpoint de módulo va detrás de su capacidad
(`puede:clientes,gestionar`). Se pregunta por **capacidad y nunca por rol** —
es lo que permite que el negocio invente «Recepcionista» o «Barbero con
inventario» sin que haya que tocar un endpoint: cambia de dónde sale la
respuesta, no quién la hace. Hay test de eso.

**`gestionar` incluye `ver`**, así que los listados se anotan una sola vez.

**Falla cerrado**: sin cuenta en el negocio o sin rol, no puede nada.

### Lo que cambia de cara al cliente

**`403` con `codigo: "sin_permiso"`** en cualquier endpoint cuyo módulo no
tenga en su matriz.

Es 403 y no 404 a propósito: el recurso existe y es de su negocio, lo que falta
es permiso. El 404 se reserva para lo de otro tenant — mezclarlos haría
imposible distinguir «no tienes acceso» de «no existe», que es justo lo que el
panel necesita para decidir si enseña un aviso o una pantalla vacía.

**La pared de cobro gana**: en un negocio suspendido sale
`suscripcion_vencida` aunque además falte el permiso, porque es el error sobre
el que alguien puede actuar. Hay test de la precedencia.

### `GET /api/capacidades`

Lo que puede hacer quien está mirando, **ya resuelto**:

```json
{
  "data": {
    "permisos": { "dashboard": "ver", "citas": "gestionar", "caja": null, "…": null },
    "solo_propios": true,
    "locales": null
  }
}
```

- **`permisos`**: los 14 módulos siempre, `null` donde no hay acceso.
- **`solo_propios`**: ve lo suyo y no lo de sus compañeros. No es un permiso —
  es sobre *quién*, no sobre *qué*.
- **`locales`**: `null` = todas las sedes; una lista de ids = solo esas. `null`
  y no la lista completa, para que se distinga «sin restricción» de
  «restringido a estas» y una lista vacía signifique de verdad ninguna.

Endpoint aparte y no dentro del `Usuario` de `/login` por arquitectura: los
permisos viven en la base del negocio, y `/login` y `/user` se resuelven
enteros en la central — conectar a la del tenant para cada uno cuesta y además
falla con 503 mientras el provisioning no ha terminado.

**Lo manda el backend resuelto y no la matriz cruda**: si el menú dedujera los
permisos por su cuenta acabaría habiendo dos matrices, y la que manda es la de
aquí.

**Y conviene decirlo aunque sea obvio: esconder una opción del menú NO es
autorización.** El backend responde 403 igual. Esto sirve para no enseñar
puertas cerradas, no para cerrarlas.

### El alcance por sedes ya filtra

`GET /locales` devuelve solo las sedes asignadas, y una fuera del alcance da
**404** — no 403. Para esa persona esa sede no existe, igual que la de otro
negocio; un 403 confirmaría que está ahí, que es lo que el alcance viene a
ocultar. Lo mismo en `/locales/{id}/profesionales`.

Con esto **el selector de sedes ya se puede ofrecer en el panel**: lo que
guarda, ahora se cumple. (Antes de esto pedimos no enseñarlo, y ese aviso
queda anulado.)

### Lo que todavía NO hace

**`solo_propios` se emite pero no filtra nada.** Es correcto: hoy no hay nada
suyo que filtrar — sus citas llegan en el Sprint 4, y es ahí donde el flag
empieza a significar algo. Se emite ya para que el panel pueda contar con él
desde el principio.

**Roles y cuentas siguen siendo del administrador general** por un candado
aparte, no por capacidad: no son módulos de la matriz. Dar de alta gente y
decidir qué puede hacer la gente son permisos distintos, y quien reparte roles
puede fabricarse un segundo titular.

- **Estado**: hecho (2026-09-05). Suite: 234 tests, 1055 aserciones.

---

## [Sprint 3] Locales, el pivote por sede, grupos, y el alcance por sedes

Módulo 3.A cerrado por el lado backend (2026-09-05). Cuatro cosas.

### 1. `/api/locales` — las sedes

CRUD multipart (`POST` + `_method=PUT` al editar). Dos reglas del backend:

**`es_principal` no se acepta en el payload.** El primer local que se crea nace
como principal y ya. Si lo eligiera el formulario, un negocio podría quedarse
sin ninguno con dos peticiones — y el principal es del que cuelga la tienda
pública y el único que no se puede borrar. Sale resuelto en la respuesta para
que la pantalla esconda el botón; el **422 salta igual** si se intenta.

**El horario es un rango simple** (`horario_desde` / `horario_hasta`), no por
día. Se guarda como JSON internamente para que el día que alguien quiera
«sábado hasta la 1, domingo cerrado» quepa sin migrar N bases, pero eso no se
nota desde fuera.

Y **se valida que el cierre sea posterior a la apertura**. La ficha señala un
local real con «21:00 – 16:07»: es un error de captura, y de ahí saldrían huecos
imposibles cuando el Sprint 4 calcule disponibilidad. Ahora da 422.

### 2. `/api/locales/{local}/profesionales` — quién atiende dónde

**El listado devuelve UNA FILA POR CADA profesional del negocio**, tenga o no
asignación en esa sede. Quien no trabaja allí vuelve con `habilitado: false` y
el resto en null. Es lo que permite que la pantalla sea una sola tabla con
interruptores en vez de dos listas y un botón de añadir.

**No hay `store` ni `destroy`.** El `PUT` hace `syncWithoutDetaching`, así que
el mismo endpoint asigna por primera vez y edita. Para sacar a alguien de una
sede **se apaga `habilitado`** — borrar la fila se llevaría de paso su nombre
público y su perfil allí, que el negocio escribió a mano y querrá recuperar si
vuelve.

**El `PUT` solo toca lo que llega**, y aquí importa más que en ningún otro
sitio: el interruptor guarda al momento y manda una petición con ese campo casi
solo. Si el resto se interpretara como vacío, encender a alguien le borraría lo
que tenía escrito. Podéis mandar solo `{"habilitado": true}` sin miedo.

**El `id` es el del profesional**, no el de la fila pivote (§1.4). El mismo que
usan `/profesionales/{id}`, las citas y `servicio_profesional`. Tener dos ids
para la misma persona según la pantalla es una fuente de errores silenciosos.

⚠️ **Divergencia**: el listado filtra también por `activo`, no solo por
`atiende` como decía la ficha. Una tabla de «quién atiende en esta sede» no
debería ofrecer a alguien dado de baja.

⚠️ **Y una que ya estaba en la ficha, por si se pierde**: el horario de esta
fila **NO controla la disponibilidad**. El motor de reservas usa el horario del
profesional, no el de su fila en el local. Esto es informativo para la página
pública, y el modal debería seguir diciéndolo — si no, alguien lo configurará
creyendo que abre o cierra huecos.

### 3. `/api/grupos`

CRUD con `sync()` en las tres listas (`locales`, `profesionales`, `servicios`).
Un **array vacío desasigna** todo lo de esa categoría; una **clave ausente no
toca nada**. `descripcion` no se emite aunque la columna exista (§2.12).

La validación de pertenencia al negocio sale gratis: cada tenant tiene su base,
así que un `exists` normal ya no puede alcanzar filas de otro. Donde el Laravel
viejo necesitaba `exists:locales,id,negocio_id,{id}`, aquí basta `exists`.

**Sigue en pie la duda de la ficha**: hoy los grupos no los consulta nadie — ni
las citas, ni el calendario, ni la tienda pública. Es un CRUD que no alimenta
nada. Está construido porque la pantalla y el esquema existen, pero antes de
darles más peso conviene decidir para qué sirven: ¿filtrar la tienda? ¿agrupar
el calendario? ¿permisos por grupo? Si no hay un uso claro, es la primera
candidata a quitar.

### 4. El alcance por sedes (esquema, sin endpoints todavía)

Tabla `local_usuario` más `usuarios.todos_los_locales`. **El alcance es de la
persona, no del rol**: dos recepcionistas con el mismo rol trabajan en sedes
distintas, y ponerlo en el rol obligaría a crear «Recepcionista de Piura» y
«Recepcionista de Castilla». Es como lo describe AgendaPro: *«se LE puede
asignar permisos sobre uno o varios locales»*.

El booleano no sobra: sin él, «sin sedes asignadas» significaría a la vez
«todas» y «nadie lo ha configurado todavía», y una cuenta nueva tendría una
puerta abierta por omisión sin que se sepa si fue decisión u olvido.

Con esto quedan tres ejes ortogonales: `roles.permisos` dice **qué**,
`roles.solo_propios` dice **sobre quién**, y esto dice **dónde**.

~~**NO lo ofrezcáis todavía en el panel.**~~ **Superado el mismo día**: el
middleware de capacidades ya existe y `GET /locales` filtra por alcance. Lo que
se guarda, se cumple — el selector de sedes se puede ofrecer. Ver el apartado
de arriba.

- **Estado**: hecho (2026-09-05). Suite: 221 tests, 1014 aserciones.

---

## [Sprint 2] Los roles de sistema se renombran: `admin_general` y `admin_local`

`dueno` → **`admin_general`**, `admin` → **`admin_local`**. Cambian la clave y
el nombre visible, en las dos bases.

### Por qué

«Administrador general» y «Administrador» se diferenciaban en **una fila útil**
—facturación— más dos cosméticas. Eso no es un rol distinto: es el mismo con un
permiso menos. Lo que de verdad los separa, y es como lo tiene AgendaPro, es el
**alcance**: uno manda en la empresa, el otro en su sede.

Al cambiar el concepto, la clave vieja dejó de describirlo. Y `dueno` tenía otro
problema: quien registra la cuenta no siempre es el propietario —en una clínica
o un salón con socios suele ser la administradora— así que decirle «Dueño» en su
panel era, además, falso.

Esta vez **sí se renombra la clave**, al revés de lo que hicimos con el nombre
visible hace unos días. La diferencia: entonces cambiaba la etiqueta y la clave
existe justo para sobrevivir a eso; ahora cambió el concepto.

### Lo que cambia de cara al cliente

- **`usuario.rol`** (en `/login` y `GET /user`) emite ahora
  `admin_general | admin_local | profesional`. **Hay que actualizar el union del
  tipo `Usuario`** en el contrato.
- **`roles[].clave`** emite lo mismo. Si el panel decide algo mirando la clave
  —esconder Facturación, deshabilitar el borrado— hay que cambiar esas
  comparaciones.
- Los **nombres visibles** pasan a «Administrador general» y «Administrador
  local», salvo en los negocios que los hayan renombrado: a esos no se les
  toca (`editado_at`).
- Los **textos de los 422** dejan de decir «dueño»: «Ya hay un administrador
  general en este negocio», «Al administrador general no se le puede quitar el
  acceso», «El administrador general no puede cambiar de rol».

### El administrador local pierde Configuración

Su preset pasa a tener `configuracion: null`. Ahí viven el nombre del negocio,
el slug, la marca y el horario base — cosas de la empresa, no de un local. Es lo
mismo que dice la guía de AgendaPro: *«NO puede acceder a la tuerca/engranaje de
configuraciones ni agregar usuarios»*. Cuentas y roles ya eran del general.

**El hueco que esto podría dejar tiene respuesta**: el socio que necesita todo
menos facturación, en todas las sedes, no se queda fuera — el administrador
general se crea un rol propio con los catorce módulos menos ese. En AgendaPro
hay que pedirle un segundo Administrador general a soporte; aquí no.

### Cómo entra en lo que ya existe

Dos migraciones que van **hacia adelante**, sin tocar las que ya corrieron: una
central que amplía el ENUM de `users.rol`, traduce las filas y lo recorta; y una
de tenant que renombra las claves de `roles`.

Lo que garantiza el test: las claves cambian **sobre las mismas filas**, así que
las cuentas —que apuntan a `roles.id`, no a la clave— conservan su rol; y el
`nombre` visible solo se actualiza en los roles que el negocio no personalizó.

- **Estado**: hecho (2026-09-04). Suite: 189 tests, 881 aserciones.

---

## [Sprint 2] Usuarios y Profesionales se separan: `/empleados` deja de existir

Es el cambio más grande hecho hasta ahora y **rehace el módulo 2.A**. Llega
ahora porque el frontend todavía no lo había conectado y no hay ni un tenant
real; después del Sprint 3 (que monta `local_profesional` encima) y del 4 (que
monta las citas y la disponibilidad) habría costado varias veces más.

### Por qué

Nuestro modelo obligaba a que **todo el staff** tuviera fila en
`profesionales`. El síntoma: para dar de alta a una **recepcionista** había que
declarar cómo se le paga —`tipo_pago` era obligatorio y `comision_pct` traía un
50 por defecto—, o sea comisión sobre servicios que no presta. Y al revés, no
se podía dar de alta a un **barbero sin cuenta**: el email era obligatorio, así
que una barbería con cinco barberos que no tocan el sistema tenía que
inventarles cinco correos. Un correo inventado es peor que ninguno: parece un
canal y no lo es.

AgendaPro lo tiene separado y su guía lo dice sin ambigüedad — el formulario de
profesional no pide correo, y «crear un usuario a este profesional» es una
**casilla opcional**.

### El modelo nuevo

| Persona | `users` (central) | `usuarios` (tenant) | `profesionales` (tenant) |
|---|---|---|---|
| Recepcionista | ✓ | ✓ con su rol | — |
| Barbero que no entra al sistema | — | — | ✓ |
| Barbero que sí entra | ✓ | ✓ | ✓ con `usuario_id` |
| Dueño que además atiende | ✓ | ✓ | ✓ |
| Dueño puramente administrativo | ✓ | ✓ | — |

`profesionales.usuario_id` es nullable y `nullOnDelete`: quitarle el acceso a
alguien **no** se lleva su ficha, sus citas ni sus comisiones.

### Los endpoints

**`/api/empleados` ya no existe.** En su lugar:

- **`/api/usuarios`** — quién entra al panel. CRUD + `POST
  /usuarios/{id}/invitacion` para reenviar. Solo el dueño (403 al resto), como
  los roles y por el mismo motivo: quien crea cuentas y reparte roles puede
  fabricarse un segundo dueño.
- **`/api/profesionales`** — quién presta los servicios. CRUD +
  `/profesionales/resumen`. **No** exige ser dueño: dar de alta a un barbero no
  reparte poder sobre el sistema.
- **`POST /api/invitacion/aceptar`** — público, para que el invitado elija su
  contraseña.

### Lo que cambia en los payloads

**Profesional**: `nombre`, `cargo`, `telefono`, `foto`, `tipo_pago` y sus
campos, `horario`, `excepciones`, `atiende`, `activo`. **Ni email, ni rol, ni
contraseña.**

Más el atajo opcional, que es la casilla «darle acceso al panel»:

```json
{ "usuario": { "email": "carmen@elrosal.pe", "rol_id": 3 } }
```

Si viene, se crea la cuenta y se le manda la invitación. Funciona igual al
crear que al editar — un barbero que lleva meses sin cuenta y un día la
necesita. A quien **ya** tiene cuenta no se le toca desde aquí: eso se edita en
`/usuarios`.

**Cuenta**: `nombre`, `apellido`, `email`, `telefono`, `rol_id`, `activo`.
**Sin campo de contraseña, ni al crear ni al editar.**

### Nadie escribe la contraseña de nadie

La cuenta nace con una aleatoria que no conoce ni quien la crea, y a la persona
le llega **«te dieron acceso, crea tu contraseña»** con un enlace de **7 días**.
Es como lo hace AgendaPro («el usuario deberá establecer su contraseña») y es
mejor práctica: el jefe no debería conocer la clave de su empleado. De paso, un
campo menos en el formulario.

Al aceptar la invitación la cuenta queda **verificada**: llegar hasta ahí exige
haber abierto un enlace enviado a ese correo, que es justo lo que la
verificación demuestra.

**Hace falta una pantalla nueva** para `/invitacion?token=…&email=…`. Puede ser
la de reset con otro texto, pero el `POST` es a `/api/invitacion/aceptar`, no a
`/api/reset-password`: son brokers distintos con caducidades distintas.

**Y el botón de reenviar importa más de lo que parece**: el alta depende de que
un correo llegue, y con el dominio recién estrenado los correos caen en spam.
Sin ese botón, la única salida sería borrar la cuenta y volverla a crear.

### El cupo del plan, otra vez más simple

**Cuenta filas activas de `profesionales`.** Sin excepciones, sin mirar roles,
sin mirar `atiende`. Quien está en esa tabla presta servicios, y punto.

Una cuenta sin ficha de profesional —la recepcionista— **no ocupa plaza**. Es
el modelo de AgendaPro: usuarios del panel ilimitados, profesionales no.

El 422 del tope cae ahora en **`activo`**.

Y `atiende` recupera su único significado: **si aparece en la tienda pública**.
Ya no decide el cupo ni si alguien es staff.

### Dos consecuencias visibles en pantalla

**El dueño puede no ser profesional.** Su ficha se crea en el provisioning
**solo si en el registro respondió `independiente`**. Un negocio que dijo «3-5»
empieza con **cero** profesionales, y el checklist de onboarding le pide el
primero — que es justo lo que ese paso siempre quiso decir.

**Dar de baja a un profesional NO le quita la cuenta**, y quitar la cuenta no
borra al profesional. Son dos decisiones distintas y el diálogo debería
decirlo: «esta persona conserva su acceso al panel; para quitárselo, ve a
Usuarios».

### Un bug que conviene tener anotado

El broker de invitaciones escribía el token en la base del **negocio** y no en
la central, porque dentro de `tenancy.init` la conexión por defecto es la del
tenant. Trece tests en rojo por una línea. El reset de contraseña se salvaba
por casualidad —sus rutas son públicas y corren fuera de tenancy—, así que
ahora los dos brokers fijan `connection` explícitamente. Vale como recordatorio
general: **cualquier cosa central invocada desde dentro del panel tiene que
declarar su conexión.**

- **Estado**: hecho (2026-09-04). 40 tests entre los dos módulos; la suite va
  por 185 y 857 aserciones.

---

## [Sprint 2] Empleados: el rol pasa a ser `rol_id`, y el cupo cambia de regla

> ⚠️ **SUPERADO el 2026-09-04** por el apartado de arriba: `/empleados` ya
> no existe y el cupo dejó de mirar `atiende`. Se conserva porque explica
> cómo se llegó hasta aquí, no lo que hay hoy.

Consecuencia directa de los endpoints de roles. **Rompe el formulario de
empleados si no se toca**, y por eso llega ahora, antes de que se conecte.

### El payload

| Antes | Ahora |
|---|---|
| `rol: "admin"` (ENUM central) | `rol_id: 4` (id de la tabla `roles` del negocio) |

`rol_id` es requerido y tiene que existir en `/api/roles`. El enum central
`users.rol` **se deriva** y deja de ser algo que el cliente elige: `admin` si
el rol tiene esa clave, `profesional` en cualquier otro caso — incluidos los
roles propios del negocio, que no tienen equivalente central. En la central el
rol solo sirve para saber quién es el dueño, que es quien maneja facturación;
los permisos del panel viven en la tabla del negocio.

Sin este cambio, los roles que cree el dueño no se le pueden asignar a nadie:
el select sería dinámico de adorno.

### La respuesta

`rol` deja de ser un string y pasa a ser objeto, y se añade `rol_id`:

```json
{
  "rol_id": 4,
  "rol": { "id": 4, "nombre": "Recepcionista", "clave": null }
}
```

`clave` es `null` en los roles propios y `dueno|admin|profesional` en los tres
de sistema — sirve para reconocerlos aunque el negocio los renombre. El objeto
viaja para poder pintar el nombre sin pedir la lista de roles solo para
traducir un id.

### Los 422 cambian de campo

«Ya hay un dueño en este negocio.» y «El dueño del negocio no puede cambiar de
rol.» pasan de `rol` a **`rol_id`**. El del cupo del plan se muda a
**`atiende`** — ver abajo.

### El cupo del plan: cuenta quien está activo Y atiende

Cambia el **eje**, no solo el umbral. Antes contaba por rol (los que llevaban
el de sistema `profesional`); ahora cuenta a quien aparece en la agenda.

Es como lo hace AgendaPro y es lo que tiene sentido: **los usuarios del panel
son ilimitados, los profesionales no**. El coste del producto escala con citas,
no con logins. Una recepcionista entra al panel y no ocupa plaza; un barbero
sí, aunque el negocio le haya inventado el rol.

Tres consecuencias:

1. **El dueño deja de ser excepción.** Si atiende —y atiende por defecto desde
   el provisioning— ocupa su plaza. Un independiente consume 1, la suya. Antes
   consumía 0, lo que hacía que «Básico: 2 profesionales» permitiera en
   realidad tres personas.
2. **El rol dejó de importar para el cupo.** El agujero que abrían los roles
   propios («creo Barbero senior y no cuenta») desaparece solo, sin necesidad
   de contar por rol.
3. **No hay nada que vigilar.** Apagar `atiende` para no pagar quita justo
   aquello por lo que se pagaba: a esa persona deja de podérsele reservar. La
   única vía que quedaba —dar de alta a diez apagados y encenderlos después—
   se cierra validando el cupo también al encender el flag, que es lo que se
   hace.

**Para la pantalla**: el 422 del tope cae en `atiende` y el texto ofrece la
salida — «Alcanzaste el límite de profesionales de tu plan. Puedes darle acceso
al panel sin agenda, o ampliar tu plan.» Conviene que el interruptor de
«atiende» esté a la vista en el formulario, porque es la alternativa gratis a
subir de plan.

**Nota de precio, no de código**: con esta regla no existe plan para un
independiente. Básico empieza en 2 plazas y un barbero solo necesita 1.

- **Estado**: hecho (2026-09-04). 27 tests en Empleados. Cambia también la
  línea correspondiente de `CLAUDE.md`, que decía que el cupo contaba el staff
  con independencia de `atiende`.

---

## [Sprint 2] `negocio.rango_profesionales` sale en el `Usuario`

- **Qué**: `GET /user` y `POST /login` emiten ahora
  `usuario.negocio.rango_profesionales` con lo que el dueño respondió en el
  registro: `independiente`, `2`, `3-5`, `6-15` o `+16`.

- **Para qué**: esconder el grupo **Equipo** (Empleados y Roles) a quien
  trabaja solo. Un independiente que abre Empleados se encuentra una pantalla
  con una sola persona —él mismo— y una matriz de permisos para repartir entre
  nadie. Es lo que hace AgendaPro: si respondes «solo yo», no te ofrece añadir
  profesionales, aunque sí crear usuarios.

- **Es una PISTA, no autorización.** `/empleados` y `/roles` responden igual
  pase lo que pase con este campo, y hay un test que lo fija. Esconder un menú
  no puede cerrar una puerta: si lo hiciera, el día que el negocio contrate a
  alguien habría que migrar algo, y cualquier fallo en la bandera dejaría a
  alguien fuera de sus propios datos.

- **Que sea reversible.** Esa respuesta se da en cinco segundos al registrarse,
  antes de conocer el producto, y el barbero que hoy dice «solo yo» contrata el
  mes que viene. Dos cosas hacen falta y ninguna bloquea hoy:
  1. Un camino visible para activarlo desde el panel («¿vas a trabajar con más
     gente?»). El campo se podrá editar desde **`PUT /configuracion`** (módulo
     2.B); hasta entonces es de solo lectura.
  2. Que el panel lo trate como valor por defecto y no como verdad: si el
     negocio ya tiene más de una persona con agenda —dato que `GET /empleados`
     ya devuelve en `resumen.profesionales_activos`— el grupo se muestra
     aunque el rango diga `independiente`.

- **Nota de precio**: con el cupo contando agendas, un independiente consume 1
  plaza (la suya) y no existe plan de 1: Básico empieza en 2.

- **Estado**: hecho (2026-09-04).

---

## [Sprint 2] Configuración (2.B): el PUT acepta trozos, y otras cuatro cosas

`GET /api/configuracion` y `PUT /api/configuracion` (multipart con
`POST` + `_method=PUT` si van logo o portada). Hechos el 2026-09-04, 18 tests.

### 1. El PUT ya no exige el objeto completo

El contrato dice hoy «el `PUT` envía el objeto completo, no un parche». Con la
pantalla partida en cuatro secciones de Administración eso deja de ser posible,
así que **todas las reglas son `sometimes`**: llega lo que llega y se toca solo
eso. Un cliente que mande los 19 campos sigue funcionando igual — el objeto
completo es un caso particular del parcial.

**Hay que actualizar esa frase del contrato** (se edita desde `Sass-ChiraFlow`).

Lo que garantiza el backend: guardar la sección de Agenda no borra el email ni
la dirección que escribió la de Negocio, y tampoco el `informacion_adicional`,
que vive en el JSON. Tiene test propio, porque es exactamente la trampa que se
llevó por delante el `telefono_normalizado` de Clientes.

Y la distinción que importa al mandar: **clave ausente** significa «no lo
toques»; **clave presente con valor vacío** sí escribe. Así que `false` en
`sitio_publico_activo` se guarda, y `email: ""` vacía el campo de verdad.

### 2. Defaults que salen del GET aunque nadie los haya escrito

Un negocio recién provisionado tiene el JSON vacío. El GET devuelve igualmente:

| Campo | Default |
|---|---|
| `horario_apertura` / `horario_cierre` | `09:00` / `20:00` |
| `agenda.modo_intervalo` | `duracion_servicio` |
| `agenda.intervalo_min` | `15` |

Son defaults de **aplicación**, no de la BD (§1.6). Las columnas sí traen el
suyo de la migración: `zona_horaria` = `America/Lima`,
`sitio_publico_activo` = `true`, `mostrar_en_marketplace` = `false`.

⚠️ **Los colores por defecto no son los de la ficha.** `vistas/configuracion.md`
dice `#7c3aed` y `#0ea5e9`, que eran los del Laravel viejo; las columnas de
`tenants` traen **`#4f46e5`** y **`#06b6d4`**. Manda la migración. Si los de la
ficha son los buenos, se cambia el default de la columna y se avisa.

### 3. `agenda.intervalo_min` solo viaja con la rejilla fija

Con `modo_intervalo: "fijo"` es **obligatorio** (entero, 5–120) → 422 en
`agenda.intervalo_min` si falta. Con `duracion_servicio` el paso lo pone el
servicio, así que se ignora aunque se mande.

Detalle al implementar la sección: **los dos campos de `agenda` viajan juntos**.
Mandar solo `intervalo_min` sin `modo_intervalo` no hace nada, porque la regla
se apoya en el modo para saber si aplica.

### 4. `zona_horaria` se valida como zona IANA de verdad

La ficha la deja como texto libre y su propia lista de pendientes pide un
select. El backend ya no acepta cualquier cosa: **`America/Lima` sí, `Lima` o
`GMT-5` no** → 422. Es la regla 6 del CLAUDE.md — cada timestamp de negocio se
interpreta en esta zona, y un valor inventado hace que todas las horas de la
agenda salgan mal sin que nadie sepa por qué: nadie ve un error, solo citas a
la hora equivocada.

**Y no hay que buscar la lista en ningún sitio: viaja con el GET.**
`zonas_horarias` sale FUERA de `data`, junto al negocio, con las 419 zonas IANA
— mismo criterio que `modulos` en el listado de roles: la pantalla necesita el
valor y las opciones, y un endpoint aparte serían dos peticiones para un campo.
El PUT no la repite, porque quien está guardando ya la tiene.

Sale como array de identificadores, sin etiquetas ni agrupación: los rótulos
los pone el frontend (convención del CLAUDE.md) y el desfase lo calcula el
navegador con `Intl` si lo queréis mostrar.

Lo que garantiza el backend, y tiene test: **el `PUT` acepta TODAS las que el
`GET` ofrece** — salen de la misma fuente que usa el validador. Un select que
proponga algo que luego da 422 sería peor que un campo de texto, porque el
usuario elige de una lista y aun así se le rechaza.

### 5. El resto, sin sorpresas

- **`slug` sale en el GET y el PUT lo ignora**: lo fija el paso 1 del
  onboarding y forma el subdominio; cambiarlo dejaría muerto cada enlace
  repartido. Mandarlo no da 422, simplemente no existe para la validación.
- **`logo` y `cover`**: misma convención que el resto del multipart — no mandar
  el archivo significa «déjalo como está», y quitarlo necesita
  **`logo_eliminar=1`** / **`cover_eliminar=1`**. `logo_url` y `cover_url`
  salen por `/api/archivos/{tenant}/negocio/…`, servibles sin sesión (la
  tienda pública los necesita).
- **`latitud` y `longitud` salen como número**, no como el string que devuelve
  el DECIMAL de MySQL. Fuera de rango → 422 en su campo.
- **Colores**: hex de 6 dígitos obligatorio; la columna es `char(7)` y con
  MySQL estricto cualquier otra cosa sería un 500.
- **Onboarding**: informar `horario_apertura` u `horario_cierre` marca el paso
  `horario_local`. Guardar cualquier otra sección no lo marca.
- **Aislación**: el negocio sale SIEMPRE del token. Aquí no hay `{id}` en la
  ruta ni base separada que haga de red —la fila del vecino está en la misma
  tabla, a un id de distancia—, así que esa es toda la defensa, y tiene test.

- **Estado**: hecho (2026-09-04). Suite: 177 tests, 854 aserciones.

---

## [Sprint 2] Configuración se parte en cuatro secciones de Administración

- **Qué**: `/configuracion` deja de ser un formulario único con cuatro
  pestañas verticales. Cada pestaña pasa a ser una sección propia de la vista
  de Administración, con su URL:

  | Pestaña de hoy | URL nueva |
  |---|---|
  | Negocio | `/administracion/general/negocio` |
  | Agenda | `/administracion/general/agenda` |
  | Marca | `/administracion/general/marca` |
  | Sitio público | `/administracion/general/sitio-publico` |

  Decidido en la sesión de frontend el 2026-09-04. `nav.ts` declaraba dos
  secciones (`negocio` y `horario`) y la pantalla tenía cuatro pestañas: no
  coincidían y había que resolverlo al mudarla. Se resuelve a favor de las
  cuatro porque el shell de Administración ya lleva índice a la izquierda —
  el mismo trabajo que hacían las pestañas verticales, así que anidarlas
  duplicaba la navegación.

  `/configuracion/perfil` (Mi perfil) **no se muda**: es personal, no
  administración del negocio.

- **Consecuencia para el backend (módulo 2.B)**: `PUT /configuracion` recibirá
  **payloads parciales**, uno por sección, en vez del objeto completo que
  describe `vistas/configuracion.md`. El update debe tocar **solo las claves
  presentes** en la petición: un `fill()` a secas haría que guardar el horario
  borrara el email y la dirección.

  Es la misma trampa que el `telefono_normalizado` de Clientes (§ Revisión de
  cierre del Sprint 1). En un PUT parcial, «clave ausente» significa «no lo
  toques», nunca «ponlo a null».

  Ojo al implementarlo: `sitio_publico_activo` y `mostrar_en_marketplace` en
  `false` SÍ viajan y SÍ deben escribirse. La distinción es entre clave
  ausente y clave con valor falso, no entre valor vacío y no vacío.

- **Onboarding**: el paso `horario_local` debe enlazar a
  `/administracion/general/agenda`. Hoy `DESCRIPCION_PASOS`
  (`web/src/features/onboarding/types.ts`) lo manda a `/configuracion`.

- **Ref**: `vistas/configuracion.md` § Qué muestra (las cuatro pestañas y el
  porqué del rediseño del 2026-08-22),
  `web/src/features/administracion/nav.ts`.
- **Estado**: pendiente de implementar (módulo 2.B).

---

## [Sprint 2] Empleados: divergencias al implementar

El backend del 2.A está hecho (24 tests). Seis cosas que conviene fijar en
`vistas/empleados.md` y en el contrato.

1. **El campo `usuario` desaparece de la ENTRADA.** `users.usuario` se eliminó
   (§2.9) y la credencial es el **email**, único global. El payload pide
   `email`; `usuario` no se acepta. De SALIDA se sigue emitiendo `usuario` con
   el email dentro, mientras el tipo `Empleado` del contrato conserve la clave
   (§1.9). **El formulario tiene los dos campos y debe quedarse solo con el
   correo**; el buscador «por usuario» ya busca por email.

2. **`rol` es `admin`, no `administrador`.** Lo dice el contrato y es lo que
   acepta el ENUM de `users.rol`. `web/src/features/empleados/constants.ts`
   usa `administrador` y hay que cambiarlo, o toda alta de admin dará 422.

3. **La contraseña exige 8 caracteres, no 6.** La ficha dice 6; el registro y
   el reset ya piden 8, y rebajar el suelo de una credencial real para cuadrar
   con el formulario sería decidirlo al revés. **Subid la validación del
   formulario a 8**, o el usuario verá un 422 tras pasar vuestra validación.

4. **El adaptador de horario YA NO HACE FALTA.** La ficha preveía traducir
   entre el array de 7 días del frontend y el `{dias:{lunes:…}, inicio, fin}`
   del Laravel viejo. El backend nuevo **guarda directamente la forma del
   contrato** (`dia`, `desde`, `hasta`, `disponible`), así que se manda y se
   recibe lo mismo. Se puede borrar la traducción de
   `features/empleados/services/empleados.api.ts`.

5. **`horario` vuelve SIEMPRE con los 7 días**, rellenando los ausentes con
   `activo: false` y `breaks: []`. La fila del dueño nace con el horario en
   NULL (la crea el provisioning) y el formulario necesita 7 tarjetas que
   pintar.

6. **`telefono` exige `+51` + 9 dígitos**, como el registro y Mi perfil —
   escriben la MISMA columna y el WhatsApp cuenta con ese formato. La ficha
   muestra `987 441 220`: el formulario tiene que normalizar antes de enviar.

**Respuestas a los pendientes de la ficha:**

- *¿Se valida que los breaks caigan dentro de la jornada?* **Sí**, 422.
- *¿Se pueden solapar dos breaks del mismo día?* **No**, 422. Tocarse en el
  extremo (13:00-14:00 y 14:00-15:00) sí vale. De este JSON sale la
  disponibilidad del Sprint 4: un break imposible produce huecos imposibles.
- *¿Qué pasa al eliminar un empleado con citas?* **204 y soft delete** en las
  dos bases, más revocación de sus tokens. Las citas lo referencian y borrarlo
  de verdad reescribiría el historial.
- *¿Existe «Sueldo fijo»?* Sí: `tipo_pago` es `comision|sueldo|ambos`, y los
  períodos son `semanal|quincenal|mensual`. Al cambiar a un tipo sin sueldo,
  `monto_sueldo` y `periodo_pago` **se limpian**.
- *¿El empleado se asigna a un local?* No en v1 — se decide en el Sprint 3.

**Añadido que el contrato no tiene**: el Resource emite **`atiende`** (bool).
Decide si la persona sale en la agenda y en la tienda pública; NO decide el
cupo del plan (§1.9). El payload ya lo acepta, para que el interruptor se
pueda añadir sin tocar backend.

**Barandillas nuevas, todas 422**: al dueño no se le cambia el rol ni se le da
de baja; nadie se asciende a `dueno`; nadie se borra a sí mismo; y el cupo se
comprueba también al **reactivar** (si solo mirase el alta, bastaría dar de
baja a uno, crear a otro y reactivar al primero para saltarse el plan).

**El listado adjunta `resumen`** (`{profesionales_activos, limite_profesionales}`)
junto a `data` y `meta`, como ofrecía la ficha. `GET /empleados/resumen` se
mantiene: el formulario lo consulta sin recargar la tabla.

- **Ref**: `vistas/empleados.md`, discrepancias §1.9, §2.3, §1.5.
- **Estado**: pendiente.

---

## [Sprint 1] Categorías de servicios: divergencias al implementar

- **Qué**: implementado el CRUD de `/categorias-servicios` tal como lo define
  la ficha y el contrato. Tres cosas conviene fijarlas en `vistas/categorias.md`:

  1. **`DELETE` responde `204`, sin cuerpo** (contrato § CRUD estándar). La
     ficha decía que el diálogo avisa cuántos servicios tiene asociados: ese
     aviso lo pinta el frontend con el `servicios_count` que ya trae del
     listado, no una respuesta del backend. Y el texto correcto es **«N
     servicios quedarán sin categoría»**, no «se eliminarán N servicios»: la
     FK es `nullOnDelete`, los servicios sobreviven.
  2. **La columna `activo` existe en la migración** aunque la ficha diga que
     «no existe». Se queda (quitarla obligaría a re-migrar cada tenant por
     nada), pero el Resource no la emite y el Form Request no la acepta: la
     categoría no se puede desactivar, como dice la ficha.
  3. **`imagen` de entrada, `imagen_url` de salida.** El formulario sube el
     archivo en el campo `imagen`; la API devuelve `imagen_url`. La columna
     guarda la RUTA (`categorias/uuid.webp`), no la URL — el día que las
     imágenes se muevan a S3 cambia una línea de configuración en vez de cada
     fila de cada tenant.

- **Pendientes de la ficha, resueltos así**:
  - `orden` se edita solo con el número; arrastrar filas queda fuera de v1.
  - **Falta un botón de «quitar color»** en el formulario: `input[type=color]`
    no puede mandar vacío, así que hoy el color nunca podría volver a ser
    `null` una vez puesto. Es trabajo de frontend; el backend ya acepta
    `color: null`.

- **Nota de seguridad**: las imágenes del catálogo van a **disco público**, no
  al privado con URL firmada de la regla 7 del CLAUDE.md. Esa regla es para
  los comprobantes de Yape; una foto de categoría acaba en la tienda pública,
  donde el visitante no tiene sesión. El resto de la regla sí se mantiene:
  re-encode con GD (lo guardado es una imagen de verdad, y se van los EXIF con
  el GPS de la foto de móvil), nombre UUID y límite de 2 MB.

- **Ref**: `vistas/categorias.md`, contrato § CRUD estándar.
- **Estado**: pendiente.

---

## [Sprint 1] Servicios: respuestas a los pendientes de la ficha

La ficha `vistas/servicios.md` cerraba con seis preguntas al backend. Las
respondo aquí; conviene volcarlas a la ficha en el ritual de cierre.

1. **«¿Qué responde el backend al eliminar un servicio con citas?»**
   **204, siempre.** Es soft delete. La ficha esperaba un 409, pero bloquear
   deja al dueño con un catálogo que no puede limpiar: quien deja de ofrecer
   un corte tiene derecho a quitarlo de la lista. El historial queda intacto
   porque la fila no desaparece, y `cita_servicio` ya congela `precio` y
   `duracion_min` al reservar, así que ninguna cita vieja cambia de importe.

2. **«¿Cómo quieres manejar el borrado de imágenes al editar?»**
   La ficha proponía `galeria_conservar[]` con **URLs** y ofrecía cambiarlo.
   **Cambiado a IDs.** El `galeria` de la respuesta pasa de `string[]` a
   `{ id, url }[]`, y el formulario devuelve los ids que conserva. Casar por
   URL obliga al backend a revertir URL → ruta, y eso se rompe **en silencio**
   si cambia `APP_URL` o el disco — y lo que se pierde son las fotos del
   negocio. Con ids no hay nada que revertir.
   **No mandar `galeria_conservar` no borra nada**: que el formulario omita el
   campo no puede significar «bórralo todo».

3. **«¿Dónde se cambia `activo`?»** El backend lo **acepta opcional** en
   `POST`/`PUT` y lo emite siempre. Al crear entra `true` por defecto de la
   columna. Falta decidir el control: switch en la fila de la tabla es lo
   natural, dado que el formulario no lo tiene.

4. **`max_sesiones`**: obligatorio cuando `tipo` es `sesiones` o `paquete`,
   ignorado en los demás. Al cambiar el tipo a `normal` el valor **se limpia**,
   no se queda de fantasma. Falta añadir el campo al formulario, visible solo
   cuando el tipo lo pida.

5. **«¿El servicio se asigna también a locales concretos?»** No en v1. El
   esquema no tiene pivote servicio↔local (sí `local_profesional`), así que un
   servicio es del negocio entero. Revisar en el Sprint 3.

6. **«¿Existe la página pública que menciona la ayuda de la galería?»** Sí:
   es la tienda pública del Sprint 5 (`/publico/{slug}`). La columna
   `visible_publico` ya existe y el Resource la emite.

**Nombre repetido de un servicio borrado**: `servicios.nombre` es UNIQUE y el
índice no distingue los borrados. Crear un servicio cuyo nombre ya tuvo otro
eliminado **restaura la fila** en vez de fallar — el dueño leería «ya existe»
mirando una lista donde no está. El `unique` de validación ignora los
borrados; el de un servicio vivo sigue dando 422.

- **Ref**: `vistas/servicios.md` § Pendiente, `ServicioService`.
- **Estado**: pendiente.

---

## [Sprint 1] Clientes: el teléfono como clave natural

- **Qué**: implementado el CRUD de `/clientes`. Una decisión de esquema que
  no estaba en ninguna ficha y hacía falta: **columna
  `clientes.telefono_normalizado`** (UNIQUE, dígitos sin prefijo país).

- **Por qué**: `vistas/clientes.md` dice que `telefono` es **texto libre, sin
  formato impuesto** — y enumera los datos reales de la app: `9768657567`,
  `+51 981 912 809`, `904 169 872`, `999`. Pero `discrepancias.md` (congelado)
  fija que **la clave natural del `firstOrCreate` de la reserva pública es el
  teléfono**.

  Las dos cosas juntas fabrican fichas duplicadas: `904169872` y
  `904 169 872` son la misma persona y crearían dos fichas, cada una con medio
  historial. Y como la reserva pública es concurrente, ni siquiera bastaba con
  comprobarlo en el service: hace falta el UNIQUE para cerrar la carrera.

  Solución: **dos columnas**. `telefono` se guarda tal cual lo escribió el
  usuario (la ficha manda en lo que se muestra) y `telefono_normalizado` es la
  que casa. El normalizado **no sale de la API**.

  La normalización es deliberadamente conservadora: quita todo lo que no sea
  dígito y el `51` del prefijo país. No inventa un `+51` que nadie escribió ni
  recorta números cortos — un `999` de prueba sigue siendo suyo. **Casar de
  más uniría fichas de dos personas distintas, que es peor que dejar dos
  fichas de una.**

- **Consecuencia para el contrato**: `POST /clientes` puede responder
  `422 { errors: { telefono: ["Ya existe un cliente con ese teléfono."] } }`,
  que la ficha no contemplaba. El formulario ya sabe pintarlo en su campo.

- **Divergencias con la ficha**:
  1. La ficha da por inventados `documento`, y no menciona `apellido`,
     `fecha_nacimiento` ni `notas`. **Existen en la migración**, y
     `discrepancias.md` dice que la reserva pública trae `apellido` y
     `documento`. Se aceptan y se emiten; el formulario del panel puede
     seguir pidiendo solo tres campos.
  2. **Buscador y paginación se quedan** (la ficha los marcaba «por
     confirmar»): el index los trae, y `search` cubre nombre, apellido, email
     y teléfono — este último también **por el normalizado**, para que quien
     escriba `904169872` encuentre al que se guardó como `904 169 872`.

- **Respuesta a «¿el alta también ocurre desde el flujo de Citas?»**: sí. La
  reserva pública hace `firstOrCreate` por teléfono (decisión congelada), y el
  panel podrá crear cliente al vuelo al agendar. Ambos caminos comparten la
  misma normalización.

- **Pendiente de la ficha, resuelto**: borrar un cliente es **soft delete**
  (sus citas lo referencian). Y volver a darlo de alta con el mismo teléfono
  **restaura su ficha con su historial**, en vez de dar un 500 contra el
  índice UNIQUE.

- **Ref**: `vistas/clientes.md`, `docs/discrepancias.md` § firstOrCreate,
  migración `tenant/2026_08_27_000002_add_telefono_normalizado_to_clientes`.
- **Estado**: pendiente.

---

## [Sprint 1] Banderas para quitar archivos en multipart

- **Qué**: tres campos nuevos, todos opcionales y booleanos:
  `imagen_eliminar` (categorías), `imagen_principal_eliminar` y
  `galeria_vaciar` (servicios).

- **Por qué**: en multipart, **no mandar un archivo significa «déjalo como
  está»** — es obligatorio que sea así, o cada edición borraría la foto que
  el usuario no volvió a subir. Pero eso deja sin forma de expresar «quítala»:
  la ausencia ya está ocupada.

  Con la galería es aún más agudo, y lo detectó la sesión de frontend: **un
  array vacío no viaja en multipart**. Quitar las cuatro fotos llega al
  backend como campo ausente, o sea «no borres nada». Su apaño era mandar
  `galeria_conservar[0]=0` como marcador, apoyándose en que el
  `auto_increment` empieza en 1 — funcionaba, pero era un acuerdo tácito que
  se rompe el día que alguien siembre datos con id 0.

- **Ref**: `vistas/categorias.md`, `vistas/servicios.md`, traspasos FE → BE
  del 2026-08-27.
- **Estado**: pendiente (anotarlo en el contrato § CRUD estándar, porque
  aplica a todo recurso con `FormData`: empleados y locales tendrán lo mismo).

---

## [Sprint 7] `rango_profesionales` sugiere plan; y falta un escalón para el independiente

### Qué hace hoy el campo del registro

«¿Cuántos profesionales atienden en tu negocio?» (`independiente`, `2`, `3-5`,
`6-15`, `+16`) se guarda en `tenants.rango_profesionales` y **no decide ningún
plan**: el registro asigna a todo el mundo el plan `prueba`.

Para la prueba está bien y debe quedarse así. Son 7 días gratis con funciones
de Premium; condicionar el trial a una respuesta que se da en cinco segundos,
antes de conocer el producto, sería restringir en el peor momento posible.

El campo tiene dos usos y conviene no mezclarlos:

1. **Esconder el grupo Equipo** a quien trabaja solo — ya desbloqueado, el dato
   viaja en `usuario.negocio.rango_profesionales`. Es trabajo del panel.
2. **Sugerir el plan** al terminar la prueba — es esto, y su sitio es la
   pantalla de Mi Plan (Sprint 7).

### El mapeo, para que no se reinvente

| Respondió | Plan sugerido |
|---|---|
| `independiente` | Individual (1) |
| `2` | Básico (2) |
| `3-5` | Premium (5) |
| `6-15` | Pro (15) |
| `+16` | Pro + plazas extra |

**Dos condiciones, y son las que hacen que esto funcione:**

**Sugerir, nunca filtrar.** No se esconden planes por esa respuesta. Quien dijo
«3-5» puede haber contratado a dos, o querer empezar barato y subir. Marcar uno
como recomendado sí; quitar los otros de la vista, no.

**Manda el uso real, no lo declarado.** Cuando el negocio llega a elegir plan ya
sabemos algo mejor que lo que dijo al registrarse: cuántos profesionales creó.
`GET /empleados/resumen` da `profesionales_activos`; ese número gana, y el
rango declarado es solo el respaldo para quien no dio de alta a nadie.

Eso además adelanta un problema en vez de dejarlo para después: si durante la
prueba creó 4 personas, la pantalla puede marcar Básico como **insuficiente**
(«no te alcanza para tu equipo actual») ANTES de que elija, en lugar de que lo
descubra al quedarse por encima del límite.

### Bajar de plan NO desactiva a nadie

Decisión explícita, porque hoy pasa por omisión y conviene que pase por
decisión: el cupo se comprueba al crear, al reactivar y al encender `atiende`,
así que un negocio que baja de plan **se queda por encima del límite** — su
gente sigue trabajando y simplemente no puede añadir a nadie más hasta volver
por debajo.

Es lo correcto. Desactivar automáticamente le quitaría gente de la agenda y
cancelaría su disponibilidad por un evento de facturación, sin que nadie lo
pida: una acción destructiva y silenciosa. Bloquear es reversible y visible.

Lo que falta no es lógica, es **decirlo en pantalla**: con
`profesionales_activos` y `limite_profesionales` que ya devuelve el resumen, el
panel puede pintar «5 de 2 — estás por encima de tu plan» con un botón de
ampliar.

### Falta un plan para el independiente

Hoy el más barato es Básico, **S/99 por 2 profesionales**. Le preguntamos al
barbero si trabaja solo y luego el plan de entrada es para dos. Y desde que el
cupo cuenta agendas, el dueño ocupa su plaza: un independiente consume 1 y paga
por 2.

Propuesta (**el precio lo decide el dueño del producto**):

| Campo | Valor propuesto |
|---|---|
| `slug` / `nombre` | `individual` / Individual |
| `precio_mensual` | **S/49** |
| `precio_anual` | 490 (misma proporción que los demás: 10 meses por 12) |
| `precio_promo` / `promo_duracion_meses` | 9 / 3, como Básico y Premium |
| `max_profesionales` | 1 |
| `max_sucursales` | 1 |
| `max_whatsapp_mes` | 0 (igual que Básico) |
| `precio_profesional_extra` | **0 — no admite plazas extra** |
| `destacado` | false |
| `features` | las de Básico |

El 49 es la mitad de Básico, número redondo, y deja sitio a la escalera
49 → 99 → 149 → 449.

**Lo importante no es el precio, es la última fila.** Si Individual admitiera
plazas sueltas a S/11 como los demás, `49 + 11 = 60` daría dos profesionales
por menos que los 99 de Básico, con las mismas funciones: nadie compraría
Básico nunca. El escalón de entrada tiene que estar **capado en duro** — crecer
significa subir de plan, y ese es justamente el momento en que el negocio
entiende para qué sirve pagar más.

(Entre los demás no hay fuga: Básico + 3 plazas son S/132 contra los S/149 de
Premium, pero Premium no vende solo plazas — trae sucursales ilimitadas, 100
WhatsApp al mes y sus features. Ahí el complemento hace lo que debe.)

- **Estado**: pendiente. El mapeo y el escalón se implementan en el Sprint 7;
  el seeder del plan se puede sembrar antes, cuando el precio esté decidido.

---

## [Backlog] Importar clientela desde Excel

- **Qué**: subir un `.xlsx`/`.csv` y dar de alta clientes en bloque, con
  previsualización antes de confirmar.

- **Por qué NO es una comodidad**: es **activación**. Un salón que lleva cinco
  años tiene su clientela en un cuaderno o en un Excel, y hasta que esos 200
  nombres no estén dentro, el sistema le sirve a medias. Es de las cosas que
  deciden si renueva al acabar la prueba.

- **Formato: aceptar `.xlsx` de verdad, no solo CSV.** Parece más simple
  pedir CSV y ahorrarse la dependencia, y es un error en este mercado: Excel
  en español exporta CSV con **punto y coma** en vez de coma, y a menudo en
  ANSI en vez de UTF-8. El dueño sube su archivo, no se parsea o entra
  «MARÍA» como «MARÃ­A», y concluye que el sistema está roto. Requiere
  `phpoffice/phpspreadsheet`; se aceptan ambos formatos pero el xlsx es el
  que va a usar la gente.

- **Columnas** (las mismas que el endpoint normal): `nombre` — la única
  obligatoria —, `apellido`, `telefono`, `email`, `documento`,
  `fecha_nacimiento`, `notas`.
  El formato se documenta con una **plantilla descargable**, no explicándolo
  en texto.

- **Qué hacer con los repetidos** (el teléfono es la clave única):

  | Caso | Acción |
  |---|---|
  | Teléfono nuevo | crear |
  | Teléfono ya existente | **rellenar solo los campos vacíos**, nunca pisar los que ya tienen valor |
  | Teléfono de una ficha borrada | restaurar, igual que el alta normal |
  | Sin teléfono | crear, avisando de que no podrá cruzarse después |

  Lo segundo es lo importante: si el dueño reimporta su Excel viejo por error,
  no puede perder las notas y correos que lleva seis meses acumulando en el
  panel.

- **Dos pasos, no uno.** Subir devuelve un resumen — «180 nuevos, 20
  actualizados, 3 filas con problemas: fila 45 sin nombre, fila 92 teléfono
  repetido dentro del propio archivo» — y el dueño confirma. Sin
  previsualización, alguien sube la columna equivocada, mete 200 fichas
  basura, y limpiarlo obliga a entrar a mano en SU base de datos.

- **Dónde**: no dentro de Clientes (cerrado), sino junto al **checklist de
  onboarding** — «importa tu clientela» como paso opcional, que es cuando el
  dueño lo necesita. Escribirlo pensando en que **Servicios y Productos
  querrán lo mismo**, sin construir esas dos.

- **Ref**: `vistas/clientes.md`, `vistas/onboarding.md`.
- **Estado**: pendiente (backlog, sin sprint asignado).

---

## [Sprint 1] Revisión de cierre: lo que cambia de cara al cliente

Repaso del Sprint 1 buscando fallos (2026-09-01). Se corrigieron 13; **cuatro
cambian lo que la API responde** y conviene fijarlos en el contrato.

1. **`color` exige hexadecimal de 6 dígitos.** Categorías y servicios validaban
   `max:20` contra una columna `char(7)`, con la BD en modo estricto: mandar
   `#5D87FF80` (hex con alfa) o `rebeccapurple` devolvía **500**. Ahora es
   `regex:/^#[0-9a-fA-F]{6}$/` → `422 errors.color`, «El color debe ser un
   hexadecimal como #4F46E5». El `input[type=color]` del formulario ya manda
   justo eso, así que no hay nada que tocar salvo pintar el 422 si algún día se
   escribe a mano.

2. **`per_page` tiene techo: 200.** No estaba acotado y `Builder::limit()`
   ignora los negativos en silencio, así que `?per_page=-1` salía **sin LIMIT**
   y volcaba la tabla entera. Ahora se recorta a `[1, 200]`; `meta.per_page`
   dice siempre lo que se aplicó de verdad.

   El techo es 200 y no 100 porque el helper `all()` del frontend
   (`web/src/lib/api/recurso.ts`), el que llena los selects, pide justo
   `per_page: 200`. **Pero `all()` es frágil de raíz**: un negocio con más
   opciones de las que quepan en una página pierde las restantes SIN error, y
   eso ya pasaba antes de este techo. La solución no es subir el número, es un
   autocompletado paginado o un endpoint ligero de solo `id`+`nombre`.

3. **Mensaje nuevo al editar un cliente con el teléfono de una ficha
   eliminada**: `422 errors.telefono`, «Ese teléfono es de un cliente
   eliminado. Para recuperarlo, créalo de nuevo con ese número.» Antes era un
   500 contra el índice UNIQUE. El de siempre («Ya existe un cliente con ese
   teléfono.») se mantiene para las fichas vivas. Mismo caso en servicios:
   renombrar uno con el nombre de otro borrado da 422, no 500.

4. **El tope de la galería es TOTAL, no por petición.** El `max:4` solo contaba
   los archivos de la llamada: reenviar los 4 ids en `galeria_conservar` y
   subir 4 más dejaba 8 fotos. Ahora se valida lo que va a QUEDAR y responde
   `422 errors.galeria` con el mismo texto de siempre.

**Nota de comportamiento, sin cambio de forma**: recrear un servicio borrado
con el mismo nombre sigue restaurando la fila, pero ahora **nace limpio** —
sin la galería del anterior, activo y visible. Para el dueño eso es un alta,
no una restauración: rellenó un formulario en blanco.

- **Ref**: `ServicioRequest`, `CategoriaServicioRequest`, `ClienteRequest`,
  `Controller::porPagina`, `ServicioService`.
- **Estado**: pendiente.

---

## [Operación] Migrar tenants al desplegar: NO usar `tenants:migrate` a secas

- **Qué**: usar `php artisan tenants:migrar-provisionados` en cada despliegue
  que traiga una migración de tenant. **No** `tenants:migrate`.

- **Por qué**: `tenants:migrate` de stancl recorre la tabla `tenants` entera
  y aborta con `TenantDatabaseDoesNotExistException` en el primer negocio que
  se registró y nunca verificó su correo — existe en la central, pero su base
  no, porque el provisioning es perezoso (regla 2 del CLAUDE.md).

  Eso no es un caso raro: **siempre habrá gente a medio registrar**. Y lo peor
  no es que falle, es que aborta la tanda: los tenants que venían *después*
  del primero sin base se quedan sin migrar, y nadie se entera hasta que a uno
  de ellos le revienta una consulta en producción.

  Salió al probar Clientes: el tenant de desarrollo daba
  `Unknown column 'telefono_normalizado'` porque la migración nueva no le
  había llegado.

- **El comando nuevo** salta los no provisionados y migra **de uno en uno**,
  para que el fallo de un negocio no deje sin migrar a los demás.

- **Ref**: `app/Console/Commands/MigrarTenantsProvisionados.php`,
  `plan-sprints.md` §4 (ritual de cierre de sprint).
- **Estado**: hecho (2026-08-28).

---

## [Configuración] `PUT /configuracion` con `nombre` ahora fija el slug y marca el paso 1

- **Qué cambia**: enviar `nombre` en `PUT /configuracion` cuando el negocio
  **aún no tiene slug** hace dos cosas más que antes:
  1. deriva `slug` y lo devuelve ya poblado en la respuesta (`data.slug`);
  2. marca el paso `nombre_negocio` del onboarding, así que el checklist de
     `GET /onboarding` cambia en la misma petición.

  Renombrar después **no** mueve el slug: cambia solo `data.nombre`. El slug
  se fija una vez y se queda.

- **Por qué**: hay DOS caminos que escriben el nombre del negocio —
  `POST /onboarding/nombre` y esta pantalla— y solo el primero derivaba el
  slug. Un negocio que se ponía el nombre desde Configuración quedaba con
  nombre y **sin enlace**: su tienda pública respondía 404 para siempre y el
  checklist seguía pidiendo el paso 1 sin decir por qué. Le pasó al tenant de
  desarrollo (`3brlcaps`), que estuvo con `nombre` puesto y `slug NULL`.

  La regla de negocio no cambia, se completa: **la primera vez que hay
  nombre, venga de donde venga, nace el slug**. Es la misma razón por la que
  la seguridad vive en el service — un invariante en un solo camino es un
  invariante a medias.

- **Para el frontend**: si la pantalla de Configuración deja poner el nombre
  antes de que el checklist esté completo, refrescar el onboarding tras
  guardar; el paso 1 puede haberse marcado solo. No hace falta llamar a
  `POST /onboarding/nombre` desde ahí.

- **Ref**: `ConfiguracionService::fijarSlugSiHaceFalta()`,
  `OnboardingService::derivarSlug()` (ahora público).
- **Estado**: hecho (2026-09-06).
