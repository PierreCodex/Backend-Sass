<?php

declare(strict_types=1);

namespace App\Http\Controllers\Publico;

use App\Http\Controllers\Controller;
use App\Models\BusinessCategory;
use Illuminate\Http\JsonResponse;

class CategoriasNegocioController extends Controller
{
    /**
     * Sin sesión: alimenta el select "Tipo de negocio" del registro.
     */
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'data' => BusinessCategory::where('activo', true)
                ->orderBy('id')
                ->get(['id', 'nombre']),
        ]);
    }
}
