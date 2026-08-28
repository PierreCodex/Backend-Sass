<?php

declare(strict_types=1);

namespace App\Models;

use Laravel\Sanctum\PersonalAccessToken as SanctumToken;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * Los tokens viven en la BD CENTRAL, siempre.
 *
 * Sin este anclaje, cualquier lectura de token posterior a
 * InicializarTenancy la busca en `tenant_{id}.personal_access_tokens`, que
 * no existe. En una peticion normal no se nota —Sanctum resuelve el token
 * antes de que tenancy arranque— pero basta con volver a tocar la relacion
 * despues para que reviente.
 */
class PersonalAccessToken extends SanctumToken
{
    use CentralConnection;
}
