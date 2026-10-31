<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Story 1.6: el módulo `pagos` (verificar el pago de una cita) en los negocios
// que ya estaban en marcha. Los nuevos lo reciben del preset al provisionar.
//
// - Administrador general: siempre. Su rol no es editable y tiene que tenerlo
//   todo; si no, su matriz saldría con `pagos: null` y no podría verificar ni
//   el primer pago.
// - Administrador de sede: solo si el negocio no lo ha personalizado
//   (`editado_at` NULL). A quien tocó su rol no se le cambia a su espalda.
// - Profesional y roles propios: nada. `null` es «sin acceso», que es lo que
//   la matriz A-1 da al profesional, y lo que un rol propio tenía hasta hoy.
return new class extends Migration
{
    public function up(): void
    {
        $this->darPagos(DB::table('roles')->where('clave', 'admin_general')->first());
        $this->darPagos(DB::table('roles')->where('clave', 'admin_local')->whereNull('editado_at')->first());
    }

    public function down(): void
    {
        // La misma regla que `up()`: al de sede personalizado no se le toca,
        // tampoco al deshacer. Su `pagos` es ya decisión del negocio.
        DB::table('roles')
            ->where(fn ($q) => $q
                ->where('clave', 'admin_general')
                ->orWhere(fn ($q) => $q->where('clave', 'admin_local')->whereNull('editado_at')))
            ->get()
            ->each(function ($rol) {
                $permisos = json_decode($rol->permisos, true) ?: [];
                unset($permisos['pagos']);
                DB::table('roles')->where('id', $rol->id)->update(['permisos' => json_encode($permisos)]);
            });
    }

    private function darPagos(?object $rol): void
    {
        if ($rol === null) {
            return;
        }

        $permisos = json_decode($rol->permisos, true) ?: [];
        $permisos['pagos'] = 'gestionar';

        DB::table('roles')->where('id', $rol->id)->update(['permisos' => json_encode($permisos)]);
    }
};
