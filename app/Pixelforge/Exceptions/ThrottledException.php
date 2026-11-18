<?php

declare(strict_types=1);

namespace App\Pixelforge\Exceptions;

/**
 * 429 rate_limit_exceeded / concurrency_limit_exceeded / daily_limit_exceeded,
 * or 503 overloaded.
 *
 * All four say the same thing for our purposes: nothing was created, nothing was
 * billed, and we should wait the number of seconds the provider named before
 * trying again.
 */
final class ThrottledException extends PixelforgeException
{
    public function __construct(
        string $message,
        ?string $errorCode = null,
        ?string $type = null,
        ?int $status = null,
        private readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message, $errorCode, $type, $status);
    }

    public function isRetryable(): bool
    {
        return true;
    }

    public function retryAfterSeconds(): ?int
    {
        return $this->retryAfter;
    }

    /** The daily quota will not clear within any sane retry window. */
    public function isDailyQuota(): bool
    {
        return $this->errorCode === 'daily_limit_exceeded';
    }
}
