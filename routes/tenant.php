<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Tenant Routes
|--------------------------------------------------------------------------
|
| Vacío a propósito. Esta API no identifica tenants por dominio:
|
|  - Panel del negocio: rutas en api.php — el BFF manda el header X-Tenant
|    como PISTA de enrutamiento y SIEMPRE se valida contra el tenant del
|    dueño del token (Sprint 0.B).
|  - Tienda pública: /publico/{slug} resuelve el tenant por su columna
|    `slug` — no por esta ruta ni por `domains` (Sprint 5).
|
*/
