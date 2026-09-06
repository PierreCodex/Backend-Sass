<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * El libro mayor del stock: cada fila explica un cambio.
 *
 * `productos.stock` es el saldo materializado, y esta tabla el porqué. Nadie
 * escribe una sin la otra — de eso se encarga `InventarioService`, en
 * transacción.
 *
 * El ENUM sabe más que la pantalla (§2.8): `entrada|salida` los registra una
 * persona, `venta` la generará la cita con productos en el Sprint 4, y `ajuste`
 * queda reservado. El endpoint manual solo acepta los dos primeros.
 *
 * `registrado_por_user_id` apunta a `users` de la CENTRAL y no lleva FK: no se
 * puede entre bases. La integridad la pone la aplicación.
 */
class InventarioMovimiento extends Model
{
    public const TIPOS_MANUALES = ['entrada', 'salida'];

    protected $table = 'inventario_movimientos';

    protected $fillable = [
        'producto_id',
        'tipo',
        'cantidad',
        'motivo',
        'cita_id',
        'registrado_por_user_id',
    ];

    protected $casts = [
        'cantidad' => 'integer',
    ];

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }
}
