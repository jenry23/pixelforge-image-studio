<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\GenerationQuality;
use App\Enums\GenerationSize;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a generation request against the provider's own documented limits,
 * so a request that Pixelforge would reject with 422 never leaves our app and
 * never consumes a slot against the per-minute rate limit.
 */
class StoreGenerationRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // "At most 4,000 characters" -- counted as characters, matching the
            // provider's Unicode-character counting rather than bytes.
            'prompt' => ['required', 'string', 'min:1', 'max:4000'],

            'size' => ['required', Rule::enum(GenerationSize::class)],
            'quality' => ['required', Rule::enum(GenerationQuality::class)],

            // "Optional http(s) image URLs to guide the style, at most 4."
            'reference_images' => ['array', 'max:4'],
            'reference_images.*' => [
                'required',
                'string',
                'url:http,https',
                'max:2048',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
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
        // Drop empty rows from the dynamic reference-image inputs so a blank
        // field is simply absent rather than a validation error.
        $this->merge([
            'reference_images' => array_values(array_filter(
                (array) $this->input('reference_images', []),
                fn ($url): bool => is_string($url) && trim($url) !== ''
            )),
        ]);
    }

    /**
     * @return array<int, string>
     */
    public function referenceImages(): array
    {
        return array_map('trim', $this->validated('reference_images', []));
    }
}
