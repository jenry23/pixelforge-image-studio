<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use App\Pixelforge\PricingEngine;
use App\Pixelforge\ProviderGeneration;
use Illuminate\Support\Facades\Log;

final readonly class ApplyProviderState
{
    public function __construct(
        private LedgerWriter $ledger,
        private PricingEngine $pricing,
    ) {}

    public function __invoke(Generation $generation, ProviderGeneration $provider): ApplyOutcome
    {
        return $this->ledger->forUser($generation->user_id, function (User $user) use ($generation, $provider) {
            $fresh = Generation::query()->whereKey($generation->getKey())->lockForUpdate()->firstOrFail();

            if ($mismatch = $this->resolveProviderId($fresh, $provider)) {
                return $mismatch;
            }

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

    private function settle(User $user, Generation $generation, ProviderGeneration $provider): bool
    {
        $settledCost = $this->pricing->settle($provider->usage, $provider->rates);

        $generation->provider_cost_nanodollars = $settledCost->providerCost->nanodollars;
        $generation->charged_nanodollars = $settledCost->userCharge->nanodollars;
        $generation->settled_at = now();

        $generation->billed_on_utc = ($provider->completedAt ?? now())->utc()->toDateString();

        $this->ledger->charge($user, $generation, $settledCost->userCharge);

        return true;
    }

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
