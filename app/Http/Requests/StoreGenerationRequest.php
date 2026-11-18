<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\GenerationQuality;
use App\Enums\GenerationSize;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreGenerationRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'prompt' => ['required', 'string', 'min:1', 'max:4000'],

            'size' => ['required', Rule::enum(GenerationSize::class)],
            'quality' => ['required', Rule::enum(GenerationQuality::class)],

            'reference_images' => ['array', 'max:4'],
            'reference_images.*' => [
                'required',
                'string',
                'url:http,https',
                'max:2048',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'prompt.max' => 'Pixelforge accepts prompts up to 4,000 characters.',
            'reference_images.max' => 'Pixelforge accepts at most 4 reference images.',
            'reference_images.*.url' => 'Each reference image must be an http or https URL.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'reference_images' => array_values(array_filter(
                (array) $this->input('reference_images', []),
                fn ($url): bool => is_string($url) && trim($url) !== ''
            )),
        ]);
    }

    public function referenceImages(): array
    {
        return array_map('trim', $this->validated('reference_images', []));
    }
}
