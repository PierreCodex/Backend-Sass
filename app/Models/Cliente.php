<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Ficha del cliente final, en la BD del tenant.
 *
 * NO tiene cuenta ni login (decisión cerrada): la reserva pública la crea o
 * la encuentra por teléfono, y la gestión posterior de la cita va por el
 * `codigo` que se envía por WhatsApp. `central_user_id` queda vestigial.
 *
 * Soft delete: las citas la referencian, así que borrarla de verdad
 * reescribiría el historial.
 */
class Cliente extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'nombre',
        'apellido',
        'telefono',
        'telefono_normalizado',
        'email',
        'documento',
        'fecha_nacimiento',
        'notas',
    ];

    protected $casts = [
        'fecha_nacimiento' => 'date',
    ];

    public function citas(): HasMany
    {
        return $this->hasMany(Cita::class);
    }

    /**
     * Deja solo dígitos y quita el prefijo país, para que "904 169 872",
     * "904169872" y "+51 904 169 872" sean la MISMA persona.
     *
     * Deliberadamente conservador: no inventa un +51 que el usuario no
     * escribió ni recorta números cortos. Un "999" de prueba se normaliza a
     * "999" y sigue siendo suyo. Casar de más uniría fichas de dos personas
     * distintas, que es mucho peor que dejar dos fichas de una.
     */
    public static function normalizarTelefono(?string $telefono): ?string
    {
        if ($telefono === null || trim($telefono) === '') {
            return null;
        }

        $digitos = preg_replace('/\D+/', '', $telefono) ?? '';

        if ($digitos === '') {
            return null;
        }

        // 51 + 9 dígitos es un móvil peruano con prefijo país.
        if (strlen($digitos) === 11 && str_starts_with($digitos, '51')) {
            $digitos = substr($digitos, 2);
        }

        return $digitos;
    }
}
