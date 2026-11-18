<?php

declare(strict_types=1);

namespace App\Pixelforge\Exceptions;

use RuntimeException;

abstract class PixelforgeException extends RuntimeException
{
    public function __construct(
        string $message,

        public readonly ?string $errorCode = null,
        public readonly ?string $type = null,
        public readonly ?int $status = null,
    ) {
        parent::__construct($message);
    }

    abstract public function isRetryable(): bool;

    public function outcomeIsUnknown(): bool
    {
        return false;
    }

    public function retryAfterSeconds(): ?int
    {
        return null;
    }
}
