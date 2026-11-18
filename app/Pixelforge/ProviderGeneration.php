<?php

declare(strict_types=1);

namespace App\Pixelforge;

use App\Enums\GenerationStatus;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * A typed reading of the provider's generation object.
 *
 * The same shape arrives from three places -- the create response, a webhook's
 * `data.object`, and GET /v1/generations/{id} -- so parsing it once here means
 * all three paths feed identical data into the single state-transition action.
 */
final readonly class ProviderGeneration
{
    private function __construct(
        public string $id,
        public GenerationStatus $status,
        public ?TokenUsage $usage,
        public Rates $rates,
        public ?string $imageUrl,
        public ?CarbonImmutable $imageUrlExpiresAt,
        public ?string $errorCode,
        public ?string $errorMessage,
        public ?CarbonImmutable $createdAt,
        public ?CarbonImmutable $startedAt,
        public ?CarbonImmutable $completedAt,
        /** @var array<string, string> */
        public array $metadata,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        foreach (['id', 'status', 'rates'] as $required) {
            if (! array_key_exists($required, $payload)) {
                throw new InvalidArgumentException("Provider generation is missing [{$required}].");
            }
        }

        $usage = $payload['usage'] ?? null;
        $error = $payload['error'] ?? null;

        return new self(
            id: (string) $payload['id'],
            status: GenerationStatus::fromProvider((string) $payload['status']),
            usage: is_array($usage) ? TokenUsage::fromArray($usage) : null,
            rates: Rates::fromArray($payload['rates']),
            imageUrl: isset($payload['image_url']) ? (string) $payload['image_url'] : null,
            imageUrlExpiresAt: self::time($payload['image_url_expires_at'] ?? null),
            errorCode: is_array($error) && isset($error['code']) ? (string) $error['code'] : null,
            errorMessage: is_array($error) && isset($error['message']) ? (string) $error['message'] : null,
            createdAt: self::time($payload['created_at'] ?? null),
            startedAt: self::time($payload['started_at'] ?? null),
            completedAt: self::time($payload['completed_at'] ?? null),
            metadata: array_map('strval', $payload['metadata'] ?? []),
        );
    }

    /**
     * Our own generation id, which we send in `metadata` on every create.
     *
     * The docs state metadata is returned "in every webhook, so you can match
     * them to your own records" -- that is what lets a webhook arriving *before*
     * the create response has been saved still find its local row.
     */
    public function localGenerationId(): ?string
    {
        return $this->metadata['generation_id'] ?? null;
    }

    /**
     * The timestamp to order this reading by, for the monotonic guard.
     *
     * Completion time for a final state, otherwise when processing started, and
     * creation time as a last resort.
     */
    public function eventAt(): ?CarbonImmutable
    {
        return $this->completedAt ?? $this->startedAt ?? $this->createdAt;
    }

    public function isBillable(): bool
    {
        return $this->status === GenerationStatus::Succeeded && $this->usage !== null;
    }

    private static function time(mixed $value): ?CarbonImmutable
    {
        return $value === null || $value === ''
            ? null
            : CarbonImmutable::parse((string) $value)->utc();
    }
}
