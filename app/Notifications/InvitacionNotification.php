<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * «Te dieron de alta, crea tu contraseña».
 *
 * No hereda de `ResetPassword` a propósito: comparte el mecanismo del token
 * pero no el texto ni la URL. Quien recibe esto no ha perdido nada — le acaban
 * de dar acceso, y el correo tiene que explicarle qué es y quién se lo manda,
 * o parecerá phishing.
 *
 * Encolada, como todo lo que sale por Resend: el envío es una llamada HTTP y no
 * debe bloquear el alta del empleado ni tumbarla si Resend falla.
 */
class InvitacionNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private string $token,
        private ?string $negocio = null,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = config('app.frontend_url').'/invitacion?'.http_build_query([
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ]);

        $de = $this->negocio !== null ? "en {$this->negocio}" : 'en el sistema de tu negocio';
        $dias = (int) round(config('auth.passwords.invitaciones.expire') / 1440);

        return (new MailMessage)
            ->subject('Te dieron acceso al panel')
            ->greeting('¡Hola!')
            ->line("Te crearon una cuenta {$de} para que puedas ver tu agenda y tus citas.")
            ->line('Solo falta que elijas tu contraseña.')
            ->action('Crear mi contraseña', $url)
            ->line("El enlace vence en {$dias} días. Si se te pasa, pídele a quien te dio de alta que te lo reenvíe.")
            ->salutation('Un saludo.');
    }
}
