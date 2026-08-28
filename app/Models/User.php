<?php

declare(strict_types=1);

namespace App\Models;

use App\Notifications\RestablecerPasswordNotification;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * Identidad central de quien hace login (dueño/admin/profesional de UN tenant).
 * El email es único GLOBAL — no existe columna `usuario` (discrepancias §2.9/§2.10).
 * El perfil laboral vive en `profesionales`, dentro de la BD del tenant.
 * Los clientes finales NO tienen fila aquí: no tienen cuenta.
 */
class User extends Authenticatable
{
    use CentralConnection, HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    public const ROLES = ['dueno', 'admin', 'profesional'];

    protected $fillable = [
        'tenant_id',
        'nombre',
        'apellido',
        'email',
        'password',
        'rol',
        'foto',
        'telefono',
        'documento',
        'activo',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'activo' => 'boolean',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Usa la versión encolada de la notificación de Laravel: el correo sale
     * por el worker, no dentro de la respuesta de /forgot-password.
     */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new RestablecerPasswordNotification($token));
    }
}
