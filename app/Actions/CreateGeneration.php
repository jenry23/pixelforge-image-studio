<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\GenerationQuality;
use App\Enums\GenerationSize;
use App\Enums\GenerationStatus;
use App\Exceptions\InsufficientCreditsException;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use App\Pixelforge\PricingCatalogRepository;
use App\Pixelforge\PricingEngine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Accepts a generation request: quote it, reserve credit for it, persist it, and
 * hand it to the queue.
 *
 * Nothing is sent to Pixelforge here. The row is created first, in `pending`,
 * carrying its idempotency key -- which is what lets the submit job retry safely
 * and lets a webhook that overtakes the create response still find a row to
 * match against.
 */
final readonly class CreateGeneration
{
    public function __construct(
        private PricingCatalogRepository $catalogue,
        private PricingEngine $pricing,
        private LedgerWriter $ledger,
    ) {}

    /**
     * @param  array<int, string>  $referenceImages
     *
     * @throws InsufficientCreditsException
     */
    public function __invoke(
        User $user,
        string $prompt,
        GenerationSize $size,
        GenerationQuality $quality,
        array $referenceImages = [],
    ): Generation {
        $catalogue = $this->catalogue->get();

        $estimate = $this->pricing->estimate(
            catalog: $catalogue,
            prompt: $prompt,
            quality: $quality,
            size: $size,
            referenceImageCount: count($referenceImages),
        );

        $generation = $this->ledger->forUser($user->getKey(), function (User $lockedUser) use (
            $prompt, $size, $quality, $referenceImages, $estimate, $catalogue
        ): Generation {
            $generation = Generation::query()->create([
                'user_id' => $lockedUser->getKey(),
                // Generated and committed before any provider contact, so every
                // retry of this submission reuses it.
                'idempotency_key' => (string) Str::uuid(),
                'status' => GenerationStatus::Pending,
                'status_event_at' => now(),
                'prompt' => $prompt,
                'size' => $size,
                'quality' => $quality,
                'reference_images' => array_values($referenceImages),
                // Our quote's rates. Replaced by the provider's authoritative
                // snapshot as soon as it tells us what it actually fixed.
                'rates_snapshot' => $catalogue->rates->toArray(),
                'estimated_provider_min_nanodollars' => $estimate->providerMin->nanodollars,
                'estimated_provider_expected_nanodollars' => $estimate->providerExpected->nanodollars,
                'estimated_provider_max_nanodollars' => $estimate->providerMax->nanodollars,
                'estimated_charge_min_nanodollars' => $estimate->chargeMin->nanodollars,
                'estimated_charge_expected_nanodollars' => $estimate->chargeExpected->nanodollars,
                'estimated_charge_max_nanodollars' => $estimate->chargeMax->nanodollars,
            ]);

            // Reserve the MAXIMUM the generation could cost, not the expected
            // figure. Reserving the expected cost would let a user with a thin
            // balance overdraw whenever reasoning came in above the midpoint.
            $hold = $this->ledger->hold($lockedUser, $generation, $estimate->chargeMax);

            if ($hold === null) {
                throw new InsufficientCreditsException(
                    required: $estimate->chargeMax,
                    available: $lockedUser->available(),
                );
            }

            return $generation;
        });

        // Dispatched after the transaction commits, so the worker can never pick
        // the job up before the row it refers to is visible.
        DB::afterCommit(fn () => SubmitGeneration::dispatch($generation->getKey()));

        return $generation;
    }
}
