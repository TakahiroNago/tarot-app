<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

uses(RefreshDatabase::class);

beforeEach(function () {
    Exceptions::fake();

    Mail::extend('failing', fn () => new class extends AbstractTransport
    {
        protected function doSend(SentMessage $message): void
        {
            throw new TransportException('SMTP is unavailable.');
        }

        public function __toString(): string
        {
            return 'failing://';
        }
    });

    config([
        'mail.mailers.failing' => ['transport' => 'failing'],
        'mail.default' => 'failing',
    ]);
});

test('registration still signs the user in when the verification mail cannot be sent', function () {
    $this->post(route('register.store'), [
        'name' => 'New User',
        'email' => 'new-user@example.com',
        'password' => 'A-strong-password-2026',
        'password_confirmation' => 'A-strong-password-2026',
    ])
        ->assertRedirect(route('verification.notice'))
        ->assertSessionHas('status', 'verification-link-failed');

    $this->assertAuthenticated();
    $this->assertDatabaseHas('users', ['email' => 'new-user@example.com']);
    Exceptions::assertReported(TransportException::class);
});

test('resending the verification mail reports a delivery failure', function () {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)
        ->from(route('verification.notice'))
        ->post(route('verification.send'))
        ->assertRedirect(route('verification.notice'))
        ->assertSessionHas('status', 'verification-link-failed');

    Exceptions::assertReported(TransportException::class);
});

test('a password reset request reports a delivery failure and keeps the address', function () {
    $user = User::factory()->create();

    $this->from(route('password.request'))
        ->post(route('password.email'), ['email' => $user->email])
        ->assertRedirect(route('password.request'))
        ->assertSessionHasErrors('email')
        ->assertSessionHasInput('email', $user->email);

    Exceptions::assertReported(TransportException::class);
});

test('an admin password reset reports a delivery failure', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();

    $this->actingAs($admin)
        ->from(route('admin.users.show', $user))
        ->post(route('admin.users.password-reset', $user))
        ->assertRedirect(route('admin.users.show', $user))
        ->assertSessionHasErrors('user');

    Exceptions::assertReported(TransportException::class);
});

test('a contact inquiry reports a delivery failure and keeps what the visitor wrote', function () {
    $this->from(route('contact.create'))
        ->post(route('contact.store'), [
            'name' => 'Visitor',
            'email' => 'visitor@example.com',
            'subject' => 'Question',
            'message' => 'A message that must not be lost.',
        ])
        ->assertRedirect(route('contact.create'))
        ->assertSessionHasErrors('contact')
        ->assertSessionHasInput('message', 'A message that must not be lost.');

    Exceptions::assertReported(TransportException::class);
});
