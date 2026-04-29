<?php

namespace App\Http\Controllers;

use App\Services\SubscriptionLifecycleService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use RuntimeException;
use Stripe\Event;
use Stripe\Webhook;
use UnexpectedValueException;

class StripeWebhookController extends Controller
{
    public function __invoke(Request $request, SubscriptionLifecycleService $subscriptions): Response
    {
        $secret = trim((string) config('services.stripe.webhook_secret', ''));
        $payload = (string) $request->getContent();
        $signature = (string) $request->header('Stripe-Signature', '');

        try {
            $event = $secret !== ''
                ? Webhook::constructEvent($payload, $signature, $secret)
                : Event::constructFrom($request->json()->all());
        } catch (UnexpectedValueException|\Stripe\Exception\SignatureVerificationException $exception) {
            throw new RuntimeException('Invalid Stripe webhook payload.', previous: $exception);
        }

        $subscriptions->handleStripeEvent($event);

        return response()->noContent();
    }
}
