<?php

declare(strict_types=1);

namespace App\Enums;

enum TokenType: string
{
    case InputText = 'input_text_tokens';
    case InputImage = 'input_image_tokens';
    case Output = 'output_tokens';
    case Reasoning = 'reasoning_tokens';

    public function label(): string
    {
        return match ($this) {
            self::InputText => 'Input text',
            self::InputImage => 'Reference images',
            self::Output => 'Output',
            self::Reasoning => 'Reasoning',
        };
    }

    public function isKnownUpFront(): bool
    {
        return match ($this) {
            self::Output, self::InputImage => true,
            self::InputText, self::Reasoning => false,
        };
    }
}
