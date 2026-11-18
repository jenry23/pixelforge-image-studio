<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\GenerationQuality;
use App\Enums\GenerationSize;
use App\Support\GenerationLimits;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EstimateGenerationRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'prompt' => ['nullable', 'string', 'max:'.GenerationLimits::PROMPT_MAX_CHARACTERS],
            'size' => ['required', Rule::enum(GenerationSize::class)],
            'quality' => ['required', Rule::enum(GenerationQuality::class)],
            'reference_image_count' => [
                'nullable', 'integer', 'min:0', 'max:'.GenerationLimits::REFERENCE_IMAGES_MAX,
            ],
        ];
    }

    public function prompt(): string
    {
        return (string) ($this->validated('prompt') ?? '');
    }

    public function size(): GenerationSize
    {
        return GenerationSize::from($this->validated('size'));
    }

    public function quality(): GenerationQuality
    {
        return GenerationQuality::from($this->validated('quality'));
    }

    public function referenceImageCount(): int
    {
        return (int) ($this->validated('reference_image_count') ?? 0);
    }
}
