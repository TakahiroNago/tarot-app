<?php

namespace App\Http\Controllers;

use App\Services\EcommercePaymentWebhookService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Exception\UnexpectedValueException;
use Stripe\Webhook;

class EcommerceStripeWebhookController extends Controller
{
    public function __invoke(Request $request, EcommercePaymentWebhookService $webhookService): Response
    {
        $webhookSecret = config('services.stripe.ecommerce_webhook_secret');
        if (! is_string($webhookSecret) || $webhookSecret === '') {
            abort(503, 'Ecommerce Stripe webhook is not configured.');
        }

        try {
            $event = Webhook::constructEvent(
                $request->getContent(),
                (string) $request->header('Stripe-Signature'),
                $webhookSecret,
            );
        } catch (SignatureVerificationException|UnexpectedValueException) {
            abort(400, 'Invalid ecommerce Stripe webhook signature or payload.');
        }

        $webhookService->handle($event);

        return response('Ecommerce webhook received.', 200);
    }
}
