<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\OrderEmail;
use App\Services\CheckoutService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Sends one email per order and type, recorded in ec_order_emails so retries cannot repeat it.
 */
abstract class SendOrderEmail implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 600;

    public int $tries = 3;

    /**
     * @var array<int, int>
     */
    public array $backoff = [30, 120];

    public function __construct(public int $orderId) {}

    abstract protected function emailType(): string;

    abstract protected function shouldSend(Order $order): bool;

    abstract protected function mailable(Order $order, ?string $guestOrderUrl): Mailable;

    protected function recipient(Order $order): string
    {
        return $order->customer_email;
    }

    public function uniqueId(): string
    {
        return $this->emailType().'-'.$this->orderId;
    }

    public function handle(CheckoutService $checkoutService): void
    {
        $order = Order::query()->with('items')->findOrFail($this->orderId);

        if (! $this->shouldSend($order) || ! $this->claim()) {
            return;
        }

        $guestOrderUrl = $order->guest_access_expires_at?->isFuture()
            ? $checkoutService->guestOrderUrl($order)
            : null;

        try {
            Mail::to($this->recipient($order))->send($this->mailable($order, $guestOrderUrl));
        } catch (Throwable $exception) {
            $this->emailRecord()->update([
                'status' => 'failed',
                'failure_reason' => $exception::class,
                'updated_at' => now(),
            ]);
            report($exception);

            throw $exception;
        }

        $this->emailRecord()->update([
            'status' => 'sent',
            'sent_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function claim(): bool
    {
        return DB::transaction(function (): bool {
            $email = $this->emailRecord()->lockForUpdate()->first();

            if ($email === null) {
                $email = OrderEmail::query()->create([
                    'order_id' => $this->orderId,
                    'email_type' => $this->emailType(),
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
    }

    /**
     * @return Builder<OrderEmail>
     */
    private function emailRecord(): Builder
    {
        return OrderEmail::query()
            ->where('order_id', $this->orderId)
            ->where('email_type', $this->emailType());
    }
}
