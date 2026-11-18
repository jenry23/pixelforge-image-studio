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
 * Establishes what actually happened to a generation whose submission ended
 * ambiguously.
 *
 * The mechanism is the idempotency key, used for exactly what it is for. Because
 * the key and the parameters are both unchanged, replaying the create has only
 * two possible outcomes, and neither can bill us twice:
 *
 *   - the original request did reach Pixelforge: we get that same generation
 *     back with `Idempotent-Replayed: true`, adopt it, and bill it correctly;
 *   - it never arrived: the replay creates it once, and the user gets the image
 *     they asked and reserved credit for.
 *
 * The credit hold is held throughout, because until this resolves we may owe
 * Pixelforge for a generation we cannot see. If the bounded window expires
 * without an answer we fail closed: release the hold, tell the user plainly they
 * were not charged, and log loudly -- and the admin reconciliation page will
 * surface any provider cost we failed to record, so the gap is visible rather
 * than silent.
 */
class ResolveUnknownGeneration implements ShouldQueue
{
    use Queueable;

    public int $tries = 100;

    public function __construct(public readonly string $generationId) {}

    public function retryUntil(): DateTimeInterface
    {
        return CarbonImmutable::now()->addMinutes(
            (int) config('services.pixelforge.resolve_unknown_for_minutes', 60) + 5
        );
    }

    public function handle(
        PixelforgeClient $client,
        ApplyProviderState $applyProviderState,
        LedgerWriter $ledger,
    ): void {
        $generation = Generation::query()->find($this->generationId);

        if ($generation === null || $generation->status !== GenerationStatus::Unknown) {
            // Already resolved, by a late webhook or the reconciler.
            return;
        }

        if ($this->windowHasClosed($generation)) {
            $this->giveUp($generation, $ledger);

            return;
        }

        try {
            $result = $client->createGeneration(
                $generation->providerPayload(),
                $generation->idempotency_key,
            );
        } catch (ThrottledException $e) {
            $this->release(max(1, $e->retryAfterSeconds() ?? 10));

            return;
        } catch (RejectedException $e) {
            // A definitive refusal now tells us nothing was ever created, which
            // resolves the ambiguity: the user owes nothing.
            $this->concludeNothingExists($generation, $ledger, $e->errorCode ?? 'rejected', $e->getMessage());

            return;
        } catch (AmbiguousOutcomeException) {
            // Still cannot tell. Keep the hold and try again shortly.
            $this->release(30);

            return;
        }

        Log::info('Resolved an unknown generation by replaying its idempotency key.', [
            'generation_id' => $generation->getKey(),
            'provider_id' => $result->generation->id,
            'was_replayed' => $result->wasReplayed,
        ]);

        $generation->forceFill(['submit_outcome_unknown' => false])->save();

        $applyProviderState($generation, $result->generation);
    }

    private function windowHasClosed(Generation $generation): bool
    {
        $startedAt = $generation->first_submit_attempted_at ?? $generation->created_at;

        return $startedAt->addMinutes(
            (int) config('services.pixelforge.resolve_unknown_for_minutes', 60)
        )->isPast();
    }

    /**
     * We established that nothing exists provider-side, so the reservation can
     * be released safely.
     */
    private function concludeNothingExists(
        Generation $generation,
        LedgerWriter $ledger,
        string $code,
        string $message,
    ): void {
        $this->finalise($generation, $ledger, $code, $message);
    }

    /**
     * The window closed without an answer. Fail closed and make it loud.
     */
    private function giveUp(Generation $generation, LedgerWriter $ledger): void
    {
        Log::error(
            'Gave up resolving an unknown generation. If Pixelforge did create it, '
            .'our provider cost will exceed our recorded spend and the admin '
            .'reconciliation report will show the difference.',
            [
                'generation_id' => $generation->getKey(),
                'idempotency_key' => $generation->idempotency_key,
                'user_id' => $generation->user_id,
            ]
        );

        $this->finalise(
            $generation,
            $ledger,
            'outcome_unconfirmed',
            'We could not confirm this generation with Pixelforge, so we have cancelled it. You were not charged.',
        );
    }

    private function finalise(Generation $generation, LedgerWriter $ledger, string $code, string $message): void
    {
        $ledger->forUser($generation->user_id, function (User $user) use ($generation, $ledger, $code, $message) {
            $fresh = Generation::query()->whereKey($generation->getKey())->lockForUpdate()->firstOrFail();

            // Never overwrite real provider news that arrived while we waited.
            if ($fresh->status !== GenerationStatus::Unknown) {
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

            $ledger->release($user, $fresh, 'Unconfirmed submission cancelled; nothing was billed');
        });
    }
}
