<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\GenerationQuality;
use App\Enums\GenerationSize;
use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Generation>
 */
class GenerationFactory extends Factory
{
    protected $model = Generation::class;

    /** The rates the live API currently fixes generations at. */
    public const array RATES = [
        'input_text_tokens' => '5.00',
        'input_image_tokens' => '10.00',
        'output_tokens' => '40.00',
        'reasoning_tokens' => '12.50',
    ];

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            // Persisted before the first provider request, exactly as the real
            // submission path does it.
            'idempotency_key' => (string) Str::uuid(),
            'provider_id' => null,
            'status' => GenerationStatus::Pending,
            'status_event_at' => null,
            'prompt' => 'a brass astrolabe resting on dark blue velvet',
            'size' => GenerationSize::Square,
            'quality' => GenerationQuality::Low,
            'reference_images' => [],
            'rates_snapshot' => self::RATES,
            'estimated_provider_min_nanodollars' => 11_000_000,
            'estimated_provider_expected_nanodollars' => 14_000_000,
            'estimated_provider_max_nanodollars' => 18_000_000,
            'estimated_charge_min_nanodollars' => 13_750_000,
            'estimated_charge_expected_nanodollars' => 17_500_000,
            'estimated_charge_max_nanodollars' => 22_500_000,
            'hold_nanodollars' => 0,
        ];
    }

    /** Accepted by the provider and waiting in its queue. */
    public function queued(): static
    {
        return $this->state(fn (): array => [
            'status' => GenerationStatus::Queued,
            'provider_id' => 'gen_'.strtoupper(Str::random(26)),
            'status_event_at' => now()->subSeconds(10),
            'provider_created_at' => now()->subSeconds(10),
        ]);
    }

    public function processing(): static
    {
        return $this->queued()->state(fn (): array => [
            'status' => GenerationStatus::Processing,
            'status_event_at' => now()->subSeconds(5),
            'provider_started_at' => now()->subSeconds(5),
        ]);
    }

    /** Settled successfully, with the exact cost we verified against /v1/usage. */
    public function succeeded(): static
    {
        return $this->queued()->state(fn (): array => [
            'status' => GenerationStatus::Succeeded,
            'status_event_at' => now(),
            'provider_started_at' => now()->subSeconds(5),
            'provider_completed_at' => now(),
            'billed_on_utc' => now()->utc()->toDateString(),
            'usage' => [
                'input_text_tokens' => 9,
                'input_image_tokens' => 0,
                'output_tokens' => 280,
                'reasoning_tokens' => 37,
                'total_tokens' => 326,
            ],
            'provider_cost_nanodollars' => 11_707_500,
            'charged_nanodollars' => 14_634_375,
            'settled_at' => now(),
            'image_url' => 'https://pixelforge.promptedit.dev/images/example.png?signature=x',
            'image_url_expires_at' => now()->addDay(),
        ]);
    }

    public function failed(): static
    {
        return $this->queued()->state(fn (): array => [
            'status' => GenerationStatus::Failed,
            'status_event_at' => now(),
            'provider_completed_at' => now(),
            'provider_cost_nanodollars' => 0,
            'charged_nanodollars' => 0,
            'settled_at' => now(),
            'error_code' => 'generation_failed',
            'error_message' => 'The model could not produce an image for this prompt.',
        ]);
    }

    /** Submission ended ambiguously; the credit hold is deliberately retained. */
    public function unknownOutcome(): static
    {
        return $this->state(fn (): array => [
            'status' => GenerationStatus::Unknown,
            'submit_attempts' => 6,
            'submit_outcome_unknown' => true,
            'first_submit_attempted_at' => now()->subMinutes(5),
            'status_event_at' => now()->subMinutes(1),
            'error_code' => 'outcome_unknown',
        ]);
    }

    public function withHold(int $nanodollars): static
    {
        return $this->state(fn (): array => ['hold_nanodollars' => $nanodollars]);
    }
}
