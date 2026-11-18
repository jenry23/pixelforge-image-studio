<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\ApplyProviderState;
use App\Enums\GenerationStatus;
use App\Jobs\ResolveUnknownGeneration;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\WebhookEvent;
use App\Pixelforge\Exceptions\PixelforgeException;
use App\Pixelforge\PixelforgeClient;
use Illuminate\Console\Command;
use Throwable;

class ReconcileGenerations extends Command
{
    protected $signature = 'pixelforge:reconcile
                            {--limit=50 : Maximum generations to poll in one pass}';

    protected $description = 'Poll Pixelforge for generations that have gone quiet, and apply any events left unapplied';

    public function handle(PixelforgeClient $client, ApplyProviderState $applyProviderState): int
    {
        $applied = $this->applyRecordedButUnappliedEvents($applyProviderState);
        $polled = $this->pollStaleGenerations($client, $applyProviderState);
        $resubmitted = $this->redispatchLostSubmissions();
        $resolving = $this->nudgeUnknownOutcomes();

        $this->info(sprintf(
            'Reconciled: %d event(s) applied, %d generation(s) polled, %d resubmitted, %d unknown being resolved.',
            $applied, $polled, $resubmitted, $resolving
        ));

        return self::SUCCESS;
    }

    private function applyRecordedButUnappliedEvents(ApplyProviderState $applyProviderState): int
    {
        $count = 0;

        WebhookEvent::query()
            ->unapplied()
            ->whereNotNull('generation_id')
            ->orderBy('received_at')
            ->limit((int) $this->option('limit'))
            ->each(function (WebhookEvent $event) use ($applyProviderState, &$count): void {
                $generation = $event->generation;
                $providerGeneration = $event->providerGeneration();

                if ($generation === null || $providerGeneration === null) {
                    $event->forceFill(['ignored_reason' => 'unmatched'])->save();

                    return;
                }

                $outcome = $applyProviderState($generation, $providerGeneration);

                $event->forceFill($outcome->applied
                    ? ['applied_at' => now(), 'ignored_reason' => null]
                    : ['ignored_reason' => $outcome->reason])
                    ->save();

                $count += $outcome->applied ? 1 : 0;
            });

        return $count;
    }

    private function pollStaleGenerations(PixelforgeClient $client, ApplyProviderState $applyProviderState): int
    {
        $staleAfter = (int) config('services.pixelforge.reconcile_after_seconds', 45);
        $count = 0;

        Generation::query()
            ->inFlightAtProvider()
            ->whereNotNull('provider_id')
            ->where('updated_at', '<=', now()->subSeconds($staleAfter))
            ->orderBy('updated_at')
            ->limit((int) $this->option('limit'))
            ->each(function (Generation $generation) use ($client, $applyProviderState, &$count): void {
                try {
                    $providerGeneration = $client->retrieveGeneration($generation->provider_id);
                } catch (PixelforgeException|Throwable $e) {
                    $this->warn("Could not poll {$generation->getKey()}: {$e->getMessage()}");

                    return;
                }

                if ($providerGeneration === null) {
                    $this->warn("Pixelforge has no record of {$generation->provider_id}.");

                    return;
                }

                if ($applyProviderState($generation, $providerGeneration)->applied) {
                    $count++;
                }
            });

        return $count;
    }

    private function redispatchLostSubmissions(): int
    {
        $count = 0;

        Generation::query()
            ->where('status', GenerationStatus::Pending)
            ->whereNull('provider_id')
            ->where('created_at', '<=', now()->subMinutes(2))
            ->limit((int) $this->option('limit'))
            ->each(function (Generation $generation) use (&$count): void {
                SubmitGeneration::dispatch($generation->getKey());
                $count++;
            });

        return $count;
    }

    private function nudgeUnknownOutcomes(): int
    {
        $count = 0;

        Generation::query()
            ->where('status', GenerationStatus::Unknown)
            ->limit((int) $this->option('limit'))
            ->each(function (Generation $generation) use (&$count): void {
                ResolveUnknownGeneration::dispatch($generation->getKey());
                $count++;
            });

        return $count;
    }
}
