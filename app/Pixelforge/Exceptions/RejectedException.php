<?php

declare(strict_types=1);

namespace App\Pixelforge\Exceptions;

/**
 * The provider refused the request outright and definitively: content policy,
 * validation, a reused idempotency key, or a bad API key.
 *
 * Nothing was created and nothing was billed, so retrying would only produce the
 * same refusal. The generation becomes terminal and the credit hold is released.
 */
final class RejectedException extends PixelforgeException
{
    public function isRetryable(): bool
    {
        return false;
    }
}
