<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Pixelforge\Money;
use Illuminate\Validation\ValidationException;
use RuntimeException;

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

    public function toValidationException(): ValidationException
    {
        return ValidationException::withMessages([
            'credits' => $this->getMessage(),
        ]);
    }
}
