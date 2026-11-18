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
            return;
        }

        if ($this->windowHasClosed($generation)) {
            $this->parkForOperator($generation, $ledger);

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
            $this->concludeNothingExists($generation, $ledger, $e->errorCode ?? 'rejected', $e->getMessage());

            return;
        } catch (AmbiguousOutcomeException) {
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

    private function concludeNothingExists(
        Generation $generation,
        LedgerWriter $ledger,
        string $code,
        string $message,
    ): void {
        $this->finalise($generation, $ledger, $code, $message);
    }

    /**
     * Automatic replays stop here, but the question stays open. Pixelforge's
     * idempotency keys never expire, so a replay can still settle this
     * definitively whenever an operator asks -- which is why this must not be
     * written down as a rejection. The hold stays with it.
     */
    private function parkForOperator(Generation $generation, LedgerWriter $ledger): void
    {
        $ledger->forUser($generation->user_id, function () use ($generation): void {
            $fresh = Generation::query()->whereKey($generation->getKey())->lockForUpdate()->firstOrFail();

            if ($fresh->status !== GenerationStatus::Unknown) {
                return;
            }

            $fresh->forceFill([
                'status' => GenerationStatus::Unresolved,
                'status_event_at' => now(),
                'error_code' => 'awaiting_operator',
                'error_message' => 'We could not confirm this generation with Pixelforge. '
                    .'It is being looked into, and your credit is still reserved until it is settled.',
            ])->save();
        });

        Log::error(
            'Stopped replaying an unknown generation automatically; it now needs an operator. '
            .'The hold is deliberately still in place. Resolve it with '
            .'`php artisan pixelforge:resolve-generation`, which replays the idempotency '
            .'key (these never expire) and settles whatever Pixelforge reports.',
            [
                'generation_id' => $generation->getKey(),
                'idempotency_key' => $generation->idempotency_key,
                'user_id' => $generation->user_id,
                'held_nanodollars' => $generation->hold_nanodollars,
            ]
        );
    }

    private function finalise(Generation $generation, LedgerWriter $ledger, string $code, string $message): void
    {
        $ledger->forUser($generation->user_id, function (User $user) use ($generation, $ledger, $code, $message) {
            $fresh = Generation::query()->whereKey($generation->getKey())->lockForUpdate()->firstOrFail();

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
