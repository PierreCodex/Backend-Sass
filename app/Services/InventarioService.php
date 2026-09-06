<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\InventarioMovimiento;
use App\Models\Producto;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * El stock y su porqué, siempre juntos.
 *
 * `productos.stock` es un saldo materializado: rápido de leer y fácil de dejar
 * mintiendo. La regla de este service es que nunca se toca solo — cada cambio
 * escribe además su fila en `inventario_movimientos`, con quién y por qué. Por
 * eso las dos escrituras van en la misma transacción.
 */
class InventarioService
{
    /** Lo que el contrato llama de una forma y la tabla de otra (§1.10). */
    private const TRADUCCION = [
        'precio_venta' => 'precio',
        'precio_compra' => 'costo',
    ];

    /**
     * @param  array<string, mixed>  $datos
     */
    public function crear(array $datos, int $usuarioId): Producto
    {
        return DB::transaction(function () use ($datos, $usuarioId) {
            $borrado = Producto::onlyTrashed()->where('nombre', $datos['nombre'])->first();

            /*
             * Restaurar es el truco para no chocar con el UNIQUE del nombre,
             * pero para el negocio esto es un ALTA: rellenó un formulario en
             * blanco. Nace limpio —activo, con el stock que se acaba de
             * escribir— y no arrastrando lo que tuviera el anterior. Misma
             * decisión que en servicios, y por el mismo motivo.
             */
            if ($borrado !== null) {
                $borrado->restore();
                $producto = $borrado;
                $datos += ['activo' => true];
            } else {
                $producto = new Producto;
            }

            $this->rellenar($producto, $datos);
            $producto->save();

            /*
             * `refresh()` para traerse los defaults que pone MySQL.
             *
             * `stock_minimo` ausente lo rellena la tabla con 5, pero el modelo
             * en memoria se queda sin él y el Resource emitiría `null` — el
             * negocio vería «sin umbral» sobre una fila que sí lo tiene. Es
             * exactamente lo que pasó con `es_principal` en locales: un valor
             * que decide la base no existe hasta que se relee.
             */
            $producto->refresh();

            /*
             * El stock inicial también es un movimiento.
             *
             * Sin esto, un producto que nace con 30 unidades tiene un saldo que
             * ninguna fila explica, y el día que se pinte el historial dirá que
             * esas 30 aparecieron solas. El libro tiene que cuadrar desde la
             * primera línea.
             */
            if ($producto->stock > 0) {
                $this->anotar($producto, 'entrada', $producto->stock, 'Stock inicial', $usuarioId);
            }

            return $producto;
        });
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    public function actualizar(Producto $producto, array $datos): Producto
    {
        /*
         * `stock` no llega nunca: el Form Request no lo valida al editar, así
         * que no está en `validated()`. Aquí no hace falta defenderse otra vez
         * — y no se hace a propósito, porque dos guardias de la misma regla es
         * lo que ya nos ha mordido tres veces.
         */
        $this->rellenar($producto, $datos);
        $producto->save();

        return $producto;
    }

    public function eliminar(Producto $producto): void
    {
        // Soft delete: `cita_producto` congela lo vendido, y borrar de verdad
        // reescribiría cuánto costó una venta vieja.
        $producto->delete();
    }

    /**
     * Registra una entrada o una salida y devuelve el producto con su stock ya
     * recalculado.
     *
     * @param  array{tipo: string, cantidad: int, motivo?: ?string}  $datos
     *
     * @throws ValidationException si la salida se pasa del stock disponible
     */
    public function movimiento(Producto $producto, array $datos, int $usuarioId): Producto
    {
        return DB::transaction(function () use ($producto, $datos, $usuarioId) {
            /*
             * `lockForUpdate` y releer: sin el bloqueo, dos salidas simultáneas
             * leen el mismo stock, las dos pasan la comprobación y el saldo
             * acaba por debajo de lo que ninguna de las dos habría permitido.
             * Es la misma carrera que el anti-solape de citas, con inventario
             * en vez de horas.
             */
            $producto = Producto::whereKey($producto->id)->lockForUpdate()->firstOrFail();

            $signo = $datos['tipo'] === 'entrada' ? 1 : -1;
            $resultante = $producto->stock + ($signo * $datos['cantidad']);

            /*
             * Una salida no puede dejar el stock en negativo.
             *
             * La app vieja restaba sin tope y el saldo se iba por debajo de
             * cero; la maqueta ya avisaba de esto y recomendaba cortarlo aquí.
             * Un stock negativo no es un dato, es un error de captura contado
             * como si fuera inventario — y de ahí sale a la tienda pública y a
             * los reportes. El 422 va en `cantidad` porque es el campo que hay
             * que corregir.
             */
            if ($resultante < 0) {
                throw ValidationException::withMessages([
                    'cantidad' => "No hay stock suficiente: quedan {$producto->stock}.",
                ]);
            }

            $this->anotar($producto, $datos['tipo'], $datos['cantidad'], $datos['motivo'] ?? null, $usuarioId);

            $producto->stock = $resultante;
            $producto->save();

            return $producto;
        });
    }

    private function anotar(Producto $producto, string $tipo, int $cantidad, ?string $motivo, int $usuarioId): void
    {
        InventarioMovimiento::create([
            'producto_id' => $producto->id,
            'tipo' => $tipo,
            // Siempre positiva: el signo lo lleva `tipo`. Guardarla con signo
            // haría que sumar la columna diera el saldo por accidente y que
            // «cantidad» significase dos cosas según la fila.
            'cantidad' => $cantidad,
            'motivo' => $motivo,
            'registrado_por_user_id' => $usuarioId,
        ]);
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    private function rellenar(Producto $producto, array $datos): void
    {
        foreach (self::TRADUCCION as $delContrato => $deLaTabla) {
            if (array_key_exists($delContrato, $datos)) {
                $datos[$deLaTabla] = $datos[$delContrato];
                unset($datos[$delContrato]);
            }
        }

        // `stock_minimo` ausente se deja en manos del default de la tabla (5),
        // que es justo lo que promete el contrato.
        if (($datos['stock_minimo'] ?? null) === null) {
            unset($datos['stock_minimo']);
        }

        $producto->fill($datos);
    }
}
