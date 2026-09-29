<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Migración de DATOS para la Story 1.3 (hueco G-3).
//
// Desde ahora una cita solo se agenda si el profesional está habilitado en la
// sede (`local_profesional.habilitado`) y presta el servicio
// (`servicio_profesional`), sin excepción para pivotes vacíos (FR-67). Hasta
// hoy nadie los exigía, así que hay negocios con profesionales sin ninguna sede
// y servicios sin ningún profesional: sin este relleno, su agenda dejaría de
// funcionar el día del despliegue.
//
// Lo que se rellena es SOLO lo que está vacío del todo:
//   - un profesional sin ninguna fila en `local_profesional` → habilitado en
//     todas las sedes;
//   - un servicio sin ningún profesional en `servicio_profesional` → asignado a
//     todos los profesionales;
//   - un profesional sin ningún servicio en `servicio_profesional` → presta
//     todos los servicios (si no, quien antes lo agendaba todo no agendaría
//     nada tras desplegar).
// Lo que ya tiene filas no se toca: una asignación deliberada no se ensancha.
// Una fila que solo apunta a algo BORRADO no cuenta como asignación: quien solo
// tenía una sede cerrada, o un servicio cuyo único profesional se fue, está tan
// vacío como si no tuviera nada.
// Los tres conjuntos «vacíos» se calculan ANTES de insertar nada: si no, el
// relleno de un servicio vacío le daría un servicio al profesional vacío y este
// se quedaría sin los demás.
//
// Sin modelos Eloquent (el modelo de mañana no es el de hoy) y sin nada
// borrado. `insertOrIgnore` sobre los UNIQUE de los pivotes: dos pasadas dan
// lo mismo, y en una base recién provisionada no hay nada que rellenar.
//
// Se despliega con `tenants:migrar-provisionados`, nunca con `tenants:migrate`
// a secas.
return new class extends Migration
{
    public function up(): void
    {
        $ahora = now();

        $profesionales = DB::table('profesionales')->whereNull('deleted_at')->pluck('id');
        $locales = DB::table('locales')->whereNull('deleted_at')->pluck('id');
        $servicios = DB::table('servicios')->whereNull('deleted_at')->pluck('id');

        $sinSedes = DB::table('profesionales')
            ->whereNull('deleted_at')
            ->whereNotExists(fn ($q) => $q->from('local_profesional')
                ->join('locales', 'locales.id', '=', 'local_profesional.local_id')
                ->whereNull('locales.deleted_at')
                ->whereColumn('local_profesional.profesional_id', 'profesionales.id'))
            ->pluck('id');

        $sinProfesionales = DB::table('servicios')
            ->whereNull('deleted_at')
            ->whereNotExists(fn ($q) => $q->from('servicio_profesional')
                ->join('profesionales', 'profesionales.id', '=', 'servicio_profesional.profesional_id')
                ->whereNull('profesionales.deleted_at')
                ->whereColumn('servicio_profesional.servicio_id', 'servicios.id'))
            ->pluck('id');

        $sinServicios = DB::table('profesionales')
            ->whereNull('deleted_at')
            ->whereNotExists(fn ($q) => $q->from('servicio_profesional')
                ->join('servicios', 'servicios.id', '=', 'servicio_profesional.servicio_id')
                ->whereNull('servicios.deleted_at')
                ->whereColumn('servicio_profesional.profesional_id', 'profesionales.id'))
            ->pluck('id');

        foreach ($sinSedes as $profesionalId) {
            $filas = $locales->map(fn ($localId) => [
                'local_id' => $localId,
                'profesional_id' => $profesionalId,
                'habilitado' => true,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ])->all();

            if ($filas !== []) {
                DB::table('local_profesional')->insertOrIgnore($filas);
            }
        }

        foreach ($sinProfesionales as $servicioId) {
            $filas = $profesionales->map(fn ($profesionalId) => [
                'servicio_id' => $servicioId,
                'profesional_id' => $profesionalId,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ])->all();

            if ($filas !== []) {
                DB::table('servicio_profesional')->insertOrIgnore($filas);
            }
        }

        foreach ($sinServicios as $profesionalId) {
            $filas = $servicios->map(fn ($servicioId) => [
                'servicio_id' => $servicioId,
                'profesional_id' => $profesionalId,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ])->all();

            if ($filas !== []) {
                DB::table('servicio_profesional')->insertOrIgnore($filas);
            }
        }
    }

    // No se deshacen datos: tras el despliegue ya no se distingue lo que puso
    // esta migración de lo que el negocio asignó después a mano.
    public function down(): void {}
};
