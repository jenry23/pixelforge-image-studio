<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Pixelforge\Money;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * The user cannot cover the maximum this generation could cost.
 *
 * Thrown before anything reaches Pixelforge, so a user who cannot afford a
 * generation never causes one to be created.
 */
final class InsufficientCreditsException extends RuntimeException
{
    public function __construct(
        public readonly Money $required,
        public readonly Money $available,
    ) {
        parent::__construct(sprintf(
            'This generation could cost up to $%s, but only $%s is available.',
            $required->toDisplayString(4),
            $available->toDisplayString(4),
        ));
    }

    /**
     * Surface as a field error on the form, so the UI shows it next to the
     * submit button rather than as an opaque failure.
     */
    public function toValidationException(): ValidationException
    {
        return ValidationException::withMessages([
            'credits' => $this->getMessage(),
        ]);
    }
}
