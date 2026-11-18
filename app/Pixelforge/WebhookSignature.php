<?php

declare(strict_types=1);

namespace App\Pixelforge;

final readonly class WebhookSignature
{
    private function __construct(
        public int $timestamp,
        public string $signature,
    ) {}

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

    public function isValidFor(string $rawBody, string $secret): bool
    {
        $expected = hash_hmac('sha256', "{$this->timestamp}.{$rawBody}", $secret);

        return hash_equals($expected, $this->signature);
    }

    public function isFresh(int $toleranceSeconds, ?int $now = null): bool
    {
        return abs(($now ?? time()) - $this->timestamp) <= $toleranceSeconds;
    }

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
