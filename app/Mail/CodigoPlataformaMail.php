<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * El código de un solo uso para entrar al panel de plataforma.
 *
 * Encolado como el resto de correos: necesita `queue:work`.
 */
class CodigoPlataformaMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $nombre,
        public string $codigo,
        public int $minutos,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Tu código de acceso: {$this->codigo}");
    }

    public function content(): Content
    {
        return new Content(
            htmlString: sprintf(
                '<p>Hola %s,</p>'
                .'<p>Tu código para entrar al panel de plataforma es:</p>'
                .'<p style="font-size:28px;font-weight:bold;letter-spacing:6px">%s</p>'
                .'<p>Vence en %d minutos y sirve <strong>una sola vez</strong>.</p>'
                .'<p>Si no intentaste entrar, ignora este correo y cambia tu contraseña.</p>',
                e($this->nombre),
                e($this->codigo),
                $this->minutos,
            ),
        );
    }
}
