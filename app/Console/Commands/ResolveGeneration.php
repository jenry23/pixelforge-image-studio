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
        {--submit : Re-send the original request. NOT read-only: if Pixelforge never received the first one, this creates a generation and we are billed for it}
        {--release : Release the hold, for a generation you have evidence was never created}';

    protected $description = 'Inspect or settle a generation whose outcome automatic retries could not confirm';

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
            return $this->release($generation, $client, $ledger);
        }

        if ($this->option('submit')) {
            return $this->submit($generation, $client, $applyProviderState);
        }

        return $this->inspect($generation, $client);
    }

    /**
     * The default, and the only one of the three that writes nothing anywhere.
     */
    private function inspect(Generation $generation, PixelforgeClient $client): int
    {
        $this->line("Searching Pixelforge for metadata.generation_id = {$generation->getKey()}…");

        try {
            $found = $client->findByMetadataGenerationId($generation->getKey());
        } catch (PixelforgeException $e) {
            $this->error("Could not reach Pixelforge: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->newLine();
        $this->table(['field', 'value'], [
            ['our id', $generation->getKey()],
            ['status', $generation->status->value],
            ['held (nd)', $generation->hold_nanodollars],
            ['idempotency key', $generation->idempotency_key],
            ['provider id', $generation->provider_id ?? '—'],
            ['records scanned', $found['scanned']],
        ]);

        if ($found['generation'] !== null) {
            $provider = $found['generation'];

            $this->newLine();
            $this->info('Pixelforge HAS this generation:');
            $this->table(['field', 'value'], [
                ['provider id', $provider->id],
                ['status', $provider->status->value],
                ['usage', $provider->usage === null ? 'none yet' : json_encode($provider->usage->toArray())],
            ]);
            $this->newLine();
            $this->line('It exists, so settle it by adopting that record:');
            $this->line("  php artisan pixelforge:resolve-generation {$generation->getKey()} --submit");
            $this->line('  (safe here: the key is already known to Pixelforge, so this replays rather than creates)');

            return self::SUCCESS;
        }

        $this->newLine();

        if (! $found['exhausted']) {
            $this->warn('Did not find it, but the search hit its page limit before reaching the end of');
            $this->warn('your history. That is not evidence of absence. Narrow it down by hand before');
            $this->warn('doing anything else.');

            return self::FAILURE;
        }

        $this->warn('Pixelforge has no generation carrying this id.');
        $this->newLine();
        $this->line('Two readings, and they need different actions:');
        $this->line('  - the request never arrived -> nothing exists, nothing is owed:');
        $this->line("      php artisan pixelforge:resolve-generation {$generation->getKey()} --release");
        $this->line('  - it arrived but is older than the list reaches -> check GET /v1/usage for the');
        $this->line('    day in question before releasing anything.');

        return self::SUCCESS;
    }

    /**
     * Re-sends the original create. This is a submission, not a lookup: an
     * idempotency key only replays if Pixelforge saw it, and if the first
     * request never arrived this makes a new generation and we pay for it.
     */
    private function submit(
        Generation $generation,
        PixelforgeClient $client,
        ApplyProviderState $applyProviderState,
    ): int {
        $this->warn('This re-sends the original request to Pixelforge.');
        $this->warn('If they received the first one, the key replays and nothing extra is billed.');
        $this->warn('If they never received it, this CREATES a generation and we are billed for it.');
        $this->warn('Run without --submit first to check which case you are in.');

        if (! $this->confirm('Send it?')) {
            return self::FAILURE;
        }

        try {
            $result = $client->createGeneration(
                $generation->providerPayload(),
                $generation->idempotency_key,
            );
        } catch (RejectedException $e) {
            $this->warn("Pixelforge refused the key: {$e->getMessage()}");

            return self::FAILURE;
        } catch (PixelforgeException $e) {
            $this->error("No verdict: {$e->getMessage()}");
            $this->line('The generation and its hold are untouched. Try again later.');

            return self::FAILURE;
        }

        $generation->forceFill(['submit_outcome_unknown' => false])->save();

        $outcome = $applyProviderState($generation, $result->generation);

        $generation->refresh();

        $this->newLine();
        $this->table(['field', 'value'], [
            ['replayed', $result->wasReplayed ? 'yes (it already existed)' : 'NO -- this created it'],
            ['provider id', $generation->provider_id ?? '—'],
            ['status', $generation->status->value],
            ['applied', $outcome->applied ? 'yes' : "no ({$outcome->reason})"],
            ['charged (nd)', $generation->charged_nanodollars],
            ['held (nd)', $generation->hold_nanodollars],
        ]);

        if (! $result->wasReplayed) {
            $this->warn('Pixelforge had never seen that key, so this submission created the generation.');
            $this->warn('The original request never reached them.');
        }

        return self::SUCCESS;
    }

    private function release(Generation $generation, PixelforgeClient $client, LedgerWriter $ledger): int
    {
        // Check before releasing rather than trusting the operator's memory: if
        // it does exist, releasing the hold would give away credit we are owed.
        try {
            $found = $client->findByMetadataGenerationId($generation->getKey());
        } catch (PixelforgeException $e) {
            $this->error("Could not confirm with Pixelforge: {$e->getMessage()}");
            $this->line('Refusing to release a hold without checking. Try again later.');

            return self::FAILURE;
        }

        if ($found['generation'] !== null) {
            $this->error("Pixelforge DOES have this generation ({$found['generation']->id}).");
            $this->line('Releasing the hold would write off a real charge. Settle it instead:');
            $this->line("  php artisan pixelforge:resolve-generation {$generation->getKey()} --submit");

            return self::FAILURE;
        }

        if (! $found['exhausted']) {
            $this->error('The search hit its page limit before reaching the end of your history,');
            $this->error('so this is not evidence that nothing was created. Not releasing.');

            return self::FAILURE;
        }

        $this->info('Pixelforge has no generation carrying this id.');

        if (! $this->confirm(
            'Release the hold and record this as never created? Check GET /v1/usage for the day first if it is old.'
        )) {
            return self::FAILURE;
        }

        $ledger->forUser($generation->user_id, function (User $user) use ($generation, $ledger): void {
            $fresh = Generation::query()->whereKey($generation->getKey())->lockForUpdate()->firstOrFail();

            // A webhook may have settled this between the check above and the
            // row lock here. If it did, that answer wins and we leave it alone.
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

        $generation->refresh();

        if ($generation->status === GenerationStatus::Succeeded) {
            $this->warn('A webhook settled this as succeeded while you were deciding.');
            $this->warn("That answer stands: charged {$generation->charged_nanodollars} nd. The hold was not released.");

            return self::SUCCESS;
        }

        $this->info('Hold released and the generation recorded as never created.');

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

        $this->newLine();
        $this->line('Inspect one (writes nothing): php artisan pixelforge:resolve-generation <id>');

        return self::SUCCESS;
    }
}
