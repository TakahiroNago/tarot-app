<?php

use App\Jobs\SendOrderConfirmationEmail;
use App\Mail\OrderConfirmationMail;
use App\Models\Order;
use App\Models\OrderEmail;
use App\Services\CheckoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

beforeEach(function () {
    Mail::fake();
    $this->freezeTime();
    $this->order = Order::query()->create([
        'order_number' => 'ECEMAILCLAIM01',
        'idempotency_key' => str_repeat('e', 64),
        'status' => 'paid',
        'payment_status' => 'paid',
        'customer_name' => '購入者',
        'customer_email' => 'buyer@example.test',
        'shipping_recipient' => '宛名',
        'shipping_country_code' => 'JP',
        'shipping_postal_code' => '1000001',
        'shipping_city' => '千代田区',
        'shipping_address_line1' => '千代田1-1',
        'shipping_method_name' => '通常配送',
        'subtotal_minor' => 1000,
        'shipping_minor' => 500,
        'total_minor' => 1500,
        'currency' => 'JPY',
    ]);
});

function existingEmailRecord(Order $order, string $status, mixed $updatedAt): OrderEmail
{
    $email = OrderEmail::query()->create([
        'order_id' => $order->id,
        'email_type' => 'order_confirmation',
        'status' => $status,
        'attempts' => 1,
    ]);
    $email->timestamps = false;
    $email->forceFill(['updated_at' => $updatedAt])->save();

    return $email;
}

function runConfirmationJob(Order $order): SendOrderConfirmationEmail
{
    $job = (new SendOrderConfirmationEmail($order->id))->withFakeQueueInteractions();
    $job->handle(app(CheckoutService::class));

    return $job;
}

test('a job that finds another attempt mid-send comes back after that claim lapses', function () {
    $email = existingEmailRecord($this->order, 'sending', now()->subMinutes(4));

    $job = runConfirmationJob($this->order);

    $job->assertReleased(361);
    Mail::assertNothingSent();
    expect($email->fresh()->status)->toBe('sending')
        ->and($email->fresh()->attempts)->toBe(1);
});

test('a claim left behind by a dead worker is taken over and the email goes out', function () {
    $email = existingEmailRecord($this->order, 'sending', now()->subMinutes(11));

    $job = runConfirmationJob($this->order);

    $job->assertNotReleased();
    Mail::assertSent(OrderConfirmationMail::class, 1);
    expect($email->fresh()->status)->toBe('sent')
        ->and($email->fresh()->attempts)->toBe(2);
});

test('a released job sends the email once it returns after the claim lapsed', function () {
    existingEmailRecord($this->order, 'sending', now()->subMinutes(4));
    runConfirmationJob($this->order)->assertReleased(361);

    $this->travel(361)->seconds();
    runConfirmationJob($this->order)->assertNotReleased();

    Mail::assertSent(OrderConfirmationMail::class, 1);
});

test('an email already sent is neither repeated nor retried', function () {
    existingEmailRecord($this->order, 'sent', now()->subMinute());

    $job = runConfirmationJob($this->order);

    $job->assertNotReleased();
    Mail::assertNothingSent();
});

test('an email that failed earlier is tried again at once', function () {
    existingEmailRecord($this->order, 'failed', now()->subMinute());

    runConfirmationJob($this->order)->assertNotReleased();

    Mail::assertSent(OrderConfirmationMail::class, 1);
});
