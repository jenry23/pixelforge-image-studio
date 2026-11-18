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

/**
 * Brings anything stuck back in line with the provider, by polling.
 *
 * Webhook delivery "isn't guaranteed", so an app that only listens for webhooks
 * is an app that eventually strands a generation -- and, with the hold design, a
 * user's credits along with it. This command is the second half of the brief's
 * "a webhook **or by polling**", and it closes four distinct gaps:
 *
 *   1. in-flight generations the provider never told us about;
 *   2. events we recorded durably but crashed before applying;
 *   3. submissions whose job was lost before it ever ran;
 *   4. ambiguous submissions still awaiting resolution.
 *
 * Everything it learns goes through the same ApplyProviderState action the
 * webhook path uses, so polling and webhooks cannot reach different conclusions,
 * and whichever arrives first wins while the other becomes a no-op.
 */
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

    /**
     * Events that were durably recorded but never applied -- the crash window
     * between acknowledging a webhook and acting on it.
     */
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

    /**
     * Generations the provider has gone quiet about. A typical generation
     * completes in 5-30 seconds, so silence well past that means we probably
     * lost an event.
     */
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
                    // Leave it for the next pass rather than failing the whole run.
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

    /**
     * Submissions whose queued job never ran -- a worker killed between the
     * commit and the job being picked up. Safe to re-dispatch because the job is
     * idempotent and the generation already holds its idempotency key.
     */
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
