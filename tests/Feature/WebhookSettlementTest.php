<?php

declare(strict_types=1);

use App\Actions\ApplyProviderState;
use App\Enums\GenerationStatus;
use App\Enums\TransactionType;
use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Pixelforge\ProviderGeneration;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Tests\Support\ProviderPayload;

beforeEach(function () {
    $this->user = fundUser(User::factory()->create(), '5.00');
});

/** A generation accepted by the provider, with credit reserved against it. */
function inFlight(User $user, string $hold = '0.05'): Generation
{
    return heldGeneration($user, $hold, [
        'status' => GenerationStatus::Queued,
        'provider_id' => 'gen_'.strtoupper(Str::random(26)),
        'status_event_at' => now()->subSeconds(10),
    ]);
}

function chargeRows(Generation $generation): int
{
    return CreditTransaction::query()
        ->where('generation_id', $generation->getKey())
        ->where('type', TransactionType::Charge)
        ->count();
}

describe('settlement on success', function () {
    it('records the exact provider cost and the marked-up charge', function () {
        $generation = inFlight($this->user);

        deliverWebhook(ProviderPayload::eventFor($generation, 'succeeded'))->assertNoContent();

        $generation->refresh();

        // The figures verified against the live GET /v1/usage.
        expect($generation->status)->toBe(GenerationStatus::Succeeded)
            ->and($generation->provider_cost_nanodollars)->toBe(11_707_500)
            ->and($generation->charged_nanodollars)->toBe(14_634_375)
            ->and($generation->providerCost()->toUsdString())->toBe('0.011707500')
            ->and($generation->settled_at)->not->toBeNull();
    });

    it('debits the user by exactly the charge and frees the reservation', function () {
        $generation = inFlight($this->user, '0.05');

        expect($this->user->refresh()->held()->toUsdString())->toBe('0.050000000');

        deliverWebhook(ProviderPayload::eventFor($generation, 'succeeded'))->assertNoContent();

        $this->user->refresh();

        expect($this->user->balance()->toUsdString())->toBe('4.985365625')  // 5 - 0.014634375
            ->and($this->user->held()->isZero())->toBeTrue()
            ->and($this->user->available()->toUsdString())->toBe('4.985365625');
    });

    it('writes exactly one charge to the ledger', function () {
        $generation = inFlight($this->user);

        deliverWebhook(ProviderPayload::eventFor($generation, 'succeeded'))->assertNoContent();

        expect(chargeRows($generation))->toBe(1);
    });

    it('stores the UTC day the provider completed it on, not the day it was created', function () {
        $generation = inFlight($this->user);

        // Created just before midnight UTC, completed just after it.
        deliverWebhook(ProviderPayload::eventFor($generation, 'succeeded', overrides: [
            'created_at' => '2026-11-18T23:59:50Z',
            'started_at' => '2026-11-18T23:59:55Z',
            'completed_at' => '2026-11-19T00:00:10Z',
        ]))->assertNoContent();

        // The provider bills this on the 19th; so must we, or the report never
        // ties out to GET /v1/usage.
        expect($generation->refresh()->billed_on_utc)->toBe('2026-11-19');
    });

    it('keeps the signed image url and its expiry', function () {
        $generation = inFlight($this->user);

        deliverWebhook(ProviderPayload::eventFor($generation, 'succeeded'))->assertNoContent();

        expect($generation->refresh()->image_url)->toContain('/images/')
            ->and($generation->image_url_expires_at)->not->toBeNull();
    });

    it('bills from the rate snapshot in the event, not from current pricing', function () {
        $generation = inFlight($this->user);

        // The provider fixed this generation at double the current output rate.
        deliverWebhook(ProviderPayload::eventFor($generation, 'succeeded', overrides: [
            'rates' => [
                'input_text_tokens' => '5.00',
                'input_image_tokens' => '10.00',
                'output_tokens' => '80.00',
                'reasoning_tokens' => '12.50',
            ],
        ]))->assertNoContent();

        // 9x5000 + 280x80000 + 37x12500 = 45,000 + 22,400,000 + 462,500
        expect($generation->refresh()->provider_cost_nanodollars)->toBe(22_907_500);
    });
});

describe('failed generations are free', function () {
    it('charges nothing and releases the hold', function () {
        $generation = inFlight($this->user, '0.05');

        deliverWebhook(ProviderPayload::eventFor($generation, 'failed'))->assertNoContent();

        $generation->refresh();
        $this->user->refresh();

        expect($generation->status)->toBe(GenerationStatus::Failed)
            ->and($generation->charged_nanodollars)->toBe(0)
            ->and($generation->provider_cost_nanodollars)->toBe(0)
            ->and($generation->error_code)->toBe('generation_failed')
            // The balance is untouched and the reservation is gone.
            ->and($this->user->balance()->toUsdString())->toBe('5.000000000')
            ->and($this->user->held()->isZero())->toBeTrue()
            ->and(chargeRows($generation))->toBe(0);
    });

    it('records the release in the ledger exactly once', function () {
        $generation = inFlight($this->user);

        deliverWebhook(ProviderPayload::eventFor($generation, 'failed', eventId: 'evt_fail_a'))->assertNoContent();
        deliverWebhook(ProviderPayload::eventFor($generation, 'failed', eventId: 'evt_fail_b'))->assertNoContent();

        expect(CreditTransaction::query()
            ->where('generation_id', $generation->getKey())
            ->where('type', TransactionType::Release)
            ->count())->toBe(1);
    });
});

describe('duplicate delivery', function () {
    it('acknowledges the same event twice but records and charges once', function () {
        $generation = inFlight($this->user);
        $event = ProviderPayload::eventFor($generation, 'succeeded', eventId: 'evt_duplicate_me');

        deliverWebhook($event, deliveryAttempt: 1)->assertNoContent();
        deliverWebhook($event, deliveryAttempt: 2)->assertNoContent();

        expect(WebhookEvent::query()->where('event_id', 'evt_duplicate_me')->count())->toBe(1)
            ->and(chargeRows($generation))->toBe(1)
            ->and($this->user->refresh()->balance()->toUsdString())->toBe('4.985365625');
    });

    it('ignores a second, differently-identified event for an already settled generation', function () {
        $generation = inFlight($this->user);

        // Two distinct event ids describing the same completion, which the
        // unique index alone would not stop -- the monotonic guard does.
        deliverWebhook(ProviderPayload::eventFor($generation, 'succeeded', eventId: 'evt_one'))->assertNoContent();
        deliverWebhook(ProviderPayload::eventFor($generation, 'succeeded', eventId: 'evt_two'))->assertNoContent();

        expect(chargeRows($generation))->toBe(1)
            ->and(WebhookEvent::query()->where('event_id', 'evt_two')->first()->ignored_reason)
            ->toBe('already_final');
    });

    it('cannot write a second charge even if application logic were bypassed', function () {
        $generation = inFlight($this->user);
        deliverWebhook(ProviderPayload::eventFor($generation, 'succeeded'))->assertNoContent();

        // The invariant that makes double-billing unrepresentable rather than
        // merely unlikely.
        CreditTransaction::query()->create([
            'user_id' => $this->user->getKey(),
            'generation_id' => $generation->getKey(),
            'type' => TransactionType::Charge,
            'balance_delta_nanodollars' => -1,
            'hold_delta_nanodollars' => 0,
        ]);
    })->throws(UniqueConstraintViolationException::class);
});

describe('out-of-order delivery', function () {
    it('ignores a processing event that arrives after success', function () {
        $generation = inFlight($this->user);

        deliverWebhook(ProviderPayload::eventFor($generation, 'succeeded'))->assertNoContent();
        deliverWebhook(ProviderPayload::eventFor($generation, 'processing', eventId: 'evt_late_processing'))
            ->assertNoContent();

        $generation->refresh();

        expect($generation->status)->toBe(GenerationStatus::Succeeded)
            ->and($generation->charged_nanodollars)->toBe(14_634_375)
            ->and(chargeRows($generation))->toBe(1)
            ->and(WebhookEvent::query()->where('event_id', 'evt_late_processing')->first()->ignored_reason)
            ->toBe('already_final');
    });

    it('ignores a queued event that arrives after processing', function () {
        $generation = inFlight($this->user);

        deliverWebhook(ProviderPayload::eventFor($generation, 'processing'))->assertNoContent();
        deliverWebhook(ProviderPayload::eventFor($generation, 'queued', eventId: 'evt_late_queued'))
            ->assertNoContent();

        expect($generation->refresh()->status)->toBe(GenerationStatus::Processing)
            ->and(WebhookEvent::query()->where('event_id', 'evt_late_queued')->first()->ignored_reason)
            ->toBe('out_of_order');
    });

    it('never moves a failed generation to succeeded', function () {
        $generation = inFlight($this->user);

        deliverWebhook(ProviderPayload::eventFor($generation, 'failed'))->assertNoContent();
        deliverWebhook(ProviderPayload::eventFor($generation, 'succeeded', eventId: 'evt_impossible'))
            ->assertNoContent();

        expect($generation->refresh()->status)->toBe(GenerationStatus::Failed)
            ->and($generation->charged_nanodollars)->toBe(0)
            ->and(chargeRows($generation))->toBe(0);
    });

    it('applies events delivered in the correct order normally', function () {
        $generation = inFlight($this->user);

        deliverWebhook(ProviderPayload::eventFor($generation, 'processing'))->assertNoContent();
        expect($generation->refresh()->status)->toBe(GenerationStatus::Processing);

        deliverWebhook(ProviderPayload::eventFor($generation, 'succeeded'))->assertNoContent();
        expect($generation->refresh()->status)->toBe(GenerationStatus::Succeeded);
    });
});

describe('a webhook that overtakes the create response', function () {
    it('matches a still-pending generation by the metadata we sent', function () {
        // No provider_id yet: this is exactly the documented race where "a
        // webhook can arrive before your POST /v1/generations has returned".
        $generation = heldGeneration($this->user, '0.05', [
            'status' => GenerationStatus::Pending,
            'provider_id' => null,
        ]);

        $payload = ProviderPayload::eventFor($generation, 'succeeded');
        $providerId = $payload['data']['object']['id'];

        deliverWebhook($payload)->assertNoContent();

        $generation->refresh();

        expect($generation->status)->toBe(GenerationStatus::Succeeded)
            // The provider id was adopted from the event.
            ->and($generation->provider_id)->toBe($providerId)
            ->and($generation->charged_nanodollars)->toBe(14_634_375)
            ->and(chargeRows($generation))->toBe(1);
    });

    it('does not let the late create response regress the status', function () {
        $generation = heldGeneration($this->user, '0.05', [
            'status' => GenerationStatus::Pending,
            'provider_id' => null,
        ]);

        $payload = ProviderPayload::eventFor($generation, 'succeeded');
        deliverWebhook($payload)->assertNoContent();

        // Now the create response finally arrives, reporting `queued`.
        $apply = app(ApplyProviderState::class);
        $outcome = $apply(
            $generation->refresh(),
            ProviderGeneration::fromArray(
                ProviderPayload::generation($generation->refresh(), 'queued', [
                    'id' => $payload['data']['object']['id'],
                ])
            )
        );

        expect($outcome->applied)->toBeFalse()
            ->and($outcome->reason)->toBe('already_final')
            ->and($generation->refresh()->status)->toBe(GenerationStatus::Succeeded)
            ->and(chargeRows($generation))->toBe(1);
    });

    it('records but does not apply an event it cannot match to any generation', function () {
        $orphan = Generation::factory()->make(['id' => (string) Str::ulid()]);

        deliverWebhook(ProviderPayload::event(
            'generation.succeeded',
            ProviderPayload::generation($orphan, 'succeeded', ['metadata' => []]),
            'evt_orphan'
        ))->assertNoContent();

        $event = WebhookEvent::query()->where('event_id', 'evt_orphan')->first();

        // Kept for the reconciler rather than discarded, and acknowledged so
        // Pixelforge stops retrying.
        expect($event)->not->toBeNull()
            ->and($event->ignored_reason)->toBe('unmatched')
            ->and($event->wasApplied())->toBeFalse();
    });
});

describe('a success report we cannot cost', function () {
    it('defers rather than applying a final status it could never settle', function () {
        $generation = inFlight($this->user);

        deliverWebhook(ProviderPayload::eventFor($generation, 'succeeded', eventId: 'evt_no_usage', overrides: [
            'usage' => null,
        ]))->assertNoContent();

        // Left unsettled on purpose, so the reconciler can fetch the real usage.
        expect($generation->refresh()->status)->toBe(GenerationStatus::Queued)
            ->and($generation->charged_nanodollars)->toBeNull()
            ->and(WebhookEvent::query()->where('event_id', 'evt_no_usage')->first()->ignored_reason)
            ->toBe('succeeded_without_usage');
    });
});

describe('rejection is our conclusion, not the provider\'s', function () {
    it('lets genuine provider news override a locally rejected generation', function () {
        // We gave up and marked it rejected; the provider then tells us it did
        // succeed. Ignoring that would leave us under-billed against /v1/usage.
        $generation = heldGeneration($this->user, '0.05', [
            'status' => GenerationStatus::Rejected,
            'provider_id' => null,
            'status_event_at' => now()->subMinute(),
            'provider_cost_nanodollars' => 0,
            'charged_nanodollars' => 0,
            'settled_at' => now()->subMinute(),
        ]);

        deliverWebhook(ProviderPayload::eventFor($generation, 'succeeded'))->assertNoContent();

        expect($generation->refresh()->status)->toBe(GenerationStatus::Succeeded)
            ->and($generation->charged_nanodollars)->toBe(14_634_375)
            ->and(chargeRows($generation))->toBe(1);
    });

    it('still ignores a stale queued event for a rejected generation', function () {
        $generation = heldGeneration($this->user, '0.05', [
            'status' => GenerationStatus::Rejected,
            'status_event_at' => now()->subMinute(),
        ]);

        deliverWebhook(ProviderPayload::eventFor($generation, 'queued', eventId: 'evt_stale_queued'))
            ->assertNoContent();

        expect($generation->refresh()->status)->toBe(GenerationStatus::Rejected);
    });
});
