<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use App\Pixelforge\PricingEngine;
use App\Pixelforge\ProviderGeneration;
use Illuminate\Support\Facades\Log;

/**
 * The single place a generation's status changes and money is settled.
 *
 * All three sources of provider truth funnel through here:
 *
 *   - the webhook handler,
 *   - the create response in the submit job,
 *   - the reconciler's GET /v1/generations/{id} poll.
 *
 * Because they share this one transactional, monotonic path, a webhook racing a
 * poll cannot produce a different outcome than either alone, and no source can
 * regress a generation that has already settled. Settlement happens in the same
 * transaction as the transition that makes the generation final, so the two can
 * never diverge.
 */
final readonly class ApplyProviderState
{
    public function __construct(
        private LedgerWriter $ledger,
        private PricingEngine $pricing,
    ) {}

    public function __invoke(Generation $generation, ProviderGeneration $provider): ApplyOutcome
    {
        // Locks the user row first, then the generation, so every money path in
        // the app takes locks in the same order.
        return $this->ledger->forUser($generation->user_id, function (User $user) use ($generation, $provider) {
            $fresh = Generation::query()->whereKey($generation->getKey())->lockForUpdate()->firstOrFail();

            if ($mismatch = $this->resolveProviderId($fresh, $provider)) {
                return $mismatch;
            }

            // A succeeded event with no usage cannot be costed. Rather than
            // applying a final status we could never settle, leave the
            // generation unsettled so the reconciler can fetch the full object.
            if ($provider->status === GenerationStatus::Succeeded && $provider->usage === null) {
                Log::warning('Pixelforge reported success without usage; deferring to the reconciler.', [
                    'generation_id' => $fresh->getKey(),
                    'provider_id' => $provider->id,
                ]);

                return ApplyOutcome::ignored('succeeded_without_usage');
            }

            if (! $fresh->canTransitionTo($provider->status, $provider->eventAt())) {
                return ApplyOutcome::ignored(
                    $fresh->status->isFinal() ? 'already_final' : 'out_of_order'
                );
            }

            $this->applyFields($fresh, $provider);

            $settled = match ($provider->status) {
                GenerationStatus::Succeeded => $this->settle($user, $fresh, $provider),
                GenerationStatus::Failed => $this->releaseAsFree($user, $fresh, 'Generation failed; not billed'),
                default => false,
            };

            $fresh->save();

            return ApplyOutcome::applied($provider->status, $settled);
        });
    }

    /**
     * Adopt the provider's id, or refuse if it contradicts one we already hold.
     *
     * A mismatch means two provider generations are claiming the same local row,
     * which should be impossible given one idempotency key per generation. It is
     * logged and ignored rather than overwriting a correct id.
     */
    private function resolveProviderId(Generation $generation, ProviderGeneration $provider): ?ApplyOutcome
    {
        if ($generation->provider_id === null) {
            $generation->provider_id = $provider->id;

            return null;
        }

        if ($generation->provider_id === $provider->id) {
            return null;
        }

        Log::error('Provider generation id conflicts with the one already recorded.', [
            'generation_id' => $generation->getKey(),
            'recorded' => $generation->provider_id,
            'incoming' => $provider->id,
        ]);

        return ApplyOutcome::ignored('provider_id_mismatch');
    }

    private function applyFields(Generation $generation, ProviderGeneration $provider): void
    {
        $generation->status = $provider->status;
        $generation->status_event_at = $provider->eventAt();

        // The provider's rate snapshot is authoritative: we quoted from cached
        // pricing, but we must bill from what it actually fixed at creation.
        $generation->rates_snapshot = $provider->rates->toArray();

        $generation->provider_created_at = $provider->createdAt ?? $generation->provider_created_at;
        $generation->provider_started_at = $provider->startedAt ?? $generation->provider_started_at;
        $generation->provider_completed_at = $provider->completedAt ?? $generation->provider_completed_at;

        if ($provider->usage !== null) {
            $generation->usage = $provider->usage->toArray();
        }

        if ($provider->imageUrl !== null) {
            $generation->image_url = $provider->imageUrl;
            $generation->image_url_expires_at = $provider->imageUrlExpiresAt;
        }

        $generation->error_code = $provider->errorCode;
        $generation->error_message = $provider->errorMessage;
    }

    /**
     * Record the exact cost and charge the user.
     *
     * Costed from the generation's own rate snapshot and the provider's reported
     * usage -- never from current pricing, and never from our estimate.
     */
    private function settle(User $user, Generation $generation, ProviderGeneration $provider): bool
    {
        $settledCost = $this->pricing->settle($provider->usage, $provider->rates);

        $generation->provider_cost_nanodollars = $settledCost->providerCost->nanodollars;
        $generation->charged_nanodollars = $settledCost->userCharge->nanodollars;
        $generation->settled_at = now();

        // The provider bills on the UTC day a generation completed, so this is
        // what the admin report must group by to tie out to GET /v1/usage.
        $generation->billed_on_utc = ($provider->completedAt ?? now())->utc()->toDateString();

        $this->ledger->charge($user, $generation, $settledCost->userCharge);

        return true;
    }

    /** A failed generation costs the user nothing and frees its reservation. */
    private function releaseAsFree(User $user, Generation $generation, string $reason): bool
    {
        $generation->provider_cost_nanodollars = 0;
        $generation->charged_nanodollars = 0;
        $generation->settled_at = now();
        $generation->billed_on_utc = null;

        $this->ledger->release($user, $generation, $reason);

        return true;
    }
}
