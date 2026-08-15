<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\User;
use App\Support\FirmaVerificacion;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class VerificarCorreoMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public User $user)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Verifica tu correo para activar tu cuenta',
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: sprintf(
                '<p>Hola %s,</p>'
                .'<p>Gracias por registrarte. Haz clic en el siguiente enlace para verificar tu correo y activar tu cuenta:</p>'
                .'<p><a href="%s">Verificar mi correo</a></p>'
                .'<p>El enlace vence en 48 horas. Si no creaste esta cuenta, ignora este mensaje.</p>',
                e($this->user->nombre),
                e(FirmaVerificacion::url($this->user)),
            ),
        );
    }
}
