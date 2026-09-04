# Pruebas de interfaz — guion manual

**Qué es esto**: el recorrido que hace una persona de verdad por lo que ya está
conectado, para verificar lo que los tests automáticos NO alcanzan.

**Qué NO es**: una especificación. Lo que cada pantalla debe mostrar está en
`Sass-ChiraFlow/docs/vistas/`; aquí solo se comprueba que funciona de punta a
punta. Si esto y una ficha se contradicen, manda la ficha.

**Por qué hace falta**: los 136 tests de Pest son contra la API. Verifican que
el backend responde bien, no que se pueda usar. Un 422 correcto que el
formulario no pinta es un test en verde y un usuario atascado.

Cubre lo que está conectado a fecha 2026-09-01: **auth y onboarding**
(Sprint 0) y **categorías, servicios y clientes** (Sprint 1). Empleados queda
fuera: el backend está listo pero el frontend aún no lo consume.

---

## Antes de empezar

| Requisito | Cómo |
|---|---|
| Backend | `php artisan serve` |
| **Worker de cola** | `php artisan queue:work` — **sin esto no sale NI UN correo**: verificación y reset son `ShouldQueue` |
| Frontend | `npm run dev` en `Sass-ChiraFlow/web` |
| Módulos conectados | `NEXT_PUBLIC_MODULOS_CONECTADOS=categorias,servicios,clientes` en `web/.env.local` — lo que no esté ahí usa datos ficticios y no prueba nada |

**Tres trampas que ya nos costaron tiempo:**

- **El correo de verificación llega a Spam.** El dominio es nuevo. Búscalo con
  `in:anywhere from:no-reply@flexishopp.site` antes de dar por rota la cola.
- **La BD central está sin tenants**: hay que registrar uno nuevo. No hay
  cuenta de pruebas que reutilizar.
- **Si añadiste una migración de tenant**, corre
  `php artisan tenants:migrar-provisionados` antes de probar, o verás errores
  de columna inexistente. Nunca `tenants:migrate` a secas.

Anota cada fallo con: qué hiciste, qué esperabas, qué pasó, y si el error se
ve en la pantalla o solo en la consola del navegador.

---

## 1 · Registro y verificación

| # | Paso | Qué debe pasar |
|---|---|---|
| 1.1 | Registrarse con datos válidos | Va a «Revisa tu correo». **No** inicia sesión ni entra al panel |
| 1.2 | Intentar entrar con esa cuenta antes de verificar | Rechazo con mensaje de «verifica tu correo», no un error genérico |
| 1.3 | Pulsar «Reenviar» dos veces seguidas | La segunda muestra cuenta atrás, no un error crudo. El backend responde 429 con `retry_after` y el botón debe usar ESE valor |
| 1.4 | Registrarse con un correo ya usado | Error **bajo el campo email**, no un banner suelto arriba |
| 1.5 | Abrir el enlace del correo | Verifica y lleva al login |
| 1.6 | Abrir el MISMO enlace otra vez | No revienta: mensaje claro de que ya estaba verificado |
| 1.7 | Entrar por primera vez | Panel con el checklist de onboarding. **Puede tardar unos segundos**: la BD del negocio se crea al verificar, en cola |

> **1.7 es la prueba más valiosa de esta sección.** El provisioning es un job:
> si el worker no corre, el usuario entra a un panel sin base de datos. Prueba
> a propósito **con el worker parado** y mira qué ve — debería ser un aviso
> honesto, no una pantalla rota.

## 2 · Login y contraseña

| # | Paso | Qué debe pasar |
|---|---|---|
| 2.1 | Contraseña incorrecta | 422 pintado **bajo el campo email** («Las credenciales no coinciden») |
| 2.2 | «Olvidé mi contraseña» con correo existente | Llega el enlace (revisa spam) y lleva al formulario del frontend, no a una pantalla de Laravel |
| 2.3 | Cambiar la contraseña desde Mi perfil | Tu sesión **sigue viva**; las demás se cierran |
| 2.4 | Repetir la contraseña actual como nueva | 422: tiene que ser distinta |
| 2.5 | Cerrar sesión | Vuelve al login y ya no se puede volver atrás con el botón del navegador |

## 3 · Onboarding - VERIFICAR

| # | Paso | Qué debe pasar |
|---|---|---|
| 3.1 | Fijar el nombre del negocio (paso 1) | Se deriva el slug y el paso queda marcado |
| 3.2 | Intentar cambiar el nombre después | El slug **no cambia**: es inmutable una vez fijado |
| 3.3 | Antes del paso 1, mirar el enlace a la tienda pública | Debe estar deshabilitado — sin slug la tienda responde 404 |
| 3.4 | Saltarse el checklist e ir al panel | Se puede: el onboarding **no bloquea** |

## 4 · Categorías de servicios

| # | Paso | Qué debe pasar |
|---|---|---|
| 4.1 | Crear una con imagen | Aparece en la tabla **con la foto visible**. Si sale rota, mira la URL: debe ser `/api/archivos/{tenant}/categorias/…` |
| 4.2 | Editar sin volver a elegir imagen | La foto **se conserva**. Que no se mande el archivo no puede borrarla |
| 4.3 | Pulsar la X de «quitar color» y guardar | La categoría queda sin color y el listado no pinta círculo |
| 4.4 | Pulsar la X de la imagen y guardar | Queda sin imagen (usa `imagen_eliminar`) |
| 4.5 | Repetir un nombre existente | 422 bajo el campo nombre |
| 4.6 | Borrar una categoría con servicios | El aviso dice «**N servicios quedarán sin categoría**», no «se eliminarán N servicios». Después, esos servicios **siguen existiendo**, sin categoría |
| 4.7 | Subir un archivo que no sea imagen, o de más de 2 MB | 422 legible, no un error de servidor |

## 5 · Servicios

| # | Paso | Qué debe pasar |
|---|---|---|
| 5.1 | Crear con imagen principal y 2 de galería | Las 3 se ven en el detalle |
| 5.2 | Editar quitando una de la galería | Se va solo esa; la principal sigue |
| 5.3 | Quitar **todas** las de la galería | Se vacía de verdad (usa `galeria_vaciar`; un array vacío no viaja en multipart) |
| 5.4 | **Con 4 fotos ya subidas, añadir una quinta** | **422 «La galería admite hasta 4 imágenes»**. ⚠️ **Cambió el 2026-09-01**: antes se guardaban 5. Lo ideal es que el formulario ni deje elegir la quinta |
| 5.5 | Tipo «sesiones» o «paquete» | Aparece `max_sesiones` y es obligatorio |
| 5.6 | Cambiar ese tipo a «normal» | `max_sesiones` **se limpia**, no queda de fantasma |
| 5.7 | Cambiar `activo` con el switch de la fila | Cambia y persiste al recargar |
| 5.8 | Borrar un servicio | Desaparece de la lista. El diálogo **no** debe decir «no se puede deshacer»: es soft delete |
| 5.9 | Crear uno con el nombre de otro borrado | Se crea (restaura la fila). ⚠️ **Cambió**: ahora **nace limpio** — sin la galería del viejo y activo |
| 5.10 | Renombrar un servicio con el nombre de otro **borrado** | 422, no un error 500 |
| 5.11 | Asignar profesionales y guardar | Persisten al reabrir |

## 6 · Clientes

| # | Paso | Qué debe pasar |
|---|---|---|
| 6.1 | Crear con teléfono `904 169 872` | Se guarda **tal cual se escribió**, con espacios |
| 6.2 | Crear otro con `904169872` | 422 bajo el campo teléfono: es la misma persona |
| 6.3 | Buscar `904169872` | Encuentra al que se guardó con espacios |
| 6.4 | Crear dos clientes **sin** teléfono | Conviven: el único no cuenta los nulos |
| 6.5 | **Editar un cliente cambiando solo el nombre** | ⚠️ **Bug corregido el 2026-09-01.** Después, **búscalo por su teléfono**: tiene que seguir apareciendo. Si no aparece, el normalizado se borró |
| 6.6 | Borrar un cliente y volver a crearlo con el mismo teléfono | **Recupera su ficha y su historial de citas** |
| 6.7 | Editar un cliente poniéndole el teléfono de uno **borrado** | 422 con mensaje propio («es de un cliente eliminado»), no un 500 |
| 6.8 | Un correo mal escrito | 422 bajo el campo email |

## 7 · Transversal (en cualquier módulo)

| # | Paso | Qué debe pasar |
|---|---|---|
| 7.1 | Recargar (F5) estando en un formulario abierto | No deja la app en un estado roto |
| 7.2 | Dejar la pestaña abierta y volver al rato | Si el token murió, va al login; no se queda en blanco |
| 7.3 | Mirar la consola del navegador durante todo el recorrido | **Cero errores rojos**. Los avisos de React también cuentan |
| 7.4 | Probar en móvil (o con el navegador estrecho) | Las tablas y los diálogos se pueden usar |
| 7.5 | Navegar solo con teclado por un formulario | Se puede completar y enviar sin ratón |
| 7.6 | Con un módulo cargando, mirar la pantalla | Skeletons o spinner, no un salto brusco |

## 8 · Aislación entre negocios (la que no se puede fallar)

Registra un **segundo** negocio, con otro correo, y verifícalo.

| # | Paso | Qué debe pasar |
|---|---|---|
| 8.1 | Crear una categoría en cada negocio | Cada uno ve **solo la suya** |
| 8.2 | Con la sesión de A, pedir a mano la URL de un recurso de B | **404**, nunca 403 ni los datos |
| 8.3 | Subir una imagen en cada negocio | Cada `imagen_url` lleva su propio id de tenant y no se cruzan |

> Si algo falla aquí, **para y avísame antes de seguir**: una fuga entre
> negocios es lo único de esta lista que no puede esperar al siguiente sprint.

---

## Qué hacer con lo que encuentres

- **Fallo del backend** (422 que no toca, 500, datos mal) → lo arreglo aquí.
- **Fallo del frontend** (error que no se pinta, imagen rota, validación que
  no salta) → va a la sesión de `Sass-ChiraFlow`.
- **Si no sabes de quién es**: mira la pestaña Red del navegador. Si la
  respuesta trae el dato correcto y la pantalla no lo muestra, es del
  frontend.

Lo que sobreviva a este guion y sea recurrente merece un test automático: un
paso manual que se repite cada sprint acaba saltándose el día que hay prisa.
