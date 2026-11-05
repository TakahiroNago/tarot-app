<?php

use App\Mail\ContactInquiry;
use Illuminate\Support\Facades\Mail;

test('the contact form is public and does not expose the recipient address', function () {
    $this->get(route('contact.create'))
        ->assertOk()
        ->assertSee('お問い合わせ')
        ->assertSee(route('contact.store'))
        ->assertDontSee('takahiro.nago.art@gmail.com');

    $this->get(route('legal.privacy'))
        ->assertOk()
        ->assertSee(route('contact.create'))
        ->assertDontSee('takahiro.nago.art@gmail.com')
        ->assertSee('https://policies.google.com/privacy')
        ->assertSee('https://stripe.com/jp/privacy')
        ->assertSee('https://openai.com/policies/privacy-policy/');
});

test('a valid contact inquiry is emailed to the configured address with a reply-to', function () {
    Mail::fake();

    $this->post(route('contact.store'), [
        'name' => '利用者',
        'email' => 'visitor@example.com',
        'subject' => 'お問い合わせの件',
        'message' => '確認したい内容です。',
    ])
        ->assertRedirect(route('contact.create'))
        ->assertSessionHas('status', 'お問い合わせを送信しました。');

    Mail::assertSent(ContactInquiry::class, function (ContactInquiry $mail): bool {
        return $mail->hasTo(config('services.contact.email'))
            && $mail->hasReplyTo('visitor@example.com', '利用者')
            && str_contains($mail->render(), '確認したい内容です。');
    });
});

test('invalid contact inquiries are rejected without sending an email', function () {
    Mail::fake();

    $this->post(route('contact.store'), [
        'name' => "利用者\r\nX-Header: injection",
        'email' => 'not-an-email',
        'subject' => "お問い合わせ\r\nBcc: victim@example.com",
        'message' => '',
    ])->assertSessionHasErrors(['name', 'email', 'subject', 'message']);

    Mail::assertNothingOutgoing();
});
