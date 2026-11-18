<?php

declare(strict_types=1);

namespace App\Pixelforge\Exceptions;

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

    public function isDailyQuota(): bool
    {
        return $this->errorCode === 'daily_limit_exceeded';
    }
}
