<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * «Te dieron acceso, crea tu contraseña».
 *
 * No hereda de `ResetPassword` a propósito: comparte el mecanismo del token
 * pero no el texto ni la URL. Quien recibe esto no ha perdido nada — le acaban
 * de dar acceso, y el correo tiene que explicarle qué es y **quién se lo
 * manda**, o parecerá phishing. Recibir sin haberlo pedido un enlace para
 * «crear una contraseña» es exactamente la forma de una estafa; lo único que
 * lo distingue es reconocer el nombre de su jefe y el de su trabajo.
 *
 * Encolada, como todo lo que sale por Resend: el envío es una llamada HTTP y no
 * debe bloquear el alta del empleado ni tumbarla si Resend falla.
 */
class InvitacionNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private string $token,
        private ?Tenant $negocio = null,
        private ?string $invitadoPor = null,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $email = $notifiable->getEmailForPasswordReset();

        $url = config('app.frontend_url').'/invitacion?'.http_build_query([
            'token' => $this->token,
            'email' => $email,
        ]);

        /*
         * El nombre del negocio es NULL hasta el paso 1 del onboarding, así que
         * hay que tener un texto que funcione sin él — es raro pero posible:
         * un dueño que da de alta a alguien antes de ponerle nombre a su local.
         */
        $negocio = $this->negocio?->nombre ?: 'tu negocio';

        $dias = (int) round(config('auth.passwords.invitaciones.expire') / 1440);

        return (new MailMessage)
            ->subject("Te dieron acceso al panel de {$negocio}")
            ->view('emails.invitacion', [
                'nombre' => $notifiable->nombre,
                'negocio' => $negocio,
                'invitadoPor' => $this->invitadoPor,
                'email' => $email,
                'url' => $url,
                'dias' => $dias,
                'titulo' => "Te dieron acceso al panel de {$negocio}",
                'preheader' => "Elige tu contraseña para entrar. Tu usuario es {$email}.",

                /*
                 * El color del NEGOCIO, no el nuestro: quien recibe esto no
                 * conoce nuestra marca, conoce la barbería donde va a trabajar.
                 * Con el default de la columna si no lo han tocado.
                 */
                'color' => $this->negocio?->color_primario ?: '#4f46e5',
            ]);
    }
}
