<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Superadmin y soporte de la plataforma (Épica 8).
 *
 * Vive en la base CENTRAL y no tiene tenant: nunca se mezcla con `users`. Por
 * ahora solo lo usa el acceso con código por correo del prototipo del panel de
 * plataforma; el guard propio y el resto de la Story 8.4 llegan después.
 */
class PlatformAdmin extends Authenticatable
{
    use Notifiable, SoftDeletes;

    protected $table = 'platform_admins';

    protected $fillable = ['nombre', 'email', 'password', 'rol', 'activo'];

    protected $hidden = ['password', 'remember_token', 'two_factor_secret'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'activo' => 'boolean',
        ];
    }
}
