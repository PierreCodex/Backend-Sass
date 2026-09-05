<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Quien entra al panel de ESTE negocio.
 *
 * Es la mitad tenant de una cuenta: la credencial —email, contraseña,
 * teléfono, documento— vive en `users` de la BD central, y aquí solo cuelga el
 * rol del negocio, que es la única pieza que necesita una foreign key de
 * verdad contra `roles`.
 *
 * No confundir con `Profesional`, que es quien presta los servicios. Una
 * recepcionista es un usuario y no es profesional; un barbero que nunca entra
 * al sistema es profesional y no es usuario. Quien es ambas cosas tiene las dos
 * filas, unidas por `profesionales.usuario_id`.
 */
class Usuario extends Model
{
    protected $table = 'usuarios';

    protected $fillable = ['central_user_id', 'rol_id', 'todos_los_locales'];

    protected $casts = ['todos_los_locales' => 'boolean'];

    /**
     * La cuenta central de esta persona.
     *
     * NO es una relación de Eloquent: `users` vive en otra base y MySQL no hace
     * JOIN entre bases. La rellena a mano el service con UNA consulta por
     * página, nunca una por fila.
     */
    public ?User $central = null;

    public function rol(): BelongsTo
    {
        return $this->belongsTo(Rol::class);
    }

    /**
     * Las sedes que ve esta cuenta. Vacia + `todos_los_locales` en true = todas.
     *
     * El alcance es de la PERSONA y no del rol: dos recepcionistas con el mismo
     * rol trabajan en sedes distintas, y ponerlo en el rol obligaria a crear un
     * rol por sucursal.
     */
    public function locales(): BelongsToMany
    {
        return $this->belongsToMany(Local::class, 'local_usuario')->withTimestamps();
    }

    /** Su ficha de profesional, si además presta servicios. */
    public function profesional(): HasOne
    {
        return $this->hasOne(Profesional::class);
    }
}
