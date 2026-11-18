<?php

declare(strict_types=1);

namespace App\Pixelforge;

final readonly class CreateGenerationResult
{
    public function __construct(
        public ProviderGeneration $generation,
        public bool $wasReplayed,
    ) {}
}
