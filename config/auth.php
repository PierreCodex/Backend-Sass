<?php

use App\Models\User;

return [

    /*
    |--------------------------------------------------------------------------
    | Authentication Defaults
    |--------------------------------------------------------------------------
    |
    | This option defines the default authentication "guard" and password
    | reset "broker" for your application. You may change these values
    | as required, but they're a perfect start for most applications.
    |
    */

    'defaults' => [
        'guard' => env('AUTH_GUARD', 'web'),
        'passwords' => env('AUTH_PASSWORD_BROKER', 'users'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication Guards
    |--------------------------------------------------------------------------
    |
    | Next, you may define every authentication guard for your application.
    | Of course, a great default configuration has been defined for you
    | which utilizes session storage plus the Eloquent user provider.
    |
    | All authentication guards have a user provider, which defines how the
    | users are actually retrieved out of your database or other storage
    | system used by the application. Typically, Eloquent is utilized.
    |
    | Supported: "session"
    |
    */

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | User Providers
    |--------------------------------------------------------------------------
    |
    | All authentication guards have a user provider, which defines how the
    | users are actually retrieved out of your database or other storage
    | system used by the application. Typically, Eloquent is utilized.
    |
    | If you have multiple user tables or models you may configure multiple
    | providers to represent the model / table. These providers may then
    | be assigned to any extra authentication guards you have defined.
    |
    | Supported: "database", "eloquent"
    |
    */

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => env('AUTH_MODEL', User::class),
        ],

        // 'users' => [
        //     'driver' => 'database',
        //     'table' => 'users',
        // ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Resetting Passwords
    |--------------------------------------------------------------------------
    |
    | These configuration options specify the behavior of Laravel's password
    | reset functionality, including the table utilized for token storage
    | and the user provider that is invoked to actually retrieve users.
    |
    | The expiry time is the number of minutes that each reset token will be
    | considered valid. This security feature keeps tokens short-lived so
    | they have less time to be guessed. You may change this as needed.
    |
    | The throttle setting is the number of seconds a user must wait before
    | generating more password reset tokens. This prevents the user from
    | quickly generating a very large amount of password reset tokens.
    |
    */

    'passwords' => [
        'users' => [
            'provider' => 'users',
            'table' => env('AUTH_PASSWORD_RESET_TOKEN_TABLE', 'password_reset_tokens'),

            /*
             * Conexion CENTRAL, explicita. Sin esto el broker usa la conexion
             * por defecto, que dentro de `tenancy.init` es la del negocio — y
             * ahi la tabla de tokens no existe. El reset de hoy se salva porque
             * sus rutas son publicas y corren fuera de tenancy; la invitacion
             * de abajo NO, y se descubrio con un 500 en pleno alta de empleado.
             */
            'connection' => env('DB_CONNECTION', 'central'),

            'expire' => 60,
            'throttle' => 60,
        ],

        /*
         * Invitaciones de empleado. Mismo mecanismo que el reset, pero con
         * caducidad de 7 dias y TABLA PROPIA.
         *
         * Los 60 minutos del reset estan bien para quien acaba de pulsar
         * «olvide mi contrasena» y malisimos para una invitacion: el barbero
         * abre el correo por la noche y ya vencio. Y la tabla es aparte porque
         * cada broker juzga la caducidad con su propia configuracion —
         * compartiendola, un enlace de reset de hace tres dias se podria
         * canjear por el endpoint de invitacion y seguiria valiendo.
         */
        'invitaciones' => [
            'provider' => 'users',
            'table' => 'invitacion_tokens',

            // Central, y aqui es imprescindible: las invitaciones se emiten
            // DESDE el panel, o sea con la conexion del tenant activa.
            'connection' => env('DB_CONNECTION', 'central'),

            'expire' => 60 * 24 * 7,
            'throttle' => 60,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Password Confirmation Timeout
    |--------------------------------------------------------------------------
    |
    | Here you may define the amount of seconds before a password confirmation
    | window expires and users are asked to re-enter their password via the
    | confirmation screen. By default, the timeout lasts for three hours.
    |
    */

    'password_timeout' => env('AUTH_PASSWORD_TIMEOUT', 10800),

];
