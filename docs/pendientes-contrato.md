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

- **Ref**: `vistas/empleados.md` § `rol` — valores (deja abierto «¿qué
  permisos tiene cada uno?»), `app/Support/RolesSistema.php` (matriz de
  presets), migración `tenant/2026_08_27_000001_create_roles_table.php`.
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

2. **`per_page` tiene techo: 100.** No estaba acotado y `Builder::limit()`
   ignora los negativos en silencio, así que `?per_page=-1` salía **sin LIMIT**
   y volcaba la tabla entera. Ahora se recorta a `[1, 100]`; `meta.per_page`
   dice siempre lo que se aplicó de verdad.

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
