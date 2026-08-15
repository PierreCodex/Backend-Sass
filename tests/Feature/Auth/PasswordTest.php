<?php

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->seed();
    Mail::fake();
    Queue::fake();
    Notification::fake();

    $this->postJson('/api/register', payloadRegistro());
    $this->user = User::where('email', 'maria@correo.pe')->firstOrFail();
});

test('forgot-password responde 200 exista o no el correo', function () {
    $this->postJson('/api/forgot-password', ['email' => 'maria@correo.pe'])->assertOk();
    $this->postJson('/api/forgot-password', ['email' => 'nadie@correo.pe'])->assertOk();

    Notification::assertSentTo($this->user, ResetPassword::class);
});

test('reset con token válido cambia la contraseña y permite el login', function () {
    $this->postJson('/api/forgot-password', ['email' => 'maria@correo.pe']);

    $token = null;
    Notification::assertSentTo($this->user, ResetPassword::class, function ($notification) use (&$token) {
        $token = $notification->token;

        return true;
    });

    $this->postJson('/api/reset-password', [
        'token' => $token,
        'email' => 'maria@correo.pe',
        'password' => 'nuevaclave99',
        'password_confirmation' => 'nuevaclave99',
    ])->assertOk();

    $this->user->forceFill(['email_verified_at' => now()])->save();

    $this->postJson('/api/login', ['email' => 'maria@correo.pe', 'password' => 'nuevaclave99'])
        ->assertOk();
});

test('reset con token inválido → 422', function () {
    $this->postJson('/api/reset-password', [
        'token' => 'token-falso',
        'email' => 'maria@correo.pe',
        'password' => 'nuevaclave99',
        'password_confirmation' => 'nuevaclave99',
    ])->assertStatus(422);
});
