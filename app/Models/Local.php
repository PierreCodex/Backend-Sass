<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Una sede del negocio.
 *
 * `horario` es un JSON `{apertura, cierre}` y no dos columnas TIME por una
 * razon de futuro: el dia que un negocio quiera «sabado hasta la 1, domingo
 * cerrado» cabe sin migrar N bases. El contrato pide el par plano
 * (`horario_desde`/`horario_hasta`) y eso lo deriva el Resource — el JSON es la
 * fuente de verdad (decision cerrada en CLAUDE.md).
 */
class Local extends Model
{
    use SoftDeletes;

    protected $table = 'locales';

    protected $fillable = [
        'nombre', 'direccion', 'descripcion_publica', 'telefono', 'email',
        'latitud', 'longitud', 'color', 'banner', 'logo', 'horario', 'activo',
    ];

    protected $casts = [
        'horario' => 'array',
        'es_principal' => 'boolean',
        'activo' => 'boolean',
    ];

    /** Quien atiende en esta sede, con sus datos propios de aqui. */
    public function profesionales(): BelongsToMany
    {
        return $this->belongsToMany(Profesional::class, 'local_profesional')
            ->withPivot(['habilitado', 'nombre_publico', 'perfil', 'horario_apertura', 'horario_cierre'])
            ->withTimestamps();
    }

    /** Las cuentas cuyo alcance incluye esta sede. */
    public function usuarios(): BelongsToMany
    {
        return $this->belongsToMany(Usuario::class, 'local_usuario')->withTimestamps();
    }
}
