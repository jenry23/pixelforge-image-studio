<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Generation;
use App\Models\WebhookEvent;
use App\Pixelforge\ProviderGeneration;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use Throwable;

final readonly class IngestWebhookEvent
{
    public function __construct(private ApplyProviderState $applyProviderState) {}

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
            return [
                WebhookEvent::query()->where('event_id', $eventId)->firstOrFail(),
                true,
            ];
        }
    }

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

    private function apply(WebhookEvent $event): void
    {
        if ($event->event_type === 'ping') {
            $event->forceFill(['applied_at' => now()])->save();

            return;
        }

        if ($event->generation_id === null) {
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
