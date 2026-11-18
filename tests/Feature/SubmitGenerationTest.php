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
use App\Pixelforge\ProviderGeneration;
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

    it('parks the generation for an operator and KEEPS the hold once the window closes', function () {
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

        // We still do not know whether Pixelforge made this, so we must not say
        // it failed, and we must not hand back credit we may owe.
        expect($generation->status)->toBe(GenerationStatus::Unresolved)
            ->and($generation->error_code)->toBe('awaiting_operator')
            ->and($generation->settled_at)->toBeNull()
            ->and($generation->hold_nanodollars)->toBe(50_000_000)
            ->and($this->user->held()->toUsdString())->toBe('0.050000000')
            ->and($this->user->available()->toUsdString())->toBe('4.950000000')
            ->and(CreditTransaction::query()
                ->where('generation_id', $generation->getKey())
                ->count())->toBe(1);

        // Automatic replays have stopped.
        Http::assertNothingSent();
    });

    it('stops replaying automatically once it has been parked', function () {
        $generation = heldGeneration($this->user, '0.05', [
            'status' => GenerationStatus::Unresolved,
            'submit_attempts' => 6,
            'submit_outcome_unknown' => true,
            'first_submit_attempted_at' => now()->subMinutes(200),
        ]);
        Http::fake();

        (new ResolveUnknownGeneration($generation->getKey()))
            ->withFakeQueueInteractions()
            ->handle(app(PixelforgeClient::class), app(ApplyProviderState::class), app(LedgerWriter::class));

        Http::assertNothingSent();
        expect($generation->refresh()->status)->toBe(GenerationStatus::Unresolved);
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

describe('a worker that dies mid-submission', function () {
    it('reuses the original idempotency key and adopts the generation Pixelforge already made', function () {
        // The worker claimed an attempt, reached Pixelforge, and was killed
        // before it could write the response. Its claim outlives it.
        $generation = pendingGeneration($this->user, [
            'submit_attempts' => 1,
            'submit_claimed_at' => now()->subMinutes(10),
            'first_submit_attempted_at' => now()->subMinutes(10),
        ]);

        $key = $generation->idempotency_key;

        Http::fake(['*/generations' => acceptedResponse($generation, ['Idempotent-Replay' => 'true'])]);

        runSubmit($generation);

        // Same key, so Pixelforge hands back the generation it already created
        // rather than making a second billable one.
        Http::assertSent(fn (Request $request) => $request->header('Idempotency-Key')[0] === $key);
        Http::assertSentCount(1);

        expect($generation->refresh()->provider_id)->toBe('gen_ACCEPTED000000000000000001')
            ->and($generation->status)->toBe(GenerationStatus::Queued)
            ->and($generation->submit_claimed_at)->toBeNull();
    });

    it('treats a killed last attempt as unknown rather than writing it off as failed', function () {
        Bus::fake([ResolveUnknownGeneration::class]);

        // The final attempt was claimed and never came back. Pixelforge may well
        // have accepted it, so this must not be recorded as a clean failure.
        $generation = pendingGeneration($this->user, [
            'submit_attempts' => 6,
            'submit_outcome_unknown' => false,
            'submit_claimed_at' => now()->subMinutes(10),
            'first_submit_attempted_at' => now()->subMinutes(10),
        ]);
        Http::fake();

        runSubmit($generation);

        $generation->refresh();
        $this->user->refresh();

        expect($generation->status)->toBe(GenerationStatus::Unknown)
            ->and($generation->error_code)->toBe('outcome_unknown')
            // The hold stays, because there may be something to pay for.
            ->and($generation->hold_nanodollars)->toBe(50_000_000)
            ->and($this->user->held()->toUsdString())->toBe('0.050000000');

        Bus::assertDispatched(ResolveUnknownGeneration::class);
    });

    it('leaves a claim that is still inside its lease alone', function () {
        // A living worker holds this one; a second worker must not barge in.
        $generation = pendingGeneration($this->user, [
            'submit_attempts' => 1,
            'submit_claimed_at' => now()->subSeconds(5),
        ]);
        Http::fake();

        $job = runSubmit($generation);

        Http::assertNothingSent();
        $job->assertReleased();
        expect($generation->refresh()->submit_attempts)->toBe(1)
            ->and($generation->submit_outcome_unknown)->toBeFalse();
    });
});

describe('two workers picking up the same generation', function () {
    it('lets exactly one of them claim the attempt', function () {
        $generation = pendingGeneration($this->user);
        Http::fake(['*/generations' => acceptedResponse($generation)]);

        $first = new SubmitGeneration($generation->getKey());
        $second = new SubmitGeneration($generation->getKey());

        // Both workers are inside handle() before either has finished: the
        // claim is the only thing standing between them.
        $claim = new ReflectionMethod(SubmitGeneration::class, 'claim');

        expect($claim->invoke($first, 6))->toBeTrue()
            ->and($claim->invoke($second, 6))->toBeFalse();

        // One claim, one attempt consumed -- no lost update.
        expect($generation->refresh()->submit_attempts)->toBe(1);
    });

    it('never lets two workers bill the same generation twice', function () {
        $generation = pendingGeneration($this->user);
        Http::fake(['*/generations' => acceptedResponse($generation)]);

        runSubmit($generation);

        $provider = ProviderGeneration::fromArray(ProviderPayload::generation(
            $generation->refresh(),
            'succeeded',
            ['id' => $generation->refresh()->provider_id],
        ));

        // Both workers settle the same completed generation.
        app(ApplyProviderState::class)($generation, $provider);
        app(ApplyProviderState::class)($generation->refresh(), $provider);

        $charges = CreditTransaction::query()
            ->where('generation_id', $generation->getKey())
            ->where('type', TransactionType::Charge)
            ->count();

        expect($charges)->toBe(1);
    });

    it('never lets two workers settle the same hold twice', function () {
        $generation = pendingGeneration($this->user, ['submit_attempts' => 6, 'error_code' => 'overloaded']);
        Http::fake();

        runSubmit($generation);
        runSubmit($generation->refresh());

        $releases = CreditTransaction::query()
            ->where('generation_id', $generation->getKey())
            ->where('type', TransactionType::Release)
            ->count();

        $this->user->refresh();

        expect($releases)->toBe(1)
            ->and($this->user->held()->isZero())->toBeTrue()
            ->and($this->user->balance()->toUsdString())->toBe('5.000000000');
    });

    it('counts the provider cap over generations, not over workers', function () {
        // The in-flight cap and the attempt claim guard different things: the
        // cap is the provider's rule, the claim is ours. The cap is advisory --
        // see the parallel harness in tests/concurrency for what it does under
        // genuinely simultaneous workers.
        config(['services.pixelforge.max_in_flight' => 1]);

        Generation::factory()->queued()->create(['user_id' => $this->user->id]);

        $generation = pendingGeneration($this->user);
        Http::fake();

        $job = runSubmit($generation);

        Http::assertNothingSent();
        $job->assertReleased();
        // Held back by the cap, so no attempt was spent and no claim was taken.
        expect($generation->refresh()->submit_attempts)->toBe(0)
            ->and($generation->submit_claimed_at)->toBeNull();
    });
});

describe('a webhook that arrives while the retry budget is running out', function () {
    it('settles the generation even though we never saw the create response', function () {
        Bus::fake([ResolveUnknownGeneration::class]);

        // Retries are spent and the outcome is unknown: the generation is
        // parked, holding the user's credit, with no provider id on record.
        $generation = pendingGeneration($this->user, [
            'submit_attempts' => 6,
            'submit_outcome_unknown' => true,
            'first_submit_attempted_at' => now()->subMinute(),
        ]);
        Http::fake();

        runSubmit($generation);

        expect($generation->refresh()->status)->toBe(GenerationStatus::Unknown)
            ->and($generation->provider_id)->toBeNull();

        // Pixelforge had accepted it after all, and now reports success. The
        // event carries our own id in metadata, which is the only way to match
        // it while provider_id is still unknown.
        deliverWebhook(ProviderPayload::eventFor($generation, 'succeeded'))->assertNoContent();

        $generation->refresh();
        $this->user->refresh();

        expect($generation->status)->toBe(GenerationStatus::Succeeded)
            ->and($generation->provider_id)->not->toBeNull()
            ->and($generation->charged_nanodollars)->toBeGreaterThan(0)
            // The hold became a charge rather than being released and re-taken.
            ->and($generation->hold_nanodollars)->toBe(0)
            ->and($this->user->held()->isZero())->toBeTrue();

        expect(CreditTransaction::query()
            ->where('generation_id', $generation->getKey())
            ->where('type', TransactionType::Charge)
            ->count())->toBe(1);
    });

    it('does not double charge when the resolver and the webhook race', function () {
        $generation = pendingGeneration($this->user, [
            'status' => GenerationStatus::Unknown,
            'submit_attempts' => 6,
            'submit_outcome_unknown' => true,
            'first_submit_attempted_at' => now()->subMinute(),
        ]);

        // The webhook lands first.
        deliverWebhook(ProviderPayload::eventFor($generation, 'succeeded'))->assertNoContent();
        $charged = $generation->refresh()->charged_nanodollars;

        // Then the resolver replays the idempotency key and gets the same
        // generation back from Pixelforge.
        Http::fake(['*/generations' => Http::response(
            ProviderPayload::generation($generation, 'succeeded', ['id' => $generation->provider_id]),
            200,
            ['Idempotent-Replay' => 'true'],
        )]);

        (new ResolveUnknownGeneration($generation->getKey()))
            ->withFakeQueueInteractions()
            ->handle(app(PixelforgeClient::class), app(ApplyProviderState::class), app(LedgerWriter::class));

        $this->user->refresh();

        expect($generation->refresh()->charged_nanodollars)->toBe($charged)
            ->and(CreditTransaction::query()
                ->where('generation_id', $generation->getKey())
                ->where('type', TransactionType::Charge)
                ->count())->toBe(1)
            ->and($this->user->held()->isZero())->toBeTrue();
    });
});

describe('a success webhook arriving after the resolution window has closed', function () {
    it('settles the parked generation exactly once and converts the hold into the charge', function () {
        // Submission went dark, retries were spent, the window closed, and the
        // generation was parked for an operator with the hold still on it.
        $generation = heldGeneration($this->user, '0.05', [
            'status' => GenerationStatus::Unknown,
            'provider_id' => null,
            'submit_attempts' => 6,
            'submit_outcome_unknown' => true,
            'first_submit_attempted_at' => now()->subMinutes(120),
        ]);
        Http::fake();

        (new ResolveUnknownGeneration($generation->getKey()))
            ->withFakeQueueInteractions()
            ->handle(app(PixelforgeClient::class), app(ApplyProviderState::class), app(LedgerWriter::class));

        expect($generation->refresh()->status)->toBe(GenerationStatus::Unresolved)
            ->and($generation->hold_nanodollars)->toBe(50_000_000);

        $balanceBefore = $this->user->refresh()->balance()->nanodollars;

        // Hours late, Pixelforge reports that it succeeded after all.
        deliverWebhook(ProviderPayload::eventFor($generation, 'succeeded'))->assertNoContent();

        $generation->refresh();
        $this->user->refresh();

        // "Needs review" never blocks a real answer: it ranks with pending, so
        // the terminal event applies and bills normally.
        expect($generation->status)->toBe(GenerationStatus::Succeeded)
            ->and($generation->provider_id)->not->toBeNull()
            ->and($generation->charged_nanodollars)->toBe(14_634_375)
            ->and($generation->settled_at)->not->toBeNull()
            // The hold became the charge; it was never released and re-taken.
            ->and($generation->hold_nanodollars)->toBe(0)
            ->and($this->user->held()->isZero())->toBeTrue()
            ->and($this->user->balance()->nanodollars)->toBe($balanceBefore - 14_634_375);

        // Exactly one settlement: one charge, and no release alongside it.
        expect(CreditTransaction::query()
            ->where('generation_id', $generation->getKey())
            ->where('type', TransactionType::Charge)
            ->count())->toBe(1)
            ->and(CreditTransaction::query()
                ->where('generation_id', $generation->getKey())
                ->where('type', TransactionType::Release)
                ->count())->toBe(0);

        // A duplicate delivery of the same event changes nothing.
        deliverWebhook(ProviderPayload::eventFor($generation, 'succeeded'))->assertNoContent();

        expect(CreditTransaction::query()
            ->where('generation_id', $generation->getKey())
            ->where('type', TransactionType::Charge)
            ->count())->toBe(1)
            ->and($this->user->refresh()->balance()->nanodollars)->toBe($balanceBefore - 14_634_375);
    });

    it('lets an operator settle a parked generation by replaying the key', function () {
        $generation = heldGeneration($this->user, '0.05', [
            'status' => GenerationStatus::Unresolved,
            'provider_id' => null,
            'submit_attempts' => 6,
            'error_code' => 'awaiting_operator',
        ]);

        // Keys never expire, so the replay still answers the question.
        Http::fake(['*/generations' => Http::response(
            ProviderPayload::generation($generation, 'succeeded', ['id' => 'gen_OPERATOR00000000000000001']),
            202,
            ['Idempotent-Replayed' => 'true'],
        )]);

        $this->artisan('pixelforge:resolve-generation', [
            'generation' => $generation->getKey(),
            '--submit' => true,
        ])->expectsConfirmation('Send it?', 'yes')->assertSuccessful();

        $generation->refresh();
        $this->user->refresh();

        expect($generation->status)->toBe(GenerationStatus::Succeeded)
            ->and($generation->provider_id)->toBe('gen_OPERATOR00000000000000001')
            ->and($generation->charged_nanodollars)->toBe(14_634_375)
            ->and($this->user->held()->isZero())->toBeTrue();

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Idempotency-Key', $generation->idempotency_key));
    });

    it('lets an operator release the hold once they have confirmed nothing exists', function () {
        $generation = heldGeneration($this->user, '0.05', [
            'status' => GenerationStatus::Unresolved,
            'provider_id' => null,
            'error_code' => 'awaiting_operator',
        ]);

        // Pixelforge's list comes back without it, which is the evidence.
        Http::fake(['*/generations*' => Http::response(['data' => [], 'has_more' => false])]);

        $this->artisan('pixelforge:resolve-generation', [
            'generation' => $generation->getKey(),
            '--release' => true,
        ])->expectsConfirmation(
            'Release the hold and record this as never created? Check GET /v1/usage for the day first if it is old.',
            'yes',
        )->assertSuccessful();

        $generation->refresh();
        $this->user->refresh();

        expect($generation->status)->toBe(GenerationStatus::Rejected)
            ->and($generation->error_code)->toBe('released_by_operator')
            ->and($generation->charged_nanodollars)->toBe(0)
            ->and($this->user->held()->isZero())->toBeTrue()
            ->and($this->user->balance()->toUsdString())->toBe('5.000000000');
    });

    it('lists what is waiting for an operator', function () {
        heldGeneration($this->user, '0.05', [
            'status' => GenerationStatus::Unresolved,
            'error_code' => 'awaiting_operator',
        ]);

        $this->artisan('pixelforge:resolve-generation')
            ->expectsOutputToContain('writes nothing')
            ->assertSuccessful();
    });
});

describe('inspecting an unresolved generation without submitting anything', function () {
    it('writes nothing and sends no create request', function () {
        $generation = heldGeneration($this->user, '0.05', [
            'status' => GenerationStatus::Unresolved,
            'provider_id' => null,
            'error_code' => 'awaiting_operator',
        ]);

        Http::fake(['*/generations*' => Http::response(['data' => [], 'has_more' => false])]);

        $this->artisan('pixelforge:resolve-generation', ['generation' => $generation->getKey()])
            ->assertSuccessful();

        // Only the read. No POST, so nothing could have been created.
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET');
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');

        $generation->refresh();
        $this->user->refresh();

        expect($generation->status)->toBe(GenerationStatus::Unresolved)
            ->and($generation->hold_nanodollars)->toBe(50_000_000)
            ->and($this->user->held()->toUsdString())->toBe('0.050000000')
            ->and(CreditTransaction::query()
                ->where('generation_id', $generation->getKey())
                ->count())->toBe(1);
    });

    it('finds the generation by the id we put in metadata', function () {
        $generation = heldGeneration($this->user, '0.05', [
            'status' => GenerationStatus::Unresolved,
            'provider_id' => null,
        ]);

        Http::fake(['*/generations*' => Http::response([
            'data' => [
                ProviderPayload::generation($generation, 'succeeded', ['id' => 'gen_FOUND0000000000000000001']),
            ],
            'has_more' => false,
        ])]);

        $this->artisan('pixelforge:resolve-generation', ['generation' => $generation->getKey()])
            ->expectsOutputToContain('Pixelforge HAS this generation')
            ->assertSuccessful();

        // Still inspection only: finding it settles nothing by itself.
        expect($generation->refresh()->status)->toBe(GenerationStatus::Unresolved)
            ->and($generation->charged_nanodollars)->toBeNull();
    });

    it('refuses to release a hold when Pixelforge does have the generation', function () {
        $generation = heldGeneration($this->user, '0.05', [
            'status' => GenerationStatus::Unresolved,
            'provider_id' => null,
        ]);

        Http::fake(['*/generations*' => Http::response([
            'data' => [
                ProviderPayload::generation($generation, 'succeeded', ['id' => 'gen_REAL00000000000000000001']),
            ],
            'has_more' => false,
        ])]);

        $this->artisan('pixelforge:resolve-generation', [
            'generation' => $generation->getKey(),
            '--release' => true,
        ])->expectsOutputToContain('Releasing the hold would write off a real charge')
            ->assertFailed();

        $this->user->refresh();

        // The hold survives, because there is something to pay for.
        expect($generation->refresh()->hold_nanodollars)->toBe(50_000_000)
            ->and($this->user->held()->toUsdString())->toBe('0.050000000');
    });

    it('refuses to call a generation missing when it ran out of pages to look at', function () {
        $generation = heldGeneration($this->user, '0.05', [
            'status' => GenerationStatus::Unresolved,
            'provider_id' => null,
        ]);

        // Every page says there is more to come, so absence is never proven.
        Http::fake(['*/generations*' => Http::response([
            'data' => [], 'has_more' => true, 'next_cursor' => 'cur_more',
        ])]);

        $this->artisan('pixelforge:resolve-generation', [
            'generation' => $generation->getKey(),
            '--release' => true,
        ])->expectsOutputToContain('not evidence')
            ->assertFailed();

        expect($generation->refresh()->hold_nanodollars)->toBe(50_000_000);
    });
});

describe('a success webhook landing while an operator runs --release', function () {
    it('keeps the provider answer and settles the ledger exactly once', function () {
        $generation = heldGeneration($this->user, '0.05', [
            'status' => GenerationStatus::Unresolved,
            'provider_id' => null,
            'error_code' => 'awaiting_operator',
        ]);

        $balanceBefore = $this->user->refresh()->balance()->nanodollars;

        // The list says nothing exists, so the operator's evidence clears them
        // to release -- but the webhook lands while they are still looking, so
        // that evidence is stale by the time the row lock is taken.
        Http::fake(['*/generations*' => function () use ($generation) {
            deliverWebhook(ProviderPayload::eventFor($generation, 'succeeded'))->assertNoContent();

            return Http::response(['data' => [], 'has_more' => false]);
        }]);

        $this->artisan('pixelforge:resolve-generation', [
            'generation' => $generation->getKey(),
            '--release' => true,
        ])->expectsConfirmation(
            'Release the hold and record this as never created? Check GET /v1/usage for the day first if it is old.',
            'yes',
        )->expectsOutputToContain('A webhook settled this as succeeded while you were deciding')
            ->assertSuccessful();

        $generation->refresh();
        $this->user->refresh();

        // The provider's answer wins; the operator's assumption is discarded.
        expect($generation->status)->toBe(GenerationStatus::Succeeded)
            ->and($generation->error_code)->not->toBe('released_by_operator')
            ->and($generation->charged_nanodollars)->toBe(14_634_375)
            ->and($generation->hold_nanodollars)->toBe(0)
            ->and($this->user->held()->isZero())->toBeTrue()
            ->and($this->user->balance()->nanodollars)->toBe($balanceBefore - 14_634_375);

        // One charge, and no release competing with it.
        expect(CreditTransaction::query()
            ->where('generation_id', $generation->getKey())
            ->where('type', TransactionType::Charge)
            ->count())->toBe(1)
            ->and(CreditTransaction::query()
                ->where('generation_id', $generation->getKey())
                ->where('type', TransactionType::Release)
                ->count())->toBe(0);
    });

    it('still bills correctly when the release lands first', function () {
        $generation = heldGeneration($this->user, '0.05', [
            'status' => GenerationStatus::Unresolved,
            'provider_id' => null,
        ]);

        $balanceBefore = $this->user->refresh()->balance()->nanodollars;

        Http::fake(['*/generations*' => Http::response(['data' => [], 'has_more' => false])]);

        $this->artisan('pixelforge:resolve-generation', [
            'generation' => $generation->getKey(),
            '--release' => true,
        ])->expectsConfirmation(
            'Release the hold and record this as never created? Check GET /v1/usage for the day first if it is old.',
            'yes',
        )->assertSuccessful();

        expect($generation->refresh()->status)->toBe(GenerationStatus::Rejected)
            ->and($this->user->refresh()->held()->isZero())->toBeTrue();

        // Pixelforge had it after all, and says so afterwards.
        deliverWebhook(ProviderPayload::eventFor($generation, 'succeeded'))->assertNoContent();

        $generation->refresh();
        $this->user->refresh();

        // Rejected is not a wall against real provider news, so we bill it --
        // once -- and the balance is right either way the race goes.
        expect($generation->status)->toBe(GenerationStatus::Succeeded)
            ->and($generation->charged_nanodollars)->toBe(14_634_375)
            ->and($this->user->balance()->nanodollars)->toBe($balanceBefore - 14_634_375)
            ->and($this->user->held()->isZero())->toBeTrue()
            ->and(CreditTransaction::query()
                ->where('generation_id', $generation->getKey())
                ->where('type', TransactionType::Charge)
                ->count())->toBe(1);
    });
});
