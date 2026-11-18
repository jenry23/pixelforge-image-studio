<?php

declare(strict_types=1);

namespace App\Support;

final class GenerationLimits
{
    public const int PROMPT_MAX_CHARACTERS = 4000;

    public const int REFERENCE_IMAGES_MAX = 4;

    public const int REFERENCE_IMAGE_URL_MAX = 2048;

    public static function forDisplay(): array
    {
        return [
            'prompt_max_characters' => self::PROMPT_MAX_CHARACTERS,
            'reference_images_max' => self::REFERENCE_IMAGES_MAX,
        ];
    }
}
