<?php

declare(strict_types=1);

use App\Actions\ApplyProviderState;
use App\Actions\LedgerWriter;
use App\Enums\GenerationStatus;
use App\Enums\TransactionType;
use App\Jobs\ResolveUnknownGeneration;
use App\Jobs\SubmitGeneration;
use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use App\Pixelforge\PixelforgeClient;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\Support\ProviderPayload;

beforeEach(function () {
    $this->user = fundUser(User::factory()->create(), '5.00');
});

/** Run the submit job against a fake provider, with queue interactions captured. */
function runSubmit(Generation $generation): SubmitGeneration
{
    $job = (new SubmitGeneration($generation->getKey()))->withFakeQueueInteractions();

    $job->handle(
        app(PixelforgeClient::class),
        app(ApplyProviderState::class),
        app(LedgerWriter::class),
    );

    return $job;
}

function pendingGeneration(User $user, array $state = []): Generation
{
    return heldGeneration($user, '0.05', [
        'status' => GenerationStatus::Pending,
        'provider_id' => null,
        ...$state,
    ]);
}

function acceptedResponse(Generation $generation, array $headers = []): PromiseInterface
{
    return Http::response(
        ProviderPayload::generation($generation, 'queued', ['id' => 'gen_ACCEPTED000000000000000001']),
        202,
        $headers
    );
}

function errorResponse(string $code, string $type, int $status, array $headers = []): PromiseInterface
{
    return Http::response([
        'error' => ['code' => $code, 'type' => $type, 'message' => "Simulated {$code}."],
    ], $status, $headers);
}

describe('idempotency', function () {
    it('persists an idempotency key before any provider request is made', function () {
        $generation = pendingGeneration($this->user);

        // The key exists on the committed row, before the job has ever run.
        expect($generation->idempotency_key)->not->toBeEmpty()
            ->and($generation->submit_attempts)->toBe(0);
    });

    it('sends that key on the create request', function () {
        $generation = pendingGeneration($this->user);
        Http::fake(['*/generations' => acceptedResponse($generation)]);

        runSubmit($generation);

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Idempotency-Key', $generation->idempotency_key)
        );
    });

    it('reuses the same key after an ambiguous timeout', function () {
        $generation = pendingGeneration($this->user);
        $keysSeen = [];

        Http::fake(function (Request $request) use (&$keysSeen) {
            $keysSeen[] = $request->header('Idempotency-Key')[0];

            // First attempt times out with an unknown outcome; the retry succeeds.
            if (count($keysSeen) === 1) {
                throw new ConnectionException('cURL error 28: Operation timed out');
            }

            return Http::response(
                ProviderPayload::generation($request->data()['metadata']['generation_id']
                    ? Generation::find($request->data()['metadata']['generation_id'])
                    : $generation, 'queued'),
                202,
                ['Idempotent-Replayed' => 'true']
            );
        });

        runSubmit($generation);
        runSubmit($generation->refresh());

        // Both attempts used one key, which is what makes the retry safe.
        expect($keysSeen)->toHaveCount(2)
            ->and($keysSeen[0])->toBe($generation->idempotency_key)
            ->and($keysSeen[1])->toBe($generation->idempotency_key);
    });

    it('sends our own generation id in metadata so an early webhook can match', function () {
        $generation = pendingGeneration($this->user);
        Http::fake(['*/generations' => acceptedResponse($generation)]);

        runSubmit($generation);

        Http::assertSent(fn (Request $request): bool => $request->data()['metadata']['generation_id'] === (string) $generation->getKey()
        );
    });

    it('sends identical parameters on every attempt, so the key can never 409', function () {
        $generation = pendingGeneration($this->user, ['prompt' => 'a precise prompt']);
        $bodies = [];

        Http::fake(function (Request $request) use (&$bodies, $generation) {
            $bodies[] = $request->data();

            if (count($bodies) < 3) {
                return errorResponse('overloaded', 'api_error', 503, ['Retry-After' => '2']);
            }

            return acceptedResponse($generation);
        });

        runSubmit($generation);
        runSubmit($generation->refresh());
        runSubmit($generation->refresh());

        expect($bodies)->toHaveCount(3)
            ->and($bodies[1])->toBe($bodies[0])
            ->and($bodies[2])->toBe($bodies[0]);
    });
});

describe('honouring Retry-After', function () {
    it('releases for exactly the seconds a rate limit asks for', function () {
        $generation = pendingGeneration($this->user);
        Http::fake(['*/generations' => errorResponse('rate_limit_exceeded', 'rate_limit_error', 429, ['Retry-After' => '37'])]);

        runSubmit($generation)->assertReleased(delay: 37);

        expect($generation->refresh()->status)->toBe(GenerationStatus::Pending);
    });

    it('releases for the 5 seconds a concurrency limit asks for', function () {
        $generation = pendingGeneration($this->user);
        Http::fake(['*/generations' => errorResponse('concurrency_limit_exceeded', 'rate_limit_error', 429, ['Retry-After' => '5'])]);

        runSubmit($generation)->assertReleased(delay: 5);
    });

    it('releases for the 2 seconds an overload asks for', function () {
        $generation = pendingGeneration($this->user);
        Http::fake(['*/generations' => errorResponse('overloaded', 'api_error', 503, ['Retry-After' => '2'])]);

        runSubmit($generation)->assertReleased(delay: 2);
    });

    it('backs off on its own when no Retry-After is given', function () {
        $generation = pendingGeneration($this->user, ['submit_attempts' => 3]);
        Http::fake(['*/generations' => fn () => throw new ConnectionException('timed out')]);

        $job = runSubmit($generation);

        // 2^(4-1) = 8s base, plus up to 3s of jitter.
        $job->assertReleased();
        expect($generation->refresh()->submit_attempts)->toBe(4);
    });

    it('counts every real attempt against the budget', function () {
        $generation = pendingGeneration($this->user);
        Http::fake(['*/generations' => errorResponse('overloaded', 'api_error', 503, ['Retry-After' => '1'])]);

        runSubmit($generation);
        runSubmit($generation->refresh());

        expect($generation->refresh()->submit_attempts)->toBe(2);
    });
});

describe('the provider in-flight cap, tracked separately from worker concurrency', function () {
    it('waits without making a request when three generations are already in flight', function () {
        // The provider's cap is on generations queued-or-processing at its end,
        // which is not the same thing as how many jobs our worker runs.
        Generation::factory()->count(3)->queued()->create();

        $generation = pendingGeneration($this->user);
        Http::fake();

        runSubmit($generation)->assertReleased(delay: 5);

        // Crucially, no HTTP call was attempted at all.
        Http::assertNothingSent();
    });

    it('does not spend retry budget while waiting for capacity', function () {
        Generation::factory()->count(3)->queued()->create();
        $generation = pendingGeneration($this->user);
        Http::fake();

        runSubmit($generation);
        runSubmit($generation->refresh());
        runSubmit($generation->refresh());

        // Capacity waits are not failures, so the budget is untouched -- otherwise
        // a busy account would exhaust its retries without ever reaching the API.
        expect($generation->refresh()->submit_attempts)->toBe(0);
    });

    it('counts in-flight generations across all users, since limits are per account', function () {
        Generation::factory()->count(2)->queued()->create();
        Generation::factory()->processing()->create(['user_id' => User::factory()]);

        $generation = pendingGeneration($this->user);
        Http::fake();

        runSubmit($generation)->assertReleased();
        Http::assertNothingSent();
    });

    it('proceeds once capacity frees up', function () {
        $busy = Generation::factory()->count(3)->queued()->create();
        $generation = pendingGeneration($this->user);
        Http::fake(['*/generations' => acceptedResponse($generation)]);

        runSubmit($generation)->assertReleased();

        $busy->first()->update(['status' => GenerationStatus::Succeeded]);

        runSubmit($generation->refresh());

        expect($generation->refresh()->provider_id)->toBe('gen_ACCEPTED000000000000000001');
    });
});

describe('definitive rejections', function () {
    it('marks a content policy violation terminal and releases the hold', function () {
        $generation = pendingGeneration($this->user);
        Http::fake(['*/generations' => errorResponse('content_policy_violation', 'invalid_request_error', 400)]);

        runSubmit($generation);

        $generation->refresh();
        $this->user->refresh();

        expect($generation->status)->toBe(GenerationStatus::Rejected)
            ->and($generation->error_code)->toBe('content_policy_violation')
            ->and($generation->charged_nanodollars)->toBe(0)
            ->and($this->user->held()->isZero())->toBeTrue()
            ->and($this->user->balance()->toUsdString())->toBe('5.000000000');
    });

    it('does not retry a validation failure', function () {
        $generation = pendingGeneration($this->user);
        Http::fake(['*/generations' => errorResponse('validation_failed', 'invalid_request_error', 422)]);

        $job = runSubmit($generation);

        $job->assertNotReleased();
        expect($generation->refresh()->status)->toBe(GenerationStatus::Rejected);
    });

    it('gives up immediately on the daily quota rather than burning the rate limit', function () {
        $generation = pendingGeneration($this->user);
        Http::fake(['*/generations' => errorResponse('daily_limit_exceeded', 'rate_limit_error', 429)]);

        $job = runSubmit($generation);

        $job->assertNotReleased();

        $generation->refresh();
        expect($generation->status)->toBe(GenerationStatus::Rejected)
            ->and($generation->error_message)->toContain('daily generation quota')
            ->and($this->user->refresh()->held()->isZero())->toBeTrue();
    });
});

describe('when the retry budget runs out', function () {
    it('parks the generation as unknown and KEEPS the hold if the outcome is unknown', function () {
        Bus::fake([ResolveUnknownGeneration::class]);

        // Budget already spent, with an ambiguous failure on record.
        $generation = pendingGeneration($this->user, [
            'submit_attempts' => 6,
            'submit_outcome_unknown' => true,
            'first_submit_attempted_at' => now()->subMinute(),
        ]);
        Http::fake();

        runSubmit($generation);

        $generation->refresh();
        $this->user->refresh();

        expect($generation->status)->toBe(GenerationStatus::Unknown)
            ->and($generation->error_code)->toBe('outcome_unknown')
            // The hold is deliberately retained: Pixelforge may have created a
            // billable generation we cannot see.
            ->and($generation->hold_nanodollars)->toBe(50_000_000)
            ->and($this->user->held()->toUsdString())->toBe('0.050000000')
            ->and($this->user->available()->toUsdString())->toBe('4.950000000');

        Http::assertNothingSent();
        Bus::assertDispatched(ResolveUnknownGeneration::class);
    });

    it('rejects and releases the hold when it knows nothing was created', function () {
        // Budget spent, but every failure was a clean throttle or refusal.
        $generation = pendingGeneration($this->user, [
            'submit_attempts' => 6,
            'submit_outcome_unknown' => false,
            'error_code' => 'overloaded',
        ]);
        Http::fake();

        runSubmit($generation);

        $generation->refresh();
        $this->user->refresh();

        expect($generation->status)->toBe(GenerationStatus::Rejected)
            ->and($generation->charged_nanodollars)->toBe(0)
            ->and($generation->hold_nanodollars)->toBe(0)
            // Safe to release, because we know there is nothing to pay for.
            ->and($this->user->held()->isZero())->toBeTrue()
            ->and($this->user->balance()->toUsdString())->toBe('5.000000000');
    });

    it('records an ambiguous failure so the distinction survives a worker restart', function () {
        $generation = pendingGeneration($this->user);
        Http::fake(['*/generations' => fn () => throw new ConnectionException('timed out')]);

        runSubmit($generation);

        // Persisted, not held in job memory.
        expect($generation->refresh()->submit_outcome_unknown)->toBeTrue();
    });

    it('clears the ambiguity flag once the provider finally answers', function () {
        $generation = pendingGeneration($this->user, ['submit_outcome_unknown' => true]);
        Http::fake(['*/generations' => acceptedResponse($generation)]);

        runSubmit($generation);

        expect($generation->refresh()->submit_outcome_unknown)->toBeFalse();
    });
});

describe('a successful submission', function () {
    it('adopts the provider id and status', function () {
        $generation = pendingGeneration($this->user);
        Http::fake(['*/generations' => acceptedResponse($generation)]);

        runSubmit($generation);

        $generation->refresh();

        expect($generation->provider_id)->toBe('gen_ACCEPTED000000000000000001')
            ->and($generation->status)->toBe(GenerationStatus::Queued)
            ->and($generation->hold_nanodollars)->toBe(50_000_000);
    });

    it('replaces our quoted rates with the snapshot the provider fixed', function () {
        $generation = pendingGeneration($this->user);

        Http::fake(['*/generations' => Http::response(
            ProviderPayload::generation($generation, 'queued', [
                'id' => 'gen_RATES00000000000000000001',
                'rates' => [
                    'input_text_tokens' => '6.00',
                    'input_image_tokens' => '10.00',
                    'output_tokens' => '40.00',
                    'reasoning_tokens' => '12.50',
                ],
            ]),
            202
        )]);

        runSubmit($generation);

        expect($generation->refresh()->rates_snapshot['input_text_tokens'])->toBe('6.00');
    });

    it('does nothing if a webhook already finished the generation first', function () {
        $generation = pendingGeneration($this->user, [
            'status' => GenerationStatus::Succeeded,
            'provider_id' => 'gen_ALREADYDONE0000000000001',
        ]);
        Http::fake();

        runSubmit($generation);

        // The documented race: the job must not re-submit something already done.
        Http::assertNothingSent();
    });

    it('is a no-op for a generation that no longer exists', function () {
        $job = (new SubmitGeneration('01JQQQQQQQQQQQQQQQQQQQQQQQ'))->withFakeQueueInteractions();
        Http::fake();

        $job->handle(
            app(PixelforgeClient::class),
            app(ApplyProviderState::class),
            app(LedgerWriter::class),
        );

        Http::assertNothingSent();
        $job->assertNotReleased();
    });
});

describe('resolving an unknown outcome', function () {
    it('replays the idempotency key and adopts whatever the provider says', function () {
        $generation = heldGeneration($this->user, '0.05', [
            'status' => GenerationStatus::Unknown,
            'provider_id' => null,
            'submit_attempts' => 6,
            'submit_outcome_unknown' => true,
            'first_submit_attempted_at' => now()->subMinutes(2),
        ]);

        // The replay reveals the generation did exist and has since succeeded.
        Http::fake(['*/generations' => Http::response(
            ProviderPayload::generation($generation, 'succeeded', ['id' => 'gen_RESOLVED00000000000000001']),
            202,
            ['Idempotent-Replayed' => 'true']
        )]);

        $job = (new ResolveUnknownGeneration($generation->getKey()))->withFakeQueueInteractions();
        $job->handle(
            app(PixelforgeClient::class),
            app(ApplyProviderState::class),
            app(LedgerWriter::class),
        );

        $generation->refresh();

        // Billed correctly, exactly once, despite never seeing a create response.
        expect($generation->status)->toBe(GenerationStatus::Succeeded)
            ->and($generation->provider_id)->toBe('gen_RESOLVED00000000000000001')
            ->and($generation->charged_nanodollars)->toBe(14_634_375);

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Idempotency-Key', $generation->idempotency_key)
        );
    });

    it('fails closed and releases the hold once the resolution window closes', function () {
        $generation = heldGeneration($this->user, '0.05', [
            'status' => GenerationStatus::Unknown,
            'submit_attempts' => 6,
            'submit_outcome_unknown' => true,
            // Older than the configured 60-minute window.
            'first_submit_attempted_at' => now()->subMinutes(120),
        ]);
        Http::fake();

        $job = (new ResolveUnknownGeneration($generation->getKey()))->withFakeQueueInteractions();
        $job->handle(
            app(PixelforgeClient::class),
            app(ApplyProviderState::class),
            app(LedgerWriter::class),
        );

        $generation->refresh();
        $this->user->refresh();

        expect($generation->status)->toBe(GenerationStatus::Rejected)
            ->and($generation->error_code)->toBe('outcome_unconfirmed')
            ->and($generation->error_message)->toContain('not charged')
            ->and($this->user->held()->isZero())->toBeTrue()
            ->and($this->user->balance()->toUsdString())->toBe('5.000000000')
            ->and(CreditTransaction::query()
                ->where('generation_id', $generation->getKey())
                ->where('type', TransactionType::Charge)
                ->count())->toBe(0);

        Http::assertNothingSent();
    });

    it('stops if the generation was resolved by a late webhook in the meantime', function () {
        $generation = heldGeneration($this->user, '0.05', [
            'status' => GenerationStatus::Succeeded,
            'first_submit_attempted_at' => now()->subMinutes(2),
        ]);
        Http::fake();

        $job = (new ResolveUnknownGeneration($generation->getKey()))->withFakeQueueInteractions();
        $job->handle(
            app(PixelforgeClient::class),
            app(ApplyProviderState::class),
            app(LedgerWriter::class),
        );

        Http::assertNothingSent();
    });
});
