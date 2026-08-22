<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * La notificación de Laravel, pero encolada: igual que VerificarCorreoMail,
 * el envío es una llamada HTTP a Resend y no debe bloquear la respuesta de
 * /forgot-password ni tumbarla si Resend falla.
 *
 * Hereda el enlace que fija ResetPassword::createUrlUsing en
 * AppServiceProvider (la propiedad estática es la del padre).
 */
class RestablecerPasswordNotification extends ResetPassword implements ShouldQueue
{
    use Queueable;
}
