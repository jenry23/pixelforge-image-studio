<?php

declare(strict_types=1);

namespace App\Pixelforge;

use App\Pixelforge\Exceptions\PixelforgeException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Supplies the published pricing rules, cached, with a committed fallback.
 *
 * The provider tells us to read rates from the pricing endpoint rather than
 * hardcoding them, but the estimate UI must not break if that endpoint is
 * briefly unreachable -- so a last-known-good snapshot ships with the repo.
 *
 * The fallback is only ever used for *estimates*. Settlement always costs from
 * the rate snapshot the provider returned on the generation itself, so a stale
 * fallback can make a quote slightly wrong but can never make a charge wrong.
 */
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
        // Memoised per request as well as cached, so one page render that quotes
        // several variants does not hit the cache driver repeatedly.
        return $this->resolved ??= PricingCatalog::fromArray($this->payload());
    }

    /** Drop the cache, e.g. after a known price change. */
    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
        $this->resolved = null;
    }

    /**
     * @return array<string, mixed>
     */
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

    /**
     * @return array<string, mixed>
     */
    private function fallback(): array
    {
        $path = resource_path('pixelforge/pricing-fallback.json');

        return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }
}
