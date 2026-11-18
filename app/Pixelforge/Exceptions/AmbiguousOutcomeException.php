<?php

declare(strict_types=1);

namespace App\Pixelforge\Exceptions;

final class AmbiguousOutcomeException extends PixelforgeException
{
    public function isRetryable(): bool
    {
        return true;
    }

    public function outcomeIsUnknown(): bool
    {
        return true;
    }
}
