<?php

namespace App\Http\Controllers;

use App\Services\StripeBillingService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Exception\UnexpectedValueException;
use Stripe\Webhook;

class StripeWebhookController extends Controller
{
    public function __invoke(Request $request, StripeBillingService $billingService): Response
    {
        $webhookSecret = config('services.stripe.webhook_secret');
        if (! is_string($webhookSecret) || $webhookSecret === '') {
            abort(503, 'Stripe webhook is not configured.');
        }

        try {
            $event = Webhook::constructEvent(
                $request->getContent(),
                (string) $request->header('Stripe-Signature'),
                $webhookSecret,
            );
        } catch (SignatureVerificationException|UnexpectedValueException) {
            abort(400, 'Invalid Stripe webhook signature or payload.');
        }

        $billingService->handleWebhook($event);

        return response('Webhook received.', 200);
    }
}
