# Backend-Sass

API del SaaS de gestión de citas para negocios que venden tiempo de personas
(barberías, salones, clínicas). Mercado peruano: soles, DNI, WhatsApp,
Yape/Plin.

- **Stack**: Laravel 12 (API pura) · stancl/tenancy v3 multi-base
  (una BD por tenant) · Sanctum por tokens Bearer · MySQL 8 · Pest.
- **Frontend**: repo separado (`Sass-ChiraFlow`, Next.js). Consume esta API a través
  de un BFF propio; el contrato vive en `Sass-ChiraFlow/docs/api-contract.md`.
- **Documentos clave**: `CLAUDE.md` (arquitectura y reglas),
  `docs/discrepancias.md` (decisiones congeladas — prevalecen sobre los SQL
  de referencia), `docs/pendientes-contrato.md` (lo que el backend le pide
  al contrato).

## Desarrollo

```bash
composer install
cp .env.example .env   # BD saas_central en MySQL local
php artisan migrate --seed
php artisan serve      # http://localhost:8000
```

Tests (usan la BD `saas_central_testing`, se crea una vez):

```bash
vendor/bin/pest
```
