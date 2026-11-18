<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\IngestWebhookEvent;
use App\Pixelforge\WebhookSignature;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class PixelforgeWebhookController extends Controller
{
    public function __invoke(Request $request, IngestWebhookEvent $ingest): Response
    {
        $rawBody = $request->getContent();

        $secret = (string) config('services.pixelforge.webhook_secret');

        if ($secret === '') {
            Log::error('A Pixelforge webhook arrived but no signing secret is configured.');

            return response('Webhook signing secret is not configured.', 500);
        }

        $signature = WebhookSignature::parse($request->header('Pixelforge-Signature'));

        if ($signature === null || ! $signature->isValidFor($rawBody, $secret)) {
            Log::warning('Rejected a Pixelforge webhook with an invalid signature.', [
                'event_id' => $request->header('Pixelforge-Event-Id'),
                'has_header' => $signature !== null,
            ]);

            return response('Invalid signature.', 400);
        }

        if (! $signature->isFresh((int) config('services.pixelforge.webhook_tolerance_seconds', 300))) {
            Log::warning('Rejected a Pixelforge webhook with a stale timestamp.', [
                'event_id' => $request->header('Pixelforge-Event-Id'),
                'signature_timestamp' => $signature->timestamp,
            ]);

            return response('Stale signature timestamp.', 400);
        }

        $payload = json_decode($rawBody, true);
        $eventId = $payload['id'] ?? $request->header('Pixelforge-Event-Id');

        if (! is_array($payload) || ! is_string($eventId) || $eventId === '') {
            return response('Unreadable event payload.', 400);
        }

        $ingest(
            payload: $payload,
            eventId: $eventId,
            eventType: (string) ($payload['type'] ?? $request->header('Pixelforge-Event-Type', 'unknown')),
            signatureTimestamp: $signature->timestamp,
            deliveryAttempt: (int) $request->header('Pixelforge-Delivery-Attempt', 1),
            rawBody: $rawBody,
        );

        return response()->noContent();
    }
}
