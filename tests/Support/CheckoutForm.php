<?php

namespace Tests\Support;

use App\Models\Cart;
use App\Services\CheckoutService;
use Illuminate\Validation\ValidationException;

/**
 * Completes a checkout form the way the confirmation page does, for tests of what happens after it.
 */
final class CheckoutForm
{
    /**
     * @param  array<string, mixed>  $form
     * @return array<string, mixed>
     */
    public static function confirmed(array $form, ?Cart $cart = null): array
    {
        $cart ??= Cart::query()->where('user_id', auth()->id())->first();

        try {
            $hash = app(CheckoutService::class)->quote($cart, $form)['hash'];
        } catch (ValidationException) {
            $hash = str_repeat('0', 64);
        }

        return [...$form, 'quote_hash' => $hash, 'terms_accepted' => '1'];
    }
}
