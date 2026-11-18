<?php

declare(strict_types=1);

namespace App\Pixelforge\Exceptions;

final class RejectedException extends PixelforgeException
{
    public function isRetryable(): bool
    {
        return false;
    }
}
