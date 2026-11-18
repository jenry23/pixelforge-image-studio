<?php

declare(strict_types=1);

namespace App\Pixelforge\Exceptions;

/**
 * A connection error, timeout, or unreadable response.
 *
 * This is the only genuinely dangerous failure: the request may have reached
 * Pixelforge and created a billable generation that we never heard about. So it
 * is retryable, the retry *must* reuse the same idempotency key, and the user's
 * credit hold must be kept until we establish what actually happened.
 */
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
