<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\StripeBillingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class BillingController extends Controller
{
    public function checkout(Request $request, StripeBillingService $billingService): RedirectResponse
    {
        $validated = $request->validate([
            'product' => ['required', 'string', 'in:credits_10,credits_50,credits_100,lite,premium'],
        ]);
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $product = $billingService->products()[$validated['product']];
        if (! is_string($product['price_id']) || $product['price_id'] === '' || ! config('services.stripe.secret')) {
            return back()->withErrors(['billing' => '決済の準備が完了していません。時間をおいて再度お試しください。']);
        }

        if (isset($product['plan']) && ($user->plan !== User::PLAN_FREE || $user->stripe_subscription_id !== null)) {
            if ($user->stripe_customer_id !== null) {
                return redirect()->away($billingService->portalUrl($user));
            }

            return back()->withErrors(['billing' => '現在のプランを変更できません。サポートにお問い合わせください。']);
        }

        return redirect()->away($billingService->checkoutUrl($user, $validated['product']));
    }

    public function portal(Request $request, StripeBillingService $billingService): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        if ($user->stripe_customer_id === null) {
            return back()->withErrors(['billing' => 'Stripeのお支払い情報が見つかりません。']);
        }

        if (! config('services.stripe.secret')) {
            return back()->withErrors(['billing' => '決済の準備が完了していません。時間をおいて再度お試しください。']);
        }

        return redirect()->away($billingService->portalUrl($user));
    }
}
