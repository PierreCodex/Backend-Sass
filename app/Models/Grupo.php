<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Agrupa locales, profesionales y servicios. «Odontología», «Pediatría».
 *
 * ⚠️ Hoy no lo consulta NADIE: ni las citas, ni el calendario, ni la tienda
 * pública. Es un CRUD que no alimenta nada todavía (ficha § Nadie usa los
 * grupos). Se construye porque la pantalla existe y el esquema también; antes
 * de darle más peso conviene decidir para qué sirve — filtrar la tienda,
 * agrupar el calendario, o permisos por grupo.
 */
class Grupo extends Model
{
    protected $table = 'grupos';

    // `descripcion` existe en la tabla pero el contrato no la emite (§2.12).
    protected $fillable = ['nombre', 'descripcion', 'activo'];

    protected $casts = ['activo' => 'boolean'];

    public function locales(): BelongsToMany
    {
        return $this->belongsToMany(Local::class, 'grupo_local');
    }

    public function profesionales(): BelongsToMany
    {
        return $this->belongsToMany(Profesional::class, 'grupo_profesional');
    }

    public function servicios(): BelongsToMany
    {
        return $this->belongsToMany(Servicio::class, 'grupo_servicio');
    }
}
