<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\StripeBillingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Stripe\Exception\ApiErrorException;

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
        if (isset($product['credits'])) {
            return back()->withErrors(['billing' => 'クレジットは購入できません。有料プランをご利用ください。']);
        }

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

    public function changePlan(Request $request, StripeBillingService $billingService): RedirectResponse
    {
        $validated = $request->validate([
            'plan' => ['required', 'string', 'in:lite,premium'],
        ]);
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        if (! is_string(config('services.stripe.secret')) || config('services.stripe.secret') === '') {
            return back()->withErrors(['billing' => '決済の準備が完了していません。時間をおいて再度お試しください。']);
        }

        try {
            $result = $billingService->changePlan($user, $validated['plan']);
        } catch (ApiErrorException $exception) {
            report($exception);

            return back()->withErrors(['billing' => 'Stripe上の契約を確認できませんでした。時間をおいて再度お試しください。']);
        }

        $message = match ($result) {
            'upgraded' => 'プレミアムプランへ切り替えました。日割り分は今回の請求に反映されています。',
            'upgrade_pending' => 'プレミアムへの変更を受け付けました。決済完了後にプランへ反映されます。',
            'downgrade_scheduled' => 'ライトプランへの変更を予約しました。現在の契約期間終了後に切り替わります。',
            default => throw new RuntimeException('Unknown subscription plan change result.'),
        };

        return to_route('account.settings.edit')->with('status', $message);
    }

    public function cancelPlanChange(Request $request, StripeBillingService $billingService): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        if (! is_string(config('services.stripe.secret')) || config('services.stripe.secret') === '') {
            return back()->withErrors(['billing' => '決済の準備が完了していません。時間をおいて再度お試しください。']);
        }

        $billingService->cancelScheduledPlanChange($user);

        return to_route('account.settings.edit')->with('status', '予約していたプラン変更を取り消しました。');
    }
}
