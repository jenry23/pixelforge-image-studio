<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Generation;
use App\Models\WebhookEvent;
use App\Pixelforge\ProviderGeneration;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Records a verified webhook delivery and applies it, in that order.
 *
 * The ordering is the point. The event row is inserted and committed *before*
 * the state transition is attempted, which gives two guarantees:
 *
 *   - `UNIQUE (event_id)` means a redelivery cannot create a second record, so
 *     an event delivered twice can never be billed twice -- the database
 *     enforces that, not an application check that could race;
 *   - if the process dies between recording and applying, the event is still
 *     durably ours with `applied_at` null, and the reconciler finishes the work.
 *     Nothing is lost, so acknowledging is always safe.
 *
 * A redelivery of an event we recorded but never applied is therefore useful
 * rather than wasted: we retry the apply, which means Pixelforge's own retry
 * schedule helps us recover.
 */
final readonly class IngestWebhookEvent
{
    public function __construct(private ApplyProviderState $applyProviderState) {}

    /**
     * @param  array<string, mixed>  $payload  the decoded event body
     */
    public function __invoke(
        array $payload,
        string $eventId,
        string $eventType,
        int $signatureTimestamp,
        int $deliveryAttempt,
        string $rawBody,
    ): WebhookEvent {
        [$event, $isDuplicate] = $this->record(
            $payload, $eventId, $eventType, $signatureTimestamp, $deliveryAttempt
        );

        if ($isDuplicate && $event->wasApplied()) {
            // Already recorded and already acted on: a pure no-op.
            return $event;
        }

        if ($isDuplicate) {
            Log::info('Redelivery of a webhook event we recorded but had not applied; retrying.', [
                'event_id' => $eventId,
                'delivery_attempt' => $deliveryAttempt,
            ]);
        }

        $this->apply($event);

        return $event->refresh();
    }

    /**
     * Insert the event, or return the existing row if this id has been seen.
     *
     * @param  array<string, mixed>  $payload
     * @return array{0: WebhookEvent, 1: bool}
     */
    private function record(
        array $payload,
        string $eventId,
        string $eventType,
        int $signatureTimestamp,
        int $deliveryAttempt,
    ): array {
        $object = $payload['data']['object'] ?? null;

        try {
            $event = WebhookEvent::query()->create([
                'event_id' => $eventId,
                'event_type' => $eventType,
                'provider_generation_id' => is_array($object) && isset($object['id'])
                    ? (string) $object['id']
                    : null,
                'generation_id' => $this->resolveGenerationId($object),
                'payload' => $payload,
                'signature_timestamp' => $signatureTimestamp,
                'delivery_attempt' => $deliveryAttempt,
                'received_at' => now(),
            ]);

            return [$event, false];
        } catch (UniqueConstraintViolationException) {
            // The deduplication invariant doing its job.
            return [
                WebhookEvent::query()->where('event_id', $eventId)->firstOrFail(),
                true,
            ];
        }
    }

    /**
     * Find the local generation this event belongs to.
     *
     * `metadata.generation_id` is tried first because it is the only thing that
     * works when a webhook overtakes the create response: at that moment we have
     * no provider id stored, but we did send our own id in metadata, which the
     * provider returns "in every webhook, so you can match them to your own
     * records".
     *
     * @param  mixed  $object  the `data.object` from the payload
     */
    private function resolveGenerationId(mixed $object): ?string
    {
        if (! is_array($object)) {
            return null;
        }

        $localId = $object['metadata']['generation_id'] ?? null;

        if (is_string($localId) && Generation::query()->whereKey($localId)->exists()) {
            return $localId;
        }

        if (isset($object['id'])) {
            return Generation::query()
                ->where('provider_id', (string) $object['id'])
                ->value('id');
        }

        return null;
    }

    /** Apply the event's state transition and record what it did. */
    private function apply(WebhookEvent $event): void
    {
        // `ping` is a dashboard test event with no generation attached.
        if ($event->event_type === 'ping') {
            $event->forceFill(['applied_at' => now()])->save();

            return;
        }

        if ($event->generation_id === null) {
            // Validly signed but unmatchable, which can happen if an event for a
            // generation created by another environment sharing this account
            // reaches us. Kept, not discarded, and left for the reconciler.
            $event->forceFill(['ignored_reason' => 'unmatched'])->save();

            return;
        }

        $generation = Generation::query()->find($event->generation_id);
        $providerGeneration = $this->providerGeneration($event);

        if ($generation === null || $providerGeneration === null) {
            $event->forceFill(['ignored_reason' => 'unmatched'])->save();

            return;
        }

        $outcome = ($this->applyProviderState)($generation, $providerGeneration);

        $event->forceFill($outcome->applied
            ? ['applied_at' => now(), 'ignored_reason' => null]
            // Recorded, acknowledged, and deliberately changed nothing -- a
            // duplicate or out-of-order delivery, kept auditable.
            : ['applied_at' => null, 'ignored_reason' => $outcome->reason])
            ->save();
    }

    private function providerGeneration(WebhookEvent $event): ?ProviderGeneration
    {
        try {
            return $event->providerGeneration();
        } catch (Throwable $e) {
            Log::error('Could not read the generation object from a webhook event.', [
                'event_id' => $event->event_id,
                'reason' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
