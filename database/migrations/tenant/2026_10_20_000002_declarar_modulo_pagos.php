<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Añade pagos a los presets que no fueron personalizados.
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            DB::table('roles')
                ->whereIn('clave', ['admin_general', 'admin_local', 'recepcion'])
                ->where('sistema', true)
                ->whereNull('editado_at')
                ->get()
                ->each(function (object $rol): void {
                    $permisos = json_decode((string) $rol->permisos, true) ?: [];
                    $permisos['pagos'] = 'gestionar';

                    DB::table('roles')->where('id', $rol->id)->update([
                        'permisos' => json_encode($permisos),
                        'updated_at' => now(),
                    ]);
                });
        });
    }

    public function down(): void
    {
        DB::table('roles')
            ->whereIn('clave', ['admin_general', 'admin_local', 'recepcion'])
            ->where('sistema', true)
            ->whereNull('editado_at')
            ->get()
            ->each(function (object $rol): void {
                $permisos = json_decode((string) $rol->permisos, true) ?: [];
                unset($permisos['pagos']);

                DB::table('roles')->where('id', $rol->id)->update([
                    'permisos' => json_encode($permisos),
                    'updated_at' => now(),
                ]);
            });
    }
};
