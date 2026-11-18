<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Generation;
use Carbon\CarbonImmutable;
use Database\Factories\GenerationFactory;
use Illuminate\Support\Str;

/**
 * Builds Pixelforge-shaped payloads for tests.
 *
 * Deliberately mirrors the real wire format captured from the live API --
 * including `rates` as decimal strings and `metadata.generation_id` -- so tests
 * exercise the same parsing the provider actually triggers.
 */
final class ProviderPayload
{
    /**
     * A generation object as the provider returns it.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function generation(
        Generation $generation,
        string $status = 'succeeded',
        array $overrides = [],
    ): array {
        $providerId = $generation->provider_id ?? 'gen_'.strtoupper(Str::random(26));
        $createdAt = CarbonImmutable::now()->subSeconds(20);

        $base = [
            'id' => $providerId,
            'object' => 'generation',
            'status' => $status,
            'prompt' => $generation->prompt,
            'size' => $generation->size->value,
            'quality' => $generation->quality->value,
            'reference_images' => $generation->reference_images ?? [],
            // The match key that makes an early webhook resolvable.
            'metadata' => ['generation_id' => (string) $generation->getKey()],
            'image_url' => null,
            'image_url_expires_at' => null,
            'usage' => null,
            'rates' => GenerationFactory::RATES,
            'error' => null,
            'created_at' => $createdAt->toIso8601ZuluString(),
            'started_at' => null,
            'completed_at' => null,
        ];

        $base = match ($status) {
            'processing' => [...$base, 'started_at' => $createdAt->addSeconds(5)->toIso8601ZuluString()],
            'succeeded' => [
                ...$base,
                'started_at' => $createdAt->addSeconds(5)->toIso8601ZuluString(),
                'completed_at' => $createdAt->addSeconds(18)->toIso8601ZuluString(),
                'image_url' => "https://pixelforge.test/images/{$providerId}.png?signature=abc",
                'image_url_expires_at' => $createdAt->addDay()->toIso8601ZuluString(),
                // The exact usage we verified live against GET /v1/usage.
                'usage' => [
                    'input_text_tokens' => 9,
                    'input_image_tokens' => 0,
                    'output_tokens' => 280,
                    'reasoning_tokens' => 37,
                    'total_tokens' => 326,
                ],
            ],
            'failed' => [
                ...$base,
                'started_at' => $createdAt->addSeconds(5)->toIso8601ZuluString(),
                'completed_at' => $createdAt->addSeconds(12)->toIso8601ZuluString(),
                'error' => [
                    'code' => 'generation_failed',
                    'message' => 'The model could not produce an image for this prompt.',
                ],
            ],
            default => $base,
        };

        return [...$base, ...$overrides];
    }

    /**
     * An event envelope wrapping a generation object.
     *
     * @param  array<string, mixed>  $generationPayload
     * @return array<string, mixed>
     */
    public static function event(
        string $type,
        array $generationPayload,
        ?string $eventId = null,
    ): array {
        return [
            'id' => $eventId ?? 'evt_'.strtoupper(Str::random(26)),
            'object' => 'event',
            'type' => $type,
            'created_at' => CarbonImmutable::now()->toIso8601ZuluString(),
            'data' => ['object' => $generationPayload],
        ];
    }

    /**
     * The event for a given status, named as Pixelforge names it.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function eventFor(
        Generation $generation,
        string $status,
        ?string $eventId = null,
        array $overrides = [],
    ): array {
        return self::event(
            type: "generation.{$status}",
            generationPayload: self::generation($generation, $status, $overrides),
            eventId: $eventId,
        );
    }
}
