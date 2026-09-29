<?php

declare(strict_types=1);

namespace App\Http\Requests\Profesionales;

use App\Models\Profesional;
use App\Models\User;
use App\Support\Rango;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Quien presta los servicios. Todo esto vive en la base del negocio.
 *
 * La única parte que cruza a la central es `usuario`, opcional: es la casilla
 * «darle acceso al panel». Sin ella la persona existe, atiende y cobra su
 * comisión, pero no entra al sistema — que es el caso de la mayoría de los
 * barberos de una barbería.
 *
 * Sin campo de contraseña, tampoco cuando se da acceso: la elige la propia
 * persona desde la invitación que le llega por correo.
 */
class ProfesionalRequest extends FormRequest
{
    /**
     * Darle acceso al panel es solo del general (G-4), y se corta ANTES de
     * validar: si no, el `unique` global de `usuario.email` —y el 422 del cupo
     * en el service— le contestarían primero a un administrador de sede,
     * filtrando si un correo existe en CUALQUIER negocio.
     *
     * Solo cuando de verdad se pediría una cuenta: con `usuario.email` y a una
     * ficha sin cuenta (a la que ya la tiene, el service ignora `usuario`). La
     * regla es la de `Rango`; `UsuarioService` la vuelve a llamar.
     */
    public function authorize(): bool
    {
        $ficha = $this->route('profesional');
        $sinCuenta = ! $ficha instanceof Profesional || $ficha->usuario_id === null;

        if ($sinCuenta && $this->filled('usuario.email')) {
            Rango::soloElAdminGeneral($this->user());
        }

        return true;
    }

    protected function prepareForValidation(): void
    {
        /*
         * En multipart todo llega como cadena, y "" no es null para `nullable`.
         * Sin esto, un campo que el usuario dejó vacío entraría a validarse
         * como si trajera contenido.
         */
        foreach (['cargo', 'telefono', 'monto_sueldo', 'periodo_pago'] as $campo) {
            if ($this->input($campo) === '') {
                $this->merge([$campo => null]);
            }
        }
    }

    public function rules(): array
    {
        return [
            // El que ven los clientes en la tienda pública.
            'nombre' => ['required', 'string', 'max:150'],

            /*
             * La cuenta, opcional. Si viene, `UsuarioService` crea el usuario
             * central y le manda la invitación; el email es UNIQUE GLOBAL, así
             * que choca con cualquier usuario de CUALQUIER negocio.
             *
             * Solo se acepta si aún no tiene cuenta: cambiarle el correo o el
             * rol a quien ya la tiene se hace en `/usuarios`, que es su sitio.
             * Aquí se ignora en silencio (el service lo comprueba).
             */
            'usuario' => ['nullable', 'array'],
            'usuario.email' => [
                'required_with:usuario', 'email', 'max:150',
                Rule::unique(User::class, 'email'),
            ],
            'usuario.rol_id' => [
                'required_with:usuario', 'integer',
                Rule::exists('roles', 'id'),
            ],

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
            /*
             * La clave lleva el atributo COMPLETO. El campo es `usuario.email`
             * —anidado desde que la cuenta es opcional— y con `email.unique` a
             * secas Laravel no encuentra el mensaje y cae al suyo, en ingles:
             * «The usuario.email has already been taken.»
             */
            'usuario.email.unique' => 'Ya existe una cuenta con ese correo.',
            'usuario.email.required_with' => 'Para darle acceso al panel hace falta su correo.',
            'usuario.rol_id.required_with' => 'Elige qué podrá hacer en el panel.',

            'telefono.regex' => 'El teléfono debe tener el formato +51 seguido de 9 dígitos.',
            'foto.max' => 'La foto no debe superar los 2 MB.',
            'foto.image' => 'El archivo debe ser una imagen.',
        ];
    }
}
