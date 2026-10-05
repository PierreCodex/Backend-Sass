# Setup del entorno de desarrollo — ChiraFlow

Guía para dejar una máquina lista para trabajar en ChiraFlow, backend y
frontend, con BMAD. Está escrita para **Windows**, que es el entorno del
proyecto. Al final hay una lista de comprobación.

ChiraFlow son **dos repos** que se usan juntos:

| Repo | Qué es | Puerto local |
|---|---|---|
| [`PierreCodex/Backend-Sass`](https://github.com/PierreCodex/Backend-Sass) | API en Laravel 12 (multi-tenant, una base por negocio) | 8000 |
| [`PierreCodex/Sass-ChiraFlow`](https://github.com/PierreCodex/Sass-ChiraFlow) | Panel en Next.js 16 (carpeta `web/`) y **toda la planificación de BMAD** (`_bmad-output/`) | 3000 |

---

## 1. Programas que hay que instalar

| Programa | Versión | Para qué | Notas |
|---|---|---|---|
| **Git** | reciente | los dos repos | con Git Bash |
| **Laragon 6** | 6.x | trae **PHP** y **MySQL** | <https://laragon.org> |
| **PHP** | **8.3** (el proyecto usa 8.3.33 NTS) | Laravel | viene con Laragon; ver §2 |
| **MySQL** | **8.4 LTS** | central y una base por negocio | igual que producción; ver §3 |
| **Composer** | 2.x | dependencias de PHP | viene con Laragon |
| **Node.js** | **22.x** (se usa 22.14) | el panel Next.js | |
| **uv** | reciente | lo usa BMAD para generar sus instrucciones | ver §6 |
| **Claude Code** | reciente | el agente con el que se trabaja | |
| **GitHub CLI (`gh`)** | opcional | abrir PR desde la terminal | `gh auth login` |

Extensiones de PHP que hacen falta (Laragon ya las trae activas): `pdo_mysql`,
`mbstring`, `openssl`, `curl`, `fileinfo`, `gd`, `exif`. Conviene activar
también `zip`.

---

## 2. PHP: usar SIEMPRE el de Laragon

Si hay otro PHP en el `PATH` (por ejemplo `C:\php`), `php` a secas puede
resolver a una versión distinta. Con PHP 8.5, Laravel 12 llena las pruebas
de avisos `PDO::MYSQL_ATTR_SSL_CA is deprecated` que tapan los fallos de
verdad.

Comprueba cuál responde:

```bash
php -v          # debe decir 8.3.x
where php       # debe aparecer primero el de C:\laragon\bin\php\...
```

Si no es el de Laragon, llámalo por su ruta completa. Así lo hacen también los
agentes:

```bash
C:/laragon/bin/php/php-8.3.33-nts-Win32-vs16-x64/php.exe artisan test
```

(ajusta la carpeta a la versión que instaló tu Laragon).

---

## 3. MySQL 8.4 en Laragon

En Laragon: menú → MySQL → versión → elige **8.4.x**. Si no aparece, añádela
con *Quick add*.

> ⚠️ **Trampa conocida de Laragon 6 con MySQL 8.4.** En cada arranque, Laragon
> añade `default_authentication_plugin=mysql_native_password` al `my.ini`, y
> MySQL 8.4 **se niega a arrancar** con esa opción. Arreglo:
> 1. Abre el `my.ini` de la 8.4 y quita esa línea. Si la necesitas, pon en su
>    lugar `mysql_native_password=ON`.
> 2. Marca el archivo como **solo lectura** para que Laragon no lo vuelva a
>    tocar: `attrib +R my.ini`. Para editarlo más adelante: `attrib -R my.ini`.
> 3. Si MySQL no arranca, el motivo está en el `mysqld.log` de la carpeta de
>    datos (`C:\laragon\data\mysql-8\`).

El usuario de MySQL tiene que poder **crear bases**: el sistema crea una base
por negocio (`tenant_{id}`) al verificar el correo. En local se usa `root`.

Crea las dos bases centrales:

```sql
CREATE DATABASE saas_central         CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE saas_central_testing CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

---

## 4. Clonar los repos (¡ojo con la ruta!)

**Clona los dos en la misma carpeta padre.** El proyecto tiene **rutas
absolutas** escritas en `CLAUDE.md` y en la configuración de BMAD
(`D:\PERSONAL_JEAN\...`). Lo más simple es replicarla:

```bash
mkdir D:\PERSONAL_JEAN
cd D:\PERSONAL_JEAN
git clone https://github.com/PierreCodex/Backend-Sass.git
git clone https://github.com/PierreCodex/Sass-ChiraFlow.git
```

Si **no puedes** usar `D:\PERSONAL_JEAN\` (no tienes unidad `D:`, por ejemplo),
clona donde quieras y haz dos cosas:

1. Crea `Backend-Sass/_bmad/custom/config.user.toml` (git lo ignora, es solo
   tuyo) con tus rutas:

   ```toml
   [modules.bmm]
   planning_artifacts = "C:/ruta/a/Sass-ChiraFlow/_bmad-output/planning-artifacts"
   implementation_artifacts = "C:/ruta/a/Sass-ChiraFlow/_bmad-output/implementation-artifacts"
   ```

2. Cuando `CLAUDE.md` nombre `D:\PERSONAL_JEAN\Sass-ChiraFlow\...`, léelo como
   tu ruta. Al empezar cada sesión, díselo a Claude: «el repo del frontend está
   en C:\ruta\a\Sass-ChiraFlow».

---

## 5. Levantar el backend (`Backend-Sass`)

```bash
cd D:\PERSONAL_JEAN\Backend-Sass
composer install
copy .env.example .env
php artisan key:generate
```

Edita `.env`. Estos son los valores de desarrollo; **nunca subas tu `.env`**:

```dotenv
APP_URL=http://localhost:8000
APP_LOCALE=es
FRONTEND_URL=http://localhost:3000

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=saas_central
DB_USERNAME=root
DB_PASSWORD=

QUEUE_CONNECTION=database

# Correo: en local "log" deja los enlaces (verificación, invitaciones) en
# storage/logs/laravel.log. Para enviar de verdad hace falta MAIL_MAILER=resend
# y una RESEND_API_KEY (pídesela a Jean; no la compartas ni la subas).
MAIL_MAILER=log
```

Migra y siembra la base central (planes y categorías de negocio):

```bash
php artisan migrate --seed
```

Para que la app funcione hacen falta **tres procesos**, cada uno en su
terminal:

```bash
php artisan serve        # API en http://127.0.0.1:8000
php artisan queue:work   # IMPRESCINDIBLE: el alta de un negocio (crear su base) y los correos van en cola
php artisan schedule:work  # tareas diarias (ciclo de vida de la prueba, métricas)
```

> Sin `queue:work`, al verificar el correo **no se crea la base del negocio** y
> el panel queda vacío. Es lo primero que hay que revisar si «no pasa nada».
> Corre **uno solo** de cada (`queue:work` y `schedule:work`) por máquina.

Las migraciones de los negocios ya creados se aplican con:

```bash
php artisan tenants:migrar-provisionados   # nunca "tenants:migrate" a secas
```

---

## 6. Pruebas del backend (Pest)

Las pruebas usan MySQL de verdad, no SQLite: la base `saas_central_testing`
y bases `tenant_*` que crean y borran ellas mismas.

```bash
php artisan test                                  # suite completa (~15–20 min)
php artisan test --filter="CitasTest|RangoTest"   # solo esas clases
```

> **No las corras por ruta de archivo** (`php artisan test tests/Feature/...`):
> los helpers compartidos (`crearTenantRegistrado()`, etc.) viven en
> `tests/Feature/ProvisioningTest.php` y no se cargan. Usa `--filter`.

Si en la **misma máquina** corren dos agentes a la vez (por ejemplo Claude y
Codex), cada uno usa su propia base de pruebas y su prefijo:

```bash
DB_DATABASE=saas_central_testing_sandro TENANCY_DB_PREFIX=tsandro_ php artisan test
```

En tu máquina, con un solo agente, no hace falta.

---

## 7. Levantar el frontend (`Sass-ChiraFlow/web`)

```bash
cd D:\PERSONAL_JEAN\Sass-ChiraFlow\web
npm ci
copy .env.example .env.local
npm run dev          # http://localhost:3000
```

En `.env.local` lo importante:

- `NEXT_PUBLIC_API_URL=http://127.0.0.1:8000/api`. Pon **`127.0.0.1`, no
  `localhost`**: Node resuelve `localhost` a IPv6 y `artisan serve` solo
  escucha en IPv4, así que el panel respondería 502.
- `NEXT_PUBLIC_USE_MOCKS` y `NEXT_PUBLIC_MODULOS_CONECTADOS`: qué módulos hablan
  con Laravel y cuáles siguen con datos de prueba. Copia los valores de
  `.env.example`.

El navegador nunca habla con Laravel directo. Lo hace a través del BFF de
Next, que guarda el token en una cookie httpOnly.

---

## 8. BMAD (la forma de trabajar)

Toda la planificación vive en `Sass-ChiraFlow/_bmad-output/`:

- `planning-artifacts/prds/prd-ChiraFlow-2026-09-19/prd.md`: **qué** hace el
  producto.
- `planning-artifacts/epics.md`: las épicas y sus historias.
- `implementation-artifacts/sprint-status.yaml`: **qué está hecho y a quién le
  toca cada historia** (campo `agente`).
- `implementation-artifacts/spec-*.md`: la spec de cada historia ya hecha.
  Leer una de ellas es la mejor forma de ver cómo se trabaja.

### 8.1 `uv` con los certificados de Windows

BMAD ejecuta un script de Python con `uv` cada vez que lanzas una de sus
skills. En redes con inspección de certificados falla con `invalid peer
certificate: UnknownIssuer`. Para evitarlo, crea
`%APPDATA%\uv\uv.toml` con:

```toml
system-certs = true
```

Comprueba que `uv --version` responde.

### 8.2 Instalar las skills de BMAD

El proyecto usa **BMAD v6 (6.13.0-next)**, instalado como skills de usuario de
Claude Code en `%USERPROFILE%\.claude\skills\bmad-*` (unas 30 carpetas:
`bmad`, `bmad-build`, `bmad-code-review`, `bmad-spec`…).

Se instalan con el instalador de skills (`npx skills`), desde el repo oficial
<https://github.com/bmad-code-org/BMAD-METHOD>, que es como las instaló Jean:

```bash
npx skills add bmad-code-org/BMAD-METHOD
```

Es interactivo. Elige **todas las skills `bmad-*`**, el agente **Claude Code**
y el alcance **global** (tu usuario, no solo el proyecto).

> ⚠️ **Versión.** Ese comando instala la última versión; Jean tiene la del
> 2026-09-20 (6.13.0-next). Para trabajar con instrucciones idénticas, hay dos
> caminos: que Jean te pase un zip de sus carpetas
> `%USERPROFILE%\.claude\skills\bmad*` y lo descomprimas en el mismo sitio, o
> que los dos actualicéis a la vez con `npx skills update`.

La configuración por proyecto **ya está en el repo** (`_bmad/`, y las reglas
del proyecto en `_bmad/custom/*.toml`). No hay que «instalar BMAD en el
proyecto», solo las skills en tu usuario.

Comprobación: abre Claude Code en `Backend-Sass` y escribe `/bmad`. Debe
responder con el estado del proyecto.

### 8.3 Cómo se trabaja una historia

1. Mira en `sprint-status.yaml` cuáles tienen `agente: sandro`.
2. Crea su rama desde `main`: `git checkout -b sprint-1/1-5-rol-recepcion`.
3. En Claude Code: **`bmad-build`** con la historia (p. ej. «Story 1.5»).
   BMAD investiga, escribe la spec, te hace preguntas si algo no está
   decidido, implementa con pruebas y la revisa.
4. Antes de entregar: **`bmad-code-review`** sobre la rama.
5. Al empezar y al terminar, actualiza tu historia en `sprint-status.yaml`
   (`in-progress` → `review` → `done`).

---

## 9. Git: a `main` solo por Pull Request

- `main` está **protegido** en los dos repos: no se puede hacer push directo
  ni force push.
- Una **rama por historia**, salida de `main` (`sprint-N/N-M-descripcion`).
- Antes de abrir la PR: **la suite completa en verde**, incluidas las pruebas
  de aislación entre negocios, y `bmad-code-review` hecho.
- Abre la PR contra `main` con el enlace a la spec de la historia y el
  resultado de las pruebas. **La aprueba y la mergea Jean** con «Create a
  merge commit».
- `git` en cada repo para lo suyo: los cambios del backend van en
  `Backend-Sass` y los del panel o la planificación en `Sass-ChiraFlow`.

---

## 10. Reglas del proyecto que conviene saber desde el día 1

Están en `Backend-Sass/CLAUDE.md` (léelo entero). Las que más muerden:

- **Las reglas de seguridad viven en el SERVICE**, en un solo sitio, nunca en
  el controlador ni duplicadas.
- **Aislamiento entre negocios:** un usuario del negocio A recibe **404** (no
  403) al pedir algo del negocio B, y cada endpoint lleva su prueba.
- **Migraciones:** nunca se edita ni se renombra una que ya corrió; cada
  cambio es un archivo nuevo.
- **El contrato de la API** (`Sass-ChiraFlow/docs/api-contract.md`) se lee
  antes de diseñar un endpoint y no se edita desde el backend. Lo que haga
  falta se anota en `Backend-Sass/docs/pendientes-contrato.md`.
- **`CitaService` es delicado** (anti-solape, alcance, reservabilidad): avisa
  antes de tocarlo.

---

## ✅ Lista de comprobación

- [ ] `php -v` → 8.3.x (o usas la ruta completa de Laragon)
- [ ] MySQL 8.4 arranca y existen `saas_central` y `saas_central_testing`
- [ ] Los dos repos clonados en la misma carpeta (o `config.user.toml` creado)
- [ ] `php artisan migrate --seed` sin errores
- [ ] `serve`, `queue:work` y `schedule:work` corriendo
- [ ] `php artisan test --filter=RangoTest` en verde
- [ ] `npm run dev` en `web/` y el panel abre en <http://localhost:3000>
- [ ] Te registras y verificas el correo (enlace en `storage/logs/laravel.log`); se crea tu negocio y entras al panel
- [ ] `%APPDATA%\uv\uv.toml` con `system-certs = true`
- [ ] Skills de BMAD instaladas y `/bmad` responde en Claude Code
- [ ] Sabes cuáles son tus historias en `sprint-status.yaml`
