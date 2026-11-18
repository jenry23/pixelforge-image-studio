<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\ApplyProviderState;
use App\Actions\LedgerWriter;
use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use App\Pixelforge\Exceptions\PixelforgeException;
use App\Pixelforge\Exceptions\RejectedException;
use App\Pixelforge\PixelforgeClient;
use Illuminate\Console\Command;

class ResolveGeneration extends Command
{
    protected $signature = 'pixelforge:resolve-generation
        {generation? : The generation id; omit to list everything awaiting review}
        {--release : Release the hold without replaying, for a generation you have confirmed by hand was never created}';

    protected $description = 'Settle a generation whose provider outcome automatic retries could not confirm';

    public function handle(
        PixelforgeClient $client,
        ApplyProviderState $applyProviderState,
        LedgerWriter $ledger,
    ): int {
        $id = $this->argument('generation');

        if ($id === null) {
            return $this->listUnresolved();
        }

        $generation = Generation::query()->find($id);

        if ($generation === null) {
            $this->error("No generation [{$id}].");

            return self::FAILURE;
        }

        if ($generation->status->isFinal()) {
            $this->info("Already settled as {$generation->status->value}; nothing to do.");

            return self::SUCCESS;
        }

        if ($this->option('release')) {
            return $this->release($generation, $ledger);
        }

        return $this->replay($generation, $client, $applyProviderState);
    }

    /**
     * The replay is the whole point of this command. Pixelforge's idempotency
     * keys never expire, so sending the original key answers the question
     * definitively however long ago the submission was.
     */
    private function replay(
        Generation $generation,
        PixelforgeClient $client,
        ApplyProviderState $applyProviderState,
    ): int {
        $this->line("Replaying idempotency key {$generation->idempotency_key}…");

        try {
            $result = $client->createGeneration(
                $generation->providerPayload(),
                $generation->idempotency_key,
            );
        } catch (RejectedException $e) {
            $this->warn("Pixelforge refused the key: {$e->getMessage()}");
            $this->warn('That means nothing was ever created. Re-run with --release to free the hold.');

            return self::FAILURE;
        } catch (PixelforgeException $e) {
            $this->error("Could not reach a verdict: {$e->getMessage()}");
            $this->line('The generation is unchanged and the hold is untouched. Try again later.');

            return self::FAILURE;
        }

        $generation->forceFill(['submit_outcome_unknown' => false])->save();

        $outcome = $applyProviderState($generation, $result->generation);

        $generation->refresh();

        $this->table(['field', 'value'], [
            ['provider id', $generation->provider_id ?? '—'],
            ['status', $generation->status->value],
            ['applied', $outcome->applied ? 'yes' : "no ({$outcome->reason})"],
            ['charged', $generation->charged_nanodollars],
            ['hold', $generation->hold_nanodollars],
        ]);

        return self::SUCCESS;
    }

    private function release(Generation $generation, LedgerWriter $ledger): int
    {
        if (! $this->confirm(
            'Only do this if you have confirmed with Pixelforge that nothing was created. Release the hold?'
        )) {
            return self::FAILURE;
        }

        $ledger->forUser($generation->user_id, function (User $user) use ($generation, $ledger): void {
            $fresh = Generation::query()->whereKey($generation->getKey())->lockForUpdate()->firstOrFail();

            if ($fresh->status->isFinal()) {
                return;
            }

            $fresh->forceFill([
                'status' => GenerationStatus::Rejected,
                'status_event_at' => now(),
                'error_code' => 'released_by_operator',
                'error_message' => 'We confirmed this generation was never created. You were not charged.',
                'provider_cost_nanodollars' => 0,
                'charged_nanodollars' => 0,
                'settled_at' => now(),
            ])->save();

            $ledger->release($user, $fresh, 'Released by an operator after confirming nothing was created');
        });

        $this->info('Hold released and the generation marked rejected.');

        return self::SUCCESS;
    }

    private function listUnresolved(): int
    {
        $rows = Generation::query()
            ->where('status', GenerationStatus::Unresolved)
            ->orderBy('created_at')
            ->get();

        if ($rows->isEmpty()) {
            $this->info('Nothing is awaiting review.');

            return self::SUCCESS;
        }

        $this->table(
            ['generation', 'user', 'held (nd)', 'since', 'idempotency key'],
            $rows->map(fn (Generation $g): array => [
                $g->getKey(),
                $g->user_id,
                $g->hold_nanodollars,
                $g->created_at->toDateTimeString(),
                $g->idempotency_key,
            ])->all(),
        );

        $this->line('Settle one with: php artisan pixelforge:resolve-generation <id>');

        return self::SUCCESS;
    }
}
