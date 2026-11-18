<?php

declare(strict_types=1);

namespace App\Pixelforge;

use App\Pixelforge\Exceptions\PixelforgeException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

final class PricingCatalogRepository
{
    private const string CACHE_KEY = 'pixelforge:pricing';

    private ?PricingCatalog $resolved = null;

    public function __construct(
        private readonly PixelforgeClient $client,
        private readonly int $ttlSeconds = 3600,
    ) {}

    public function get(): PricingCatalog
    {
        return $this->resolved ??= PricingCatalog::fromArray($this->payload());
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
        $this->resolved = null;
    }

    private function payload(): array
    {
        $cached = Cache::get(self::CACHE_KEY);

        if (is_array($cached)) {
            return $cached;
        }

        try {
            $payload = $this->client->pricing()->toPayload();

            Cache::put(self::CACHE_KEY, $payload, $this->ttlSeconds);

            return $payload;
        } catch (PixelforgeException|Throwable $e) {
            Log::warning('Falling back to the bundled Pixelforge pricing snapshot.', [
                'reason' => $e->getMessage(),
            ]);

            return $this->fallback();
        }
    }

    private function fallback(): array
    {
        $path = resource_path('pixelforge/pricing-fallback.json');

        return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }
}
