<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * `orden = 0` es la imagen principal; 1..4 son la galeria (comentario de la
 * migracion del catalogo). Se guarda la RUTA, no la URL.
 */
class ServicioImagen extends Model
{
    protected $table = 'servicio_imagenes';

    protected $fillable = ['servicio_id', 'ruta', 'orden'];

    protected $casts = ['orden' => 'integer'];

    public function servicio(): BelongsTo
    {
        return $this->belongsTo(Servicio::class);
    }
}
