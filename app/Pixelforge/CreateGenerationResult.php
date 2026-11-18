<?php

declare(strict_types=1);

namespace App\Pixelforge;

/**
 * The outcome of a create call, including whether the provider served it from
 * our idempotency key rather than creating something new.
 *
 * `wasReplayed` is the proof that a retry after an ambiguous timeout adopted the
 * existing generation instead of starting (and billing) a second one.
 */
final readonly class CreateGenerationResult
{
    public function __construct(
        public ProviderGeneration $generation,
        public bool $wasReplayed,
    ) {}
}
