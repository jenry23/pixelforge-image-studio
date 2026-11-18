<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\ApplyProviderState;
use App\Actions\LedgerWriter;
use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use App\Pixelforge\Exceptions\AmbiguousOutcomeException;
use App\Pixelforge\Exceptions\RejectedException;
use App\Pixelforge\Exceptions\ThrottledException;
use App\Pixelforge\PixelforgeClient;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Hands one generation to Pixelforge, and owns every retry decision.
 *
 * This job exists so that each way the provider misbehaves becomes a retry
 * policy instead of a user-facing error. Three things make it safe:
 *
 *  1. The idempotency key was persisted with the generation *before* the first
 *     request, so any retry -- including after a timeout whose outcome we cannot
 *     know -- provably adopts the original generation rather than creating a
 *     second billable one.
 *  2. The provider's 3-in-flight cap is respected by counting our own in-flight
 *     generations, which is deliberately *not* the queue's worker concurrency:
 *     this job returns in milliseconds while its generation stays in flight at
 *     the provider for 5-30 seconds, so worker concurrency is no proxy for
 *     provider capacity.
 *  3. Retries are bounded twice over -- by a wall-clock deadline (retryUntil)
 *     and by a persisted count of real provider attempts. Waiting on the
 *     in-flight gate costs no retry budget, because nothing was attempted.
 */
class SubmitGeneration implements ShouldQueue
{
    use Queueable;

    /**
     * Bounded by retryUntil() rather than a tries count, because most releases
     * are capacity waits rather than failures.
     */
    public int $tries = 100;

    public function __construct(public readonly string $generationId) {}

    /** Hard wall-clock bound on the whole submission effort. */
    public function retryUntil(): DateTimeInterface
    {
        return CarbonImmutable::now()->addMinutes(30);
    }

    public function handle(
        PixelforgeClient $client,
        ApplyProviderState $applyProviderState,
        LedgerWriter $ledger,
    ): void {
        $generation = Generation::query()->find($this->generationId);

        if ($generation === null) {
            return;
        }

        // A webhook can arrive before the create response is saved, so by the
        // time this runs the generation may already be known -- or even finished.
        if ($generation->provider_id !== null || $generation->status->isFinal()) {
            return;
        }

        $maxAttempts = (int) config('services.pixelforge.submit_max_attempts', 6);

        if ($generation->submit_attempts >= $maxAttempts) {
            $this->exhaustBudget($generation, $ledger);

            return;
        }

        // The provider in-flight gate. Checked before the HTTP call so we avoid
        // provoking 429 concurrency_limit_exceeded rather than reacting to it.
        if ($this->providerIsAtCapacity()) {
            $this->release(
                (int) config('services.pixelforge.concurrency_wait_seconds', 5)
            );

            return;
        }

        $generation->forceFill([
            'submit_attempts' => $generation->submit_attempts + 1,
            'first_submit_attempted_at' => $generation->first_submit_attempted_at ?? now(),
        ])->save();

        try {
            $result = $client->createGeneration(
                $generation->providerPayload(),
                $generation->idempotency_key,
            );
        } catch (ThrottledException $e) {
            $this->handleThrottled($generation, $ledger, $e);

            return;
        } catch (RejectedException $e) {
            // Definitive: nothing was created, nothing billed.
            $this->reject($generation, $ledger, $e->errorCode ?? 'rejected', $e->getMessage());

            return;
        } catch (AmbiguousOutcomeException $e) {
            $this->handleAmbiguous($generation, $ledger, $e);

            return;
        }

        if ($result->wasReplayed) {
            // A previous attempt did reach the provider after all; we adopted it
            // instead of creating a duplicate. Worth seeing in the logs.
            Log::info('Adopted an existing Pixelforge generation via idempotent replay.', [
                'generation_id' => $generation->getKey(),
                'provider_id' => $result->generation->id,
                'attempts' => $generation->submit_attempts,
            ]);
        }

        // The outcome is known, so the ambiguity flag no longer applies.
        if ($generation->submit_outcome_unknown) {
            $generation->forceFill(['submit_outcome_unknown' => false])->save();
        }

        $applyProviderState($generation, $result->generation);
    }

    private function handleThrottled(Generation $generation, LedgerWriter $ledger, ThrottledException $e): void
    {
        // The daily quota will not clear inside any sane retry window, so retrying
        // would just burn the per-minute limit. Nothing was created.
        if ($e->isDailyQuota()) {
            $this->reject(
                $generation,
                $ledger,
                $e->errorCode ?? 'daily_limit_exceeded',
                'Pixelforge\'s daily generation quota is used up. Please try again tomorrow.',
            );

            return;
        }

        $this->release($this->backoffFor($generation, $e->retryAfterSeconds()));
    }

    private function handleAmbiguous(Generation $generation, LedgerWriter $ledger, AmbiguousOutcomeException $e): void
    {
        // Record that we may have created something we cannot see. The credit
        // hold stays until the truth is established.
        $generation->forceFill([
            'submit_outcome_unknown' => true,
            'error_code' => $e->errorCode ?? 'provider_unreachable',
            'error_message' => $e->getMessage(),
        ])->save();

        Log::warning('Ambiguous Pixelforge submit outcome; will retry with the same idempotency key.', [
            'generation_id' => $generation->getKey(),
            'attempt' => $generation->submit_attempts,
            'reason' => $e->getMessage(),
        ]);

        $this->release($this->backoffFor($generation, null));
    }

    /**
     * Honour Retry-After when the provider sends one, otherwise back off
     * exponentially with jitter so retries spread out instead of synchronising.
     */
    private function backoffFor(Generation $generation, ?int $retryAfter): int
    {
        if ($retryAfter !== null) {
            return max(1, $retryAfter);
        }

        $base = min(60, 2 ** max(0, $generation->submit_attempts - 1));

        return $base + random_int(0, 3);
    }

    /**
     * The retry budget is spent. What happens next depends entirely on whether
     * we know what the provider did.
     */
    private function exhaustBudget(Generation $generation, LedgerWriter $ledger): void
    {
        if (! $generation->submit_outcome_unknown) {
            // Every failure was a clean refusal or throttle, so we know for
            // certain nothing was created and nothing will be billed.
            $this->reject(
                $generation,
                $ledger,
                $generation->error_code ?? 'submit_failed',
                'We could not submit this generation to Pixelforge. You were not charged.',
            );

            return;
        }

        // We genuinely do not know whether a billable generation exists. Park it
        // and KEEP the hold: releasing now could let the user spend credits we
        // may still owe Pixelforge for. ResolveUnknownGeneration settles it by
        // replaying the same idempotency key.
        $generation->forceFill([
            'status' => GenerationStatus::Unknown,
            'status_event_at' => now(),
            'error_code' => 'outcome_unknown',
            'error_message' => 'We lost contact with Pixelforge while submitting this. '
                .'We are confirming whether it was created; you have not been charged yet.',
        ])->save();

        Log::error('Submit retry budget exhausted with an unknown provider outcome.', [
            'generation_id' => $generation->getKey(),
            'idempotency_key' => $generation->idempotency_key,
            'attempts' => $generation->submit_attempts,
        ]);

        ResolveUnknownGeneration::dispatch($generation->getKey())->delay(now()->addSeconds(30));
    }

    /** Terminal, nothing created provider-side: free the reservation. */
    private function reject(Generation $generation, LedgerWriter $ledger, string $code, string $message): void
    {
        $ledger->forUser($generation->user_id, function (User $user) use ($generation, $code, $message, $ledger) {
            $fresh = Generation::query()->whereKey($generation->getKey())->lockForUpdate()->firstOrFail();

            // An early webhook may have overtaken us with real news; never
            // overwrite a genuine provider outcome with our submit failure.
            if ($fresh->status->isFinal() || $fresh->provider_id !== null) {
                return;
            }

            $fresh->forceFill([
                'status' => GenerationStatus::Rejected,
                'status_event_at' => now(),
                'error_code' => $code,
                'error_message' => $message,
                'provider_cost_nanodollars' => 0,
                'charged_nanodollars' => 0,
                'settled_at' => now(),
            ])->save();

            $ledger->release($user, $fresh, 'Submission rejected; nothing was billed');
        });
    }

    /**
     * How many of our generations Pixelforge currently counts as in flight.
     *
     * Counted account-wide, not per user, because the provider's limits "apply
     * per account, across all of its API keys".
     */
    private function providerIsAtCapacity(): bool
    {
        $max = (int) config('services.pixelforge.max_in_flight', 3);

        return Generation::query()->inFlightAtProvider()->count() >= $max;
    }
}
