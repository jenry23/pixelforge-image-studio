<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\IngestWebhookEvent;
use App\Pixelforge\WebhookSignature;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Receives Pixelforge webhooks.
 *
 * Anyone can POST here, so nothing is trusted until the signature verifies
 * against the raw body. Work is kept small and synchronous: the whole handler is
 * a signature check, one insert and one short transaction, which stays well
 * inside the provider's 10-second acknowledgement budget while guaranteeing the
 * event is durably recorded before we answer 2xx.
 *
 * Queueing the work instead would let us answer faster but would mean
 * acknowledging an event before it was safely ours -- the opposite of what we
 * want, since a lost queue job after a 2xx is an event Pixelforge will never
 * resend.
 */
class PixelforgeWebhookController extends Controller
{
    public function __invoke(Request $request, IngestWebhookEvent $ingest): Response
    {
        // The raw body, before any decoding: re-encoding JSON changes bytes and
        // would invalidate the HMAC.
        $rawBody = $request->getContent();

        $secret = (string) config('services.pixelforge.webhook_secret');

        if ($secret === '') {
            // Refusing is safer than accepting unverified events; a non-2xx also
            // makes the misconfiguration visible in the Pixelforge dashboard.
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
            // A correctly signed but stale delivery is a replay attempt. Each
            // genuine attempt is signed with a fresh timestamp, so a real retry
            // is never stale.
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

        // Only now, with the event committed, do we acknowledge it.
        return response()->noContent();
    }
}
