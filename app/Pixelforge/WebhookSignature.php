<?php

declare(strict_types=1);

namespace App\Pixelforge;

/**
 * Verification of the `Pixelforge-Signature` header.
 *
 * The header is `t=<unix timestamp>,v1=<hex>`, where the signature is the hex
 * HMAC-SHA256 of "<timestamp>.<raw request body>" keyed with the signing secret
 * of the API key that created the generation.
 *
 * Two properties matter and both are easy to get subtly wrong:
 *
 *   - the HMAC must be computed over the *raw* body, before any JSON decoding,
 *     because re-encoding changes bytes and therefore the digest;
 *   - the comparison must be constant time, so a timing side channel cannot be
 *     used to discover a valid signature byte by byte.
 *
 * Old timestamps are rejected so a captured delivery cannot be replayed later.
 */
final readonly class WebhookSignature
{
    private function __construct(
        public int $timestamp,
        public string $signature,
    ) {}

    /** Parse the header, or null if it is missing or malformed. */
    public static function parse(?string $header): ?self
    {
        if ($header === null || $header === '') {
            return null;
        }

        $parts = [];

        foreach (explode(',', $header) as $segment) {
            [$key, $value] = array_pad(explode('=', trim($segment), 2), 2, '');

            if ($key !== '') {
                $parts[$key] = $value;
            }
        }

        if (! isset($parts['t'], $parts['v1']) || ! ctype_digit($parts['t']) || $parts['v1'] === '') {
            return null;
        }

        return new self((int) $parts['t'], $parts['v1']);
    }

    /**
     * Whether this signature is valid for the given raw body and secret.
     *
     * @param  string  $rawBody  the request body exactly as received
     */
    public function isValidFor(string $rawBody, string $secret): bool
    {
        $expected = hash_hmac('sha256', "{$this->timestamp}.{$rawBody}", $secret);

        // hash_equals, not ===, to avoid leaking how much of the digest matched.
        return hash_equals($expected, $this->signature);
    }

    /** Whether the signature is recent enough to not be a replay. */
    public function isFresh(int $toleranceSeconds, ?int $now = null): bool
    {
        return abs(($now ?? time()) - $this->timestamp) <= $toleranceSeconds;
    }

    /** Build a signature header, used by tests and local replay tooling. */
    public static function sign(string $rawBody, string $secret, ?int $timestamp = null): string
    {
        $timestamp ??= time();

        return sprintf(
            't=%d,v1=%s',
            $timestamp,
            hash_hmac('sha256', "{$timestamp}.{$rawBody}", $secret)
        );
    }
}
