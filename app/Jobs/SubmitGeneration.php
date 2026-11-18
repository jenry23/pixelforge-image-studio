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

class SubmitGeneration implements ShouldQueue
{
    use Queueable;

    public int $tries = 100;

    public function __construct(public readonly string $generationId) {}

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

        if ($generation->provider_id !== null || $generation->status->isFinal()) {
            return;
        }

        $maxAttempts = (int) config('services.pixelforge.submit_max_attempts', 6);

        if ($generation->submit_attempts >= $maxAttempts) {
            $this->exhaustBudget($generation, $ledger);

            return;
        }

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
            $this->reject($generation, $ledger, $e->errorCode ?? 'rejected', $e->getMessage());

            return;
        } catch (AmbiguousOutcomeException $e) {
            $this->handleAmbiguous($generation, $ledger, $e);

            return;
        }

        if ($result->wasReplayed) {
            Log::info('Adopted an existing Pixelforge generation via idempotent replay.', [
                'generation_id' => $generation->getKey(),
                'provider_id' => $result->generation->id,
                'attempts' => $generation->submit_attempts,
            ]);
        }

        if ($generation->submit_outcome_unknown) {
            $generation->forceFill(['submit_outcome_unknown' => false])->save();
        }

        $applyProviderState($generation, $result->generation);
    }

    private function handleThrottled(Generation $generation, LedgerWriter $ledger, ThrottledException $e): void
    {
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

    private function backoffFor(Generation $generation, ?int $retryAfter): int
    {
        if ($retryAfter !== null) {
            return max(1, $retryAfter);
        }

        $base = min(60, 2 ** max(0, $generation->submit_attempts - 1));

        return $base + random_int(0, 3);
    }

    private function exhaustBudget(Generation $generation, LedgerWriter $ledger): void
    {
        if (! $generation->submit_outcome_unknown) {
            $this->reject(
                $generation,
                $ledger,
                $generation->error_code ?? 'submit_failed',
                'We could not submit this generation to Pixelforge. You were not charged.',
            );

            return;
        }

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

    private function reject(Generation $generation, LedgerWriter $ledger, string $code, string $message): void
    {
        $ledger->forUser($generation->user_id, function (User $user) use ($generation, $code, $message, $ledger) {
            $fresh = Generation::query()->whereKey($generation->getKey())->lockForUpdate()->firstOrFail();

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

    private function providerIsAtCapacity(): bool
    {
        $max = (int) config('services.pixelforge.max_in_flight', 3);

        return Generation::query()->inFlightAtProvider()->count() >= $max;
    }
}
