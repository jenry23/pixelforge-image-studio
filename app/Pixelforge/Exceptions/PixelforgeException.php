<?php

declare(strict_types=1);

namespace App\Pixelforge\Exceptions;

use RuntimeException;

/**
 * Base for every provider failure.
 *
 * The subclasses exist so the submit job can express its retry policy as a
 * readable match on failure *meaning* rather than on HTTP status codes, and so
 * the one genuinely dangerous case -- an ambiguous outcome, where a billable
 * generation may or may not exist -- is impossible to confuse with a clean
 * rejection.
 */
abstract class PixelforgeException extends RuntimeException
{
    public function __construct(
        string $message,
        // Named `errorCode`, not `code`: Throwable already declares a
        // non-readonly int $code, and redeclaring it is a fatal error.
        public readonly ?string $errorCode = null,
        public readonly ?string $type = null,
        public readonly ?int $status = null,
    ) {
        parent::__construct($message);
    }

    /** Whether retrying could plausibly produce a different outcome. */
    abstract public function isRetryable(): bool;

    /**
     * Whether the provider may have created a billable generation despite this
     * failure. When true we must not release the user's credit hold until we
     * have established the truth.
     */
    public function outcomeIsUnknown(): bool
    {
        return false;
    }

    public function retryAfterSeconds(): ?int
    {
        return null;
    }
}
