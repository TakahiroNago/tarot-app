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
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Sends one email per order and type, recorded in ec_order_emails so retries cannot repeat it.
 */
abstract class SendOrderEmail implements ShouldBeUnique, ShouldQueue
{
    use InteractsWithQueue, Queueable;

    private const CLAIM_MINUTES = 10;

    private const CLAIMED = 0;

    private const ALREADY_SENT = -1;

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

        if (! $this->shouldSend($order)) {
            return;
        }

        $claim = $this->claim();
        if ($claim === self::ALREADY_SENT) {
            return;
        }
        if ($claim !== self::CLAIMED) {
            $this->release($claim);

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

    /**
     * @return int The claim result, or how many seconds another attempt's claim has left. A worker
     *             that died mid-send leaves that claim behind, so this job must come back after it
     *             lapses rather than end as if the email had gone out.
     */
    private function claim(): int
    {
        return DB::transaction(function (): int {
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
                return self::ALREADY_SENT;
            }
            if ($email->status === 'sending' && $email->updated_at?->isAfter(now()->subMinutes(self::CLAIM_MINUTES))) {
                return (int) ceil(now()->diffInSeconds($email->updated_at->copy()->addMinutes(self::CLAIM_MINUTES))) + 1;
            }

            $email->status = 'sending';
            $email->attempts++;
            $email->failure_reason = null;
            $email->save();

            return self::CLAIMED;
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
