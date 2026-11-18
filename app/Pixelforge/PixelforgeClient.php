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

/**
 * The only place that talks to Pixelforge.
 *
 * Its job is to turn HTTP outcomes into the three failure meanings the rest of
 * the app cares about -- throttled (wait and retry), rejected (never going to
 * work), ambiguous (we genuinely do not know whether a billable generation was
 * created) -- so no caller ever branches on a status code.
 */
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

    /**
     * Create a generation.
     *
     * The idempotency key is required rather than optional: every create in this
     * app is retryable, and a retry without the key could produce a second
     * billable generation. Making it a required argument means that mistake
     * cannot be made by omission.
     *
     * @param  array<string, mixed>  $payload
     */
    public function createGeneration(array $payload, string $idempotencyKey): CreateGenerationResult
    {
        $response = $this->send(
            fn (PendingRequest $request): Response => $request
                ->withHeader('Idempotency-Key', $idempotencyKey)
                ->post('/generations', $payload)
        );

        return new CreateGenerationResult(
            generation: ProviderGeneration::fromArray($this->decode($response)),
            // Proof a retry adopted the original generation instead of creating one.
            wasReplayed: $response->header('Idempotent-Replayed') === 'true',
        );
    }

    /**
     * Fetch a generation's current state. Returns null if the provider has no
     * such generation, which for us means "nothing was ever created".
     */
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

    /** Published rates and token tables. Needs no authentication. */
    public function pricing(): PricingCatalog
    {
        $response = $this->send(
            fn (PendingRequest $request): Response => $request->get('/pricing'),
            authenticated: false,
        );

        return PricingCatalog::fromArray($this->decode($response));
    }

    /**
     * The provider's billing record for this API key, per UTC day.
     *
     * Dates are inclusive and must be UTC Y-m-d, matching how the provider
     * attributes a generation to the day it completed.
     */
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

    // -- Plumbing ------------------------------------------------------------

    private function request(bool $authenticated = true): PendingRequest
    {
        $request = Http::baseUrl($this->baseUrl)
            ->acceptJson()
            ->asJson()
            ->timeout($this->timeout)
            ->connectTimeout($this->connectTimeout)
            // Retries are owned by the queued job, which can persist its budget
            // and honour Retry-After. Retrying inside the client would hide
            // attempts from that budget and from the per-minute rate limit.
            ->withUserAgent('PixelforgeImageStudio/1.0 (+laravel)');

        return $authenticated && $this->apiKey !== null
            ? $request->withToken($this->apiKey)
            : $request;
    }

    /**
     * @param  callable(PendingRequest): Response  $send
     *
     * @throws PixelforgeException
     */
    private function send(callable $send, bool $authenticated = true): Response
    {
        try {
            $response = $send($this->request($authenticated));
        } catch (ConnectionException $e) {
            // Timeout or connection failure: the request may have been processed.
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
            // Nothing created, nothing billed; wait the stated interval.
            $status === 429, $status === 503 => new ThrottledException(
                $message, $code, $type, $status, $retryAfter
            ),

            // Definitive refusals: retrying cannot change the answer.
            in_array($status, [400, 401, 403, 404, 409, 422], strict: true) => new RejectedException(
                $message, $code, $type, $status
            ),

            // Any other 5xx: the provider may have acted before failing.
            default => new AmbiguousOutcomeException($message, $code, $type, $status),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Response $response): array
    {
        $decoded = $response->json();

        if (! is_array($decoded)) {
            // A 2xx we cannot read is not a success we can act on.
            throw new AmbiguousOutcomeException(
                'Pixelforge returned a non-JSON body with HTTP '.$response->status().'.'
            );
        }

        return $decoded;
    }
}
