# AGENTS.md — Backend-Sass (Laravel 12, API pura)

Instrucciones para cualquier agente (Claude Code, Codex CLI) que trabaje en
**este** repositorio. Son las mismas reglas que `CLAUDE.md`, en la forma que
Codex lee. Si las dos difieren, manda `CLAUDE.md` y hay que corregir este
archivo.

## Qué es esto

SaaS de gestión de citas para negocios que venden tiempo de personas
(barberías, salones, clínicas) en el mercado peruano. Este repo es el
**backend: Laravel 12 como API pura**. El frontend (Next.js 16 + BFF) vive en
`D:/PERSONAL_JEAN/Sass-ChiraFlow` y consume esta API.

## Dónde está la planificación (solo lectura desde aquí)

Toda la planificación vive en el repo del frontend y se lee **por ruta**:

| Qué | Ruta |
|---|---|
| Historias y épicas | `D:/PERSONAL_JEAN/Sass-ChiraFlow/_bmad-output/planning-artifacts/epics.md` |
| Estado del sprint | `D:/PERSONAL_JEAN/Sass-ChiraFlow/_bmad-output/implementation-artifacts/sprint-status.yaml` |
| PRD (FR-1..FR-84) | `.../planning-artifacts/prds/prd-ChiraFlow-2026-09-19/prd.md` |
| Arquitectura (AD-1..AD-22) | `.../planning-artifacts/architecture/architecture-ChiraFlow-2026-09-19/ARCHITECTURE-SPINE.md` |
| Decisiones y matriz de avisos | `.../planning-artifacts/propuesta-decisiones-2026-09-19.md` |
| **Contrato de la API** | `D:/PERSONAL_JEAN/Sass-ChiraFlow/docs/api-contract.md` |
| Fichas por pantalla | `D:/PERSONAL_JEAN/Sass-ChiraFlow/docs/vistas/` |

`api-contract.md` es el original único: **se lee, nunca se edita desde aquí**.
Lo que este repo necesite del contrato se anota en `docs/pendientes-contrato.md`.

`docs/estado.md` y `docs/plan-sprints.md` del frontend están **sustituidos**
por `sprint-status.yaml`; se leen solo como historial.

**Jerarquía ante conflicto:** PRD de BMAD (en lo que sustituye expresamente) →
`docs/discrepancias.md` (congelado) → `api-contract.md` → los SQL de `docs/` →
las fichas de `vistas/`.

## Entorno

- **PHP: el de Laragon, por ruta absoluta.** `php` a secas es otro (8.5) y
  ensucia la salida con deprecaciones:
  `C:/laragon/bin/php/php-8.3.33-nts-Win32-vs16-x64/php.exe`
- MySQL 8.4.11 (Laragon). El `my.ini` está en solo lectura a propósito.
- `uv` necesita los certificados de Windows para bajar dependencias. Está en
  `%APPDATA%\uv\uv.toml` (`system-certs = true`); si falla con `UnknownIssuer`,
  revisar ese archivo.

```bash
PHP="C:/laragon/bin/php/php-8.3.33-nts-Win32-vs16-x64/php.exe"
$PHP artisan test                 # suite completa
$PHP artisan test --filter=Cita   # un grupo
```

## Aislamiento de recursos entre agentes (obligatorio)

Dos agentes corriendo pruebas a la vez se pisan la base de pruebas. Cada
agente usa **su propio prefijo y su propia base central de pruebas**:

| Agente | `DB_DATABASE` | `TENANCY_DB_PREFIX` | `php artisan serve` |
|---|---|---|---|
| Claude | `saas_central_testing` | `tenant_` (por defecto) | 8000 |
| Codex | `saas_central_testing_codex` | `tcodex_` | 8001 |

```bash
# Codex, dentro de su worktree:
DB_DATABASE=saas_central_testing_codex TENANCY_DB_PREFIX=tcodex_ $PHP artisan test
```

`phpunit.xml` no fuerza esas variables, así que el entorno gana. **En
producción y en local el nombre de la base de un tenant no cambia**: sigue
siendo `tenant_{id}`.

Un solo `queue:work` y un solo `schedule:work` en la máquina; si dos agentes
los necesitan, se turnan o cada uno usa su propia base.

## Flujo de trabajo

- **Una historia** (`epics.md`, p. ej. «Story 1.1»): `bmad-build` sobre ella,
  en su rama. **Un arreglo pequeño**: `bmad-build` directo.
- **Antes de mergear:** `bmad-code-review`. **Al cerrar una épica:**
  `bmad-retrospective`.
- **Una rama por historia**, salida de `main`: `sprint-1/1-1-descripcion-corta`.
  Merge con `--no-ff` para que cada historia sea revertible de un tirón.
- **Un responsable por historia.** El reparto está en
  `sprint-status.yaml` → `preparacion.listo_para_desarrollar` (`agente:`).
- **Al terminar**, actualizar el estado de esa historia en `sprint-status.yaml`
  y nada más: no se toca el estado de historias ajenas.

## Reglas que no se negocian

1. **`git` solo en este repositorio.** Nunca crear ramas ni commitear en
   `Sass-ChiraFlow`: el 2026-08-22 las dos sesiones escribieron sobre el mismo
   repo y no se perdió trabajo de milagro.
2. **Las reglas de seguridad y de negocio viven en el SERVICE**, nunca en el
   controlador, y **una sola vez**. Pasó tres veces en una semana: una regla
   escrita dos veces diverge, y la copia que se olvida es la que no se prueba.
   El rango de quién puede conceder qué vive en `App\Support\Rango`.
3. **Permisos con el middleware `puede:modulo,nivel`**, preguntando por
   CAPACIDAD y nunca por rol. Falla cerrado; 403 con `codigo: sin_permiso`; la
   pared de cobro (`suscripcion_vencida`) gana cuando concurren.
4. **Aislamiento entre negocios**: lo ajeno responde **404**, no 403. Cada
   endpoint de recursos lleva su prueba.
5. **Anti-solape de citas**: transacción + `SELECT ... FOR UPDATE` sobre las
   citas del profesional en la ventana, validar, recién insertar.
6. **Las migraciones solo se añaden.** Nunca editar ni renombrar una que ya
   corrió. Las de tenant se despliegan con `tenants:migrar-provisionados`,
   nunca con `tenants:migrate` a secas.
7. **Contrato primero** (AD-13): si la historia cambia la API, se acuerda la
   forma en `api-contract.md` **antes** de escribir el endpoint.
8. **Subida de comprobantes**: único endpoint de escritura sin sesión.
   Autorización por posesión del `codigo`, MIME real por firma, re-encode,
   límite de tamaño, nombre UUID, disco privado, URL firmada y rate limit.

## Archivos compartidos: coordinación

`app/Services/CitaService.php` y `app/Services/Disponibilidad.php` los tocan
varias historias. Reglas:

- **Una sola implementación de cada regla compartida.** Si hace falta
  ampliarla, se amplía el método existente; no se escribe otro.
- **Un agente por archivo a la vez.** Dos historias que tocan `CitaService` no
  se reparten en paralelo: se ordenan.
- **La historia dueña se mergea primero** (1.3 antes que 2.2; 2.2 antes que
  cualquiera de las épicas 6 y 7), y la siguiente **sale de una base que ya
  incluye ese cambio**.
- Toda historia que los toque pasa por `bmad-code-review` antes del merge.

## Convenciones

- PHP 8.3+, PSR-12, Pest para tests.
- Form Requests para validación, Services para lógica, API Resources para salida.
- Respuestas: `{ data: ... }` individual, `{ data: [...], meta: {...} }`
  paginado. **Paginar siempre los index.** Búsqueda con `search` (nunca
  `buscar`). 422 con `errors` por campo.
- Todo el texto y todo el error, **en español**.
- Toda hora de negocio se interpreta en `tenants.zona_horaria`; lo programado
  se guarda en UTC.
- Los colores y las etiquetas los pone el frontend.

## Trampas conocidas

- La BD de tenant **no tiene `negocio_id`** en ninguna tabla: la base ES el negocio.
- `users` vive en la BD **central**; `usuarios` y `profesionales`, en la del tenant.
  `usuarios` y `clientes` referencian `central_user_id` **sin FK** (MySQL no
  permite FK entre bases).
- `tenants.id` (inmutable, nombra la base) y `tenants.slug` (público, cambiable)
  son cosas distintas. `domains` apunta al **id**.
- `cita_servicio` es la única fuente de verdad de los servicios de una cita:
  no existe `citas.servicio_id`.
- El stock solo se mueve con movimientos; `productos.stock` es un saldo
  materializado.
