<?php

declare(strict_types=1);

namespace App\Pixelforge;

use App\Pixelforge\Exceptions\AmbiguousOutcomeException;
use App\Pixelforge\Exceptions\PixelforgeException;
use App\Pixelforge\Exceptions\RejectedException;
use App\Pixelforge\Exceptions\ThrottledException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

final readonly class PixelforgeClient
{
    public function __construct(
        private string $baseUrl,
        private ?string $apiKey,
        private int $timeout = 15,
        private int $connectTimeout = 5,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            baseUrl: (string) config('services.pixelforge.base_url'),
            apiKey: config('services.pixelforge.key'),
            timeout: (int) config('services.pixelforge.timeout', 15),
            connectTimeout: (int) config('services.pixelforge.connect_timeout', 5),
        );
    }

    public function createGeneration(array $payload, string $idempotencyKey): CreateGenerationResult
    {
        $response = $this->send(
            fn (PendingRequest $request): Response => $request
                ->withHeader('Idempotency-Key', $idempotencyKey)
                ->post('/generations', $payload)
        );

        return new CreateGenerationResult(
            generation: ProviderGeneration::fromArray($this->decode($response)),

            wasReplayed: $response->header('Idempotent-Replayed') === 'true',
        );
    }

    public function retrieveGeneration(string $providerId): ?ProviderGeneration
    {
        try {
            $response = $this->send(
                fn (PendingRequest $request): Response => $request->get("/generations/{$providerId}")
            );
        } catch (RejectedException $e) {
            if ($e->errorCode === 'not_found') {
                return null;
            }

            throw $e;
        }

        return ProviderGeneration::fromArray($this->decode($response));
    }

    /**
     * Read-only. There is no lookup by idempotency key -- sending a key the
     * provider never saw would create a generation -- so the only safe way to
     * ask "does this exist?" is to page the list and match the generation id we
     * put in metadata on the way out.
     *
     * @return array{generation: ?ProviderGeneration, scanned: int, exhausted: bool}
     */
    public function findByMetadataGenerationId(string $generationId, int $maxPages = 10): array
    {
        $cursor = null;
        $scanned = 0;

        for ($page = 0; $page < $maxPages; $page++) {
            $query = array_filter([
                'limit' => 100,
                'cursor' => $cursor,
            ], fn ($value): bool => $value !== null);

            $response = $this->send(
                fn (PendingRequest $request): Response => $request->get('/generations', $query)
            );

            $body = $this->decode($response);

            foreach ($body['data'] ?? [] as $candidate) {
                $scanned++;

                if (($candidate['metadata']['generation_id'] ?? null) === $generationId) {
                    return [
                        'generation' => ProviderGeneration::fromArray($candidate),
                        'scanned' => $scanned,
                        'exhausted' => true,
                    ];
                }
            }

            if (($body['has_more'] ?? false) !== true) {
                return ['generation' => null, 'scanned' => $scanned, 'exhausted' => true];
            }

            $cursor = $body['next_cursor'] ?? null;

            if ($cursor === null) {
                return ['generation' => null, 'scanned' => $scanned, 'exhausted' => true];
            }
        }

        // Ran out of pages before running out of history: absence is not proven.
        return ['generation' => null, 'scanned' => $scanned, 'exhausted' => false];
    }

    public function pricing(): PricingCatalog
    {
        $response = $this->send(
            fn (PendingRequest $request): Response => $request->get('/pricing'),
            authenticated: false,
        );

        return PricingCatalog::fromArray($this->decode($response));
    }

    public function usage(string $startDate, string $endDate): UsageReport
    {
        $response = $this->send(
            fn (PendingRequest $request): Response => $request->get('/usage', [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ])
        );

        return UsageReport::fromArray($this->decode($response));
    }

    private function request(bool $authenticated = true): PendingRequest
    {
        $request = Http::baseUrl($this->baseUrl)
            ->acceptJson()
            ->asJson()
            ->timeout($this->timeout)
            ->connectTimeout($this->connectTimeout)

            ->withUserAgent('PixelforgeImageStudio/1.0 (+laravel)');

        return $authenticated && $this->apiKey !== null
            ? $request->withToken($this->apiKey)
            : $request;
    }

    private function send(callable $send, bool $authenticated = true): Response
    {
        try {
            $response = $send($this->request($authenticated));
        } catch (ConnectionException $e) {
            throw new AmbiguousOutcomeException(
                'Could not reach Pixelforge: '.$e->getMessage()
            );
        } catch (Throwable $e) {
            throw new AmbiguousOutcomeException(
                'Unexpected failure calling Pixelforge: '.$e->getMessage()
            );
        }

        if ($response->successful()) {
            return $response;
        }

        throw $this->toException($response);
    }

    private function toException(Response $response): PixelforgeException
    {
        $error = $response->json('error') ?? [];
        $code = isset($error['code']) ? (string) $error['code'] : null;
        $type = isset($error['type']) ? (string) $error['type'] : null;
        $message = isset($error['message'])
            ? (string) $error['message']
            : "Pixelforge returned HTTP {$response->status()}.";
        $status = $response->status();

        $retryAfter = $response->header('Retry-After');
        $retryAfter = is_numeric($retryAfter) ? (int) $retryAfter : null;

        return match (true) {
            $status === 429, $status === 503 => new ThrottledException(
                $message, $code, $type, $status, $retryAfter
            ),

            in_array($status, [400, 401, 403, 404, 409, 422], strict: true) => new RejectedException(
                $message, $code, $type, $status
            ),

            default => new AmbiguousOutcomeException($message, $code, $type, $status),
        };
    }

    private function decode(Response $response): array
    {
        $decoded = $response->json();

        if (! is_array($decoded)) {
            throw new AmbiguousOutcomeException(
                'Pixelforge returned a non-JSON body with HTTP '.$response->status().'.'
            );
        }

        return $decoded;
    }
}
