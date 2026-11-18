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

        $signatureHeader = $request->header('Pixelforge-Signature');
        $signature = WebhookSignature::parse($signatureHeader);

        if ($signature === null || ! $signature->isValidFor($rawBody, $secret)) {
            Log::warning('Rejected a Pixelforge webhook with an invalid signature.', [
                'header_present' => $signatureHeader !== null && $signatureHeader !== '',
                'header_parsed' => $signature !== null,
            ]);

            return response('Invalid signature.', 400);
        }

        if (! $signature->isFresh((int) config('services.pixelforge.webhook_tolerance_seconds', 300))) {
            Log::warning('Rejected a Pixelforge webhook with a stale timestamp.', [
                'signature_timestamp' => $signature->timestamp,
            ]);

            return response('Stale signature timestamp.', 400);
        }

        $payload = json_decode($rawBody, true);

        if (! is_array($payload) || array_is_list($payload)) {
            return response('Event payload must be a JSON object.', 400);
        }

        $eventId = $payload['id'] ?? null;
        $eventType = $payload['type'] ?? null;

        if (! is_string($eventId) || trim($eventId) === '') {
            return response('Event payload is missing a usable id.', 400);
        }

        if (! is_string($eventType) || trim($eventType) === '') {
            return response('Event payload is missing a usable type.', 400);
        }

        $ingest(
            payload: $payload,
            eventId: $eventId,
            eventType: $eventType,
            signatureTimestamp: $signature->timestamp,
            deliveryAttempt: (int) $request->header('Pixelforge-Delivery-Attempt', 1),
            rawBody: $rawBody,
        );

        return response()->noContent();
    }
}
