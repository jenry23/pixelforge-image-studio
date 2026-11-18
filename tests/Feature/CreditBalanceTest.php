<?php

declare(strict_types=1);

use App\Actions\CreateGeneration;
use App\Enums\GenerationQuality;
use App\Enums\GenerationSize;
use App\Enums\GenerationStatus;
use App\Enums\TransactionType;
use App\Exceptions\InsufficientCreditsException;
use App\Jobs\SubmitGeneration;
use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use App\Pixelforge\Money;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\Support\ProviderPayload;

beforeEach(function () {
    Http::preventStrayRequests();
    Http::fake(['*/pricing' => Http::response(pricingFixture())]);
    Bus::fake([SubmitGeneration::class]);
});

function submit(User $user, GenerationQuality $quality = GenerationQuality::Low): Generation
{
    return app(CreateGeneration::class)(
        user: $user,
        prompt: 'a brass astrolabe on dark blue velvet',
        size: GenerationSize::Square,
        quality: $quality,
    );
}

describe('reservations', function () {
    it('reserves the maximum possible charge, not the expected one', function () {
        $user = fundUser(User::factory()->create(), '5.00');

        $generation = submit($user);

        // The hold must cover the worst case, or a generation whose reasoning
        // lands above the midpoint could overdraw the account.
        expect($generation->hold_nanodollars)
            ->toBe($generation->estimated_charge_max_nanodollars)
            ->and($generation->hold_nanodollars)
            ->toBeGreaterThan($generation->estimated_charge_expected_nanodollars);
    });

    it('reduces available credit but not the balance', function () {
        $user = fundUser(User::factory()->create(), '5.00');

        $generation = submit($user);
        $user->refresh();

        // Nothing has been spent yet -- it is reserved, not charged.
        expect($user->balance()->toUsdString())->toBe('5.000000000')
            ->and($user->held()->nanodollars)->toBe($generation->hold_nanodollars)
            ->and($user->available()->nanodollars)
            ->toBe(Money::fromUsdString('5.00')->nanodollars - $generation->hold_nanodollars);
    });

    it('writes one hold row to the ledger', function () {
        $user = fundUser(User::factory()->create(), '5.00');

        $generation = submit($user);

        expect(CreditTransaction::query()
            ->where('generation_id', $generation->getKey())
            ->where('type', TransactionType::Hold)
            ->count())->toBe(1);
    });

    it('refuses a generation the user cannot afford and creates nothing', function () {
        // Enough for a low-quality generation, nowhere near a high-quality one.
        $user = fundUser(User::factory()->create(), '0.01');

        expect(fn () => submit($user, GenerationQuality::High))
            ->toThrow(InsufficientCreditsException::class);

        // The whole thing rolls back: no row, no hold, no provider contact.
        expect(Generation::query()->count())->toBe(0)
            ->and($user->refresh()->held()->isZero())->toBeTrue();

        Bus::assertNotDispatched(SubmitGeneration::class);
    });

    it('refuses a user with no credits at all', function () {
        $user = User::factory()->create();

        expect(fn () => submit($user))->toThrow(InsufficientCreditsException::class);
    });
});

describe('simultaneous submissions', function () {
    it('lets a user spend the same credits only once', function () {
        // A high-quality 1024x1024 generation reserves $0.340075 at most, so
        // $0.40 covers exactly one of them and no more.
        $user = fundUser(User::factory()->create(), '0.40');

        $first = submit($user, GenerationQuality::High);

        expect($first->hold_nanodollars)->toBe(340_075_000)
            ->and($user->refresh()->available()->toUsdString())->toBe('0.059925000');

        // The second submission sees the first one's hold and is refused, rather
        // than both succeeding against the same credits.
        expect(fn () => submit($user->refresh(), GenerationQuality::High))
            ->toThrow(InsufficientCreditsException::class);

        expect(Generation::query()->count())->toBe(1)
            ->and($user->refresh()->held()->nanodollars)->toBe(340_075_000);
    });

    it('serialises many submissions so holds never exceed the balance', function () {
        $user = fundUser(User::factory()->create(), '1.00');
        $created = 0;

        // Keep submitting until the account genuinely cannot cover another.
        for ($i = 0; $i < 100; $i++) {
            try {
                submit($user->refresh(), GenerationQuality::High);
                $created++;
            } catch (InsufficientCreditsException) {
                break;
            }
        }

        $user->refresh();

        expect($created)->toBeGreaterThan(0)
            // The invariant: reservations can never exceed what is owned.
            ->and($user->held()->nanodollars)->toBeLessThanOrEqual($user->balance()->nanodollars)
            ->and($user->available()->isNegative())->toBeFalse();
    });
});

describe('settlement against the reservation', function () {
    it('charges the actual cost and returns the difference to available credit', function () {
        $user = fundUser(User::factory()->create(), '5.00');
        $generation = submit($user);

        $held = $generation->hold_nanodollars;
        $generation->update([
            'status' => GenerationStatus::Queued,
            'provider_id' => 'gen_SETTLE0000000000000000001',
            'status_event_at' => now()->subSeconds(5),
        ]);

        deliverWebhook(ProviderPayload::eventFor($generation->refresh(), 'succeeded'))->assertNoContent();

        $user->refresh();

        // Actual charge was well below the reserved maximum, and the rest came back.
        expect($generation->refresh()->charged_nanodollars)->toBe(14_634_375)
            ->and($generation->charged_nanodollars)->toBeLessThan($held)
            ->and($user->held()->isZero())->toBeTrue()
            ->and($user->balance()->toUsdString())->toBe('4.985365625')
            ->and($user->available()->toUsdString())->toBe('4.985365625');
    });

    it('returns the whole reservation when a generation fails', function () {
        $user = fundUser(User::factory()->create(), '5.00');
        $generation = submit($user);

        $generation->update([
            'status' => GenerationStatus::Queued,
            'provider_id' => 'gen_FAILREL0000000000000001',
            'status_event_at' => now()->subSeconds(5),
        ]);

        deliverWebhook(ProviderPayload::eventFor($generation->refresh(), 'failed'))->assertNoContent();

        $user->refresh();

        expect($user->balance()->toUsdString())->toBe('5.000000000')
            ->and($user->held()->isZero())->toBeTrue()
            ->and($user->available()->toUsdString())->toBe('5.000000000');
    });
});

describe('the cached balance', function () {
    it('always agrees with the folded ledger', function () {
        $user = fundUser(User::factory()->create(), '5.00');
        $generation = submit($user);

        $generation->update([
            'status' => GenerationStatus::Queued,
            'provider_id' => 'gen_FOLD000000000000000001',
            'status_event_at' => now()->subSeconds(5),
        ]);
        deliverWebhook(ProviderPayload::eventFor($generation->refresh(), 'succeeded'))->assertNoContent();

        $user->refresh();
        $folded = $user->foldLedger();

        expect($folded['balance']->nanodollars)->toBe($user->balance_nanodollars)
            ->and($folded['held']->nanodollars)->toBe($user->held_nanodollars);
    });

    it('passes the ledger verification command', function () {
        $user = fundUser(User::factory()->create(), '5.00');
        $generation = submit($user);

        $generation->update([
            'status' => GenerationStatus::Queued,
            'provider_id' => 'gen_VERIFY00000000000000001',
            'status_event_at' => now()->subSeconds(5),
        ]);
        deliverWebhook(ProviderPayload::eventFor($generation->refresh(), 'succeeded'))->assertNoContent();

        $this->artisan('pixelforge:verify-ledger')->assertSuccessful();
    });

    it('fails the verification command when a cached balance is tampered with', function () {
        $user = fundUser(User::factory()->create(), '5.00');

        // Simulate the drift the command exists to catch.
        User::query()->whereKey($user->getKey())->update(['balance_nanodollars' => 999]);

        $this->artisan('pixelforge:verify-ledger')->assertFailed();
    });
});

describe('buying credits', function () {
    it('adds a credit pack through the ledger', function () {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('credits.store'), ['amount_usd' => '5.00'])
            ->assertRedirect();

        expect($user->refresh()->balance()->toUsdString())->toBe('5.000000000')
            ->and(CreditTransaction::query()->where('type', TransactionType::Topup)->count())->toBe(1);
    });

    it('rejects an arbitrary amount from the client', function () {
        $user = User::factory()->create();

        // Nothing should be able to mint a balance of its own choosing.
        $this->actingAs($user)
            ->post(route('credits.store'), ['amount_usd' => '1000000.00'])
            ->assertSessionHasErrors('amount_usd');

        expect($user->refresh()->balance()->isZero())->toBeTrue();
    });

    it('grants a welcome credit on registration', function () {
        $this->post('/register', [
            'name' => 'New Person',
            'email' => 'new@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        expect(User::query()->where('email', 'new@example.com')->first()->balance()->toUsdString())
            ->toBe('5.000000000');
    });
});

describe('blocking generation when unaffordable', function () {
    it('returns a validation error naming the shortfall', function () {
        $user = fundUser(User::factory()->create(), '0.01');

        $response = $this->actingAs($user)->post(route('generations.store'), [
            'prompt' => 'an expensive high quality generation',
            'size' => GenerationSize::Landscape->value,
            'quality' => GenerationQuality::High->value,
        ]);

        $response->assertSessionHasErrors('credits');
        expect(Generation::query()->count())->toBe(0);
    });

    it('allows it once credits are topped up', function () {
        $user = fundUser(User::factory()->create(), '0.01');

        $this->actingAs($user)->post(route('credits.store'), ['amount_usd' => '5.00']);

        $this->actingAs($user->refresh())->post(route('generations.store'), [
            'prompt' => 'an affordable generation',
            'size' => GenerationSize::Square->value,
            'quality' => GenerationQuality::Low->value,
        ])->assertRedirect(route('generations.index'));

        expect(Generation::query()->count())->toBe(1);
    });
});
