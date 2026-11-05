<?php

namespace App\Jobs;

use App\Mail\OrderConfirmationMail;
use App\Models\Order;
use App\Models\OrderEmail;
use App\Services\CheckoutService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendOrderConfirmationEmail implements ShouldQueue, \Illuminate\Contracts\Queue\ShouldBeUnique
{
    use Queueable;

    public int $uniqueFor = 600;

    public int $tries = 3;

    /**
     * @var array<int, int>
     */
    public array $backoff = [30, 120];

    public function __construct(public int $orderId) {}

    public function uniqueId(): string
    {
        return 'order-confirmation-'.$this->orderId;
    }

    public function handle(CheckoutService $checkoutService): void
    {
        $claimed = DB::transaction(function (): bool {
            $email = OrderEmail::query()
                ->where('order_id', $this->orderId)
                ->where('email_type', 'order_confirmation')
                ->lockForUpdate()
                ->first();

            if ($email === null) {
                $email = OrderEmail::query()->create([
                    'order_id' => $this->orderId,
                    'email_type' => 'order_confirmation',
                    'status' => 'pending',
                    'attempts' => 0,
                ]);
            }

            if ($email->status === 'sent') {
                return false;
            }
            if ($email->status === 'sending' && $email->updated_at?->isAfter(now()->subMinutes(10))) {
                return false;
            }

            $email->status = 'sending';
            $email->attempts++;
            $email->failure_reason = null;
            $email->save();

            return true;
        });

        if (! $claimed) {
            return;
        }

        $order = Order::query()->with('items')->findOrFail($this->orderId);
        $guestOrderUrl = $order->guest_access_expires_at?->isFuture()
            ? $checkoutService->guestOrderUrl($order)
            : null;

        try {
            Mail::to($order->customer_email)->send(new OrderConfirmationMail($order, $guestOrderUrl));
        } catch (Throwable $exception) {
            OrderEmail::query()
                ->where('order_id', $order->id)
                ->where('email_type', 'order_confirmation')
                ->update([
                    'status' => 'failed',
                    'failure_reason' => $exception::class,
                    'updated_at' => now(),
                ]);
            report($exception);

            throw $exception;
        }

        OrderEmail::query()
            ->where('order_id', $order->id)
            ->where('email_type', 'order_confirmation')
            ->update([
                'status' => 'sent',
                'sent_at' => now(),
                'updated_at' => now(),
            ]);
    }
}
