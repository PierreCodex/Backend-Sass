<?php

declare(strict_types=1);

namespace App\Http\Requests\Empleados;

use App\Models\Profesional;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * El empleado cruza las dos bases (§1.9), y también su validación: `email`,
 * `password` y `rol` son de `users` (central); el resto, de `profesionales`.
 *
 * NO existe el campo `usuario` que pide la ficha: `users.usuario` se eliminó
 * (§2.9) y la credencial de login es el email, único GLOBAL. El Resource sigue
 * emitiendo `usuario` con el email dentro mientras el contrato conserve la
 * clave, pero de entrada no se acepta — anotado en pendientes-contrato.
 */
class EmpleadoRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        /*
         * En multipart todo llega como cadena, y "" no es null para `nullable`.
         * La contraseña es el caso que importa: la ficha dice que al editar se
         * manda vacía para "no cambiarla", y sin esto entraría a `min:8`.
         */
        foreach (['password', 'cargo', 'telefono', 'monto_sueldo', 'periodo_pago'] as $campo) {
            if ($this->input($campo) === '') {
                $this->merge([$campo => null]);
            }
        }
    }

    public function rules(): array
    {
        $empleado = $this->route('empleado');
        $centralUserId = $empleado?->central_user_id;

        return [
            'nombre' => ['required', 'string', 'max:150'],

            /*
             * El email es la credencial y es UNIQUE GLOBAL: choca con cualquier
             * usuario de CUALQUIER negocio, no solo del propio. Es lo que
             * impide que dos personas distintas compartan login.
             */
            'email' => [
                'required', 'email', 'max:150',
                Rule::unique(User::class, 'email')->ignore($centralUserId),
            ],

            /*
             * Obligatoria al crear; ausente o null al editar significa "no la
             * toques" (ficha § Contraseña). `min:8` como el registro y el reset:
             * la ficha dice 6, pero rebajar el suelo de una credencial real por
             * cuadrar con el formulario sería al revés de como debe decidirse.
             */
            'password' => [$empleado === null ? 'required' : 'nullable', 'string', 'min:8'],

            /*
             * El rol es ahora uno de la tabla `roles` del negocio, no el ENUM
             * central: es lo que deja asignar los que crea el dueño. El
             * `users.rol` central se DERIVA de su clave (ver EmpleadoService)
             * y deja de ser un dato que el cliente elige.
             */
            'rol_id' => ['required', 'integer', Rule::exists('roles', 'id')],

            'cargo' => ['nullable', 'string', 'max:100'],

            // Mismo formato que el registro y Mi perfil: escriben la MISMA
            // columna (`users.telefono`) y el WhatsApp cuenta con el +51.
            'telefono' => ['nullable', 'string', 'regex:/^\+51\d{9}$/'],

            'foto' => ['nullable', 'image', 'mimes:jpeg,png,jpg,gif,webp', 'max:2048'],

            // La ausencia del archivo ya significa "déjala como está", así que
            // quitarla necesita bandera propia (misma lección del Sprint 1).
            'foto_eliminar' => ['nullable', 'boolean'],

            'activo' => ['nullable', 'boolean'],

            /*
             * Aún no está en el formulario: controla si la persona sale en la
             * agenda y en la tienda pública, no si consume cupo (§1.9). Se
             * acepta desde ya para que el frontend pueda añadir el interruptor
             * sin tocar backend.
             */
            'atiende' => ['nullable', 'boolean'],

            'tipo_pago' => ['required', Rule::in(Profesional::TIPOS_PAGO)],

            'comision_porcentaje' => [
                Rule::requiredIf(fn () => in_array($this->input('tipo_pago'), Profesional::TIPOS_CON_COMISION, true)),
                'nullable', 'numeric', 'min:0', 'max:100',
            ],

            'monto_sueldo' => [
                Rule::requiredIf(fn () => in_array($this->input('tipo_pago'), Profesional::TIPOS_CON_SUELDO, true)),
                'nullable', 'numeric', 'min:0',
            ],

            'periodo_pago' => [
                Rule::requiredIf(fn () => in_array($this->input('tipo_pago'), Profesional::TIPOS_CON_SUELDO, true)),
                'nullable', Rule::in(Profesional::PERIODOS_PAGO),
            ],

            /*
             * Horario. La ficha manda los 7 días siempre, pero no se exige:
             * un día ausente es un día que no se trabaja, y rechazar la
             * petición por eso solo añadiría una forma de fallar.
             */
            'horario' => ['nullable', 'array', 'max:7'],
            'horario.*.dia' => ['required', 'integer', 'between:1,7'],
            'horario.*.activo' => ['nullable', 'boolean'],
            'horario.*.desde' => ['nullable', 'date_format:H:i'],
            'horario.*.hasta' => ['nullable', 'date_format:H:i'],
            'horario.*.breaks' => ['nullable', 'array', 'max:6'],
            'horario.*.breaks.*.desde' => ['required', 'date_format:H:i'],
            'horario.*.breaks.*.hasta' => ['required', 'date_format:H:i'],

            'excepciones' => ['nullable', 'array', 'max:60'],
            'excepciones.*.fecha' => ['required', 'date_format:Y-m-d'],
            'excepciones.*.disponible' => ['nullable', 'boolean'],
            'excepciones.*.desde' => ['nullable', 'date_format:H:i'],
            'excepciones.*.hasta' => ['nullable', 'date_format:H:i'],
            'excepciones.*.nota' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Lo que no cabe en una regla suelta: coherencia DENTRO de cada día.
     *
     * No es quisquillosería. De este JSON sale la disponibilidad del Sprint 4:
     * un break que se sale de la jornada, o dos que se pisan, produce huecos
     * imposibles en la agenda y se descubre tarde, cuando ya hay citas.
     */
    public function withValidator(Validator $validador): void
    {
        $validador->after(function (Validator $v) {
            $this->validarHorario($v);
            $this->validarExcepciones($v);
        });
    }

    private function validarHorario(Validator $v): void
    {
        $vistos = [];

        foreach ((array) $this->input('horario', []) as $i => $dia) {
            if (! is_array($dia)) {
                continue;
            }

            $clave = $dia['dia'] ?? null;

            if ($clave !== null) {
                if (in_array($clave, $vistos, true)) {
                    $v->errors()->add("horario.{$i}.dia", 'Ese día está repetido.');
                }

                $vistos[] = $clave;
            }

            if (! filter_var($dia['activo'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                continue;
            }

            $desde = $dia['desde'] ?? null;
            $hasta = $dia['hasta'] ?? null;

            if ($desde === null || $hasta === null) {
                $v->errors()->add("horario.{$i}.desde", 'Un día activo necesita hora de inicio y de fin.');

                continue;
            }

            if ($hasta <= $desde) {
                $v->errors()->add("horario.{$i}.hasta", 'La hora de fin debe ser posterior a la de inicio.');

                continue;
            }

            $this->validarBreaks($v, $i, (array) ($dia['breaks'] ?? []), $desde, $hasta);
        }
    }

    /**
     * @param  array<int, mixed>  $breaks
     */
    private function validarBreaks(Validator $v, int|string $i, array $breaks, string $desde, string $hasta): void
    {
        $rangos = [];

        foreach ($breaks as $j => $break) {
            if (! is_array($break) || ! isset($break['desde'], $break['hasta'])) {
                continue;
            }

            [$bd, $bh] = [$break['desde'], $break['hasta']];

            if ($bh <= $bd) {
                $v->errors()->add("horario.{$i}.breaks.{$j}.hasta", 'El break debe terminar después de empezar.');

                continue;
            }

            if ($bd < $desde || $bh > $hasta) {
                $v->errors()->add("horario.{$i}.breaks.{$j}.desde", 'El break tiene que caer dentro de la jornada del día.');

                continue;
            }

            foreach ($rangos as $previo) {
                // Dos rangos se pisan salvo que uno acabe antes de que empiece
                // el otro. Tocarse en el extremo (13:00-14:00 y 14:00-15:00) vale.
                if ($bd < $previo[1] && $previo[0] < $bh) {
                    $v->errors()->add("horario.{$i}.breaks.{$j}.desde", 'Este break se solapa con otro del mismo día.');

                    break;
                }
            }

            $rangos[] = [$bd, $bh];
        }
    }

    private function validarExcepciones(Validator $v): void
    {
        $fechas = [];

        foreach ((array) $this->input('excepciones', []) as $i => $excepcion) {
            if (! is_array($excepcion)) {
                continue;
            }

            $fecha = $excepcion['fecha'] ?? null;

            if ($fecha !== null) {
                if (in_array($fecha, $fechas, true)) {
                    $v->errors()->add("excepciones.{$i}.fecha", 'Ya hay una excepción para esa fecha.');
                }

                $fechas[] = $fecha;
            }

            // Con `disponible: false` no hacen falta horas: es un día libre.
            if (! filter_var($excepcion['disponible'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                continue;
            }

            $desde = $excepcion['desde'] ?? null;
            $hasta = $excepcion['hasta'] ?? null;

            if ($desde === null || $hasta === null) {
                $v->errors()->add("excepciones.{$i}.desde", 'Una excepción disponible necesita hora de inicio y de fin.');

                continue;
            }

            if ($hasta <= $desde) {
                $v->errors()->add("excepciones.{$i}.hasta", 'La hora de fin debe ser posterior a la de inicio.');
            }
        }
    }

    public function messages(): array
    {
        return [
            'email.unique' => 'Ya existe una cuenta con ese correo.',
            'telefono.regex' => 'El teléfono debe tener el formato +51 seguido de 9 dígitos.',
            'password.min' => 'La contraseña debe tener al menos 8 caracteres.',
            'foto.max' => 'La foto no debe superar los 2 MB.',
            'foto.image' => 'El archivo debe ser una imagen.',
        ];
    }
}
