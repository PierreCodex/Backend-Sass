<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use Illuminate\Support\Str;
use Stancl\Tenancy\Contracts\UniqueIdentifierGenerator;

/**
 * Genera el id inmutable del tenant: 8 caracteres [a-z0-9].
 * Nombra la BD (tenant_{id}) y nunca se muestra al usuario.
 * El subdominio público sale de la columna `slug`, no de este id.
 */
class ShortIdGenerator implements UniqueIdentifierGenerator
{
    public static function generate($resource): string
    {
        $model = config('tenancy.tenant_model');

        do {
            $id = strtolower(Str::random(8));
        } while ($model::where('id', $id)->exists());

        return $id;
    }
}
