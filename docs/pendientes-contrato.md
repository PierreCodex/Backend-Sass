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
