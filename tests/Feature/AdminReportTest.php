<?php

declare(strict_types=1);

use App\Models\Generation;
use App\Models\User;
use App\Reporting\BillingReport;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    $this->admin = User::factory()->create(['is_admin' => true]);
});

/** A settled generation billed on a specific UTC day. */
function billedOn(string $date, User $user, int $providerCost, int $charged): Generation
{
    return Generation::factory()->succeeded()->for($user)->create([
        'billed_on_utc' => $date,
        'provider_completed_at' => CarbonImmutable::parse($date.' 12:00:00', 'UTC'),
        'provider_cost_nanodollars' => $providerCost,
        'charged_nanodollars' => $charged,
    ]);
}

/** Fake GET /v1/usage with the given per-day costs. */
function fakeUsage(array $costsByDate): void
{
    Http::fake(['*/usage*' => Http::response([
        'object' => 'usage',
        'start_date' => array_key_first($costsByDate),
        'end_date' => array_key_last($costsByDate),
        'data' => array_map(fn (string $date, string $cost): array => [
            'date' => $date,
            'generations' => 1,
            'input_text_tokens' => 0,
            'input_image_tokens' => 0,
            'output_tokens' => 0,
            'reasoning_tokens' => 0,
            'cost_usd' => $cost,
        ], array_keys($costsByDate), array_values($costsByDate)),
        'total' => [
            'generations' => count($costsByDate),
            'cost_usd' => '0.000000000',
        ],
    ])]);
}

function buildReport(string $start, string $end): array
{
    return app(BillingReport::class)->build(
        CarbonImmutable::parse($start, 'UTC'),
        CarbonImmutable::parse($end, 'UTC'),
    );
}

describe('daily totals', function () {
    it('reports credit spend, provider cost and profit for each day', function () {
        billedOn('2026-11-17', $this->admin, 11_707_500, 14_634_375);
        fakeUsage(['2026-11-17' => '0.011707500']);

        $report = buildReport('2026-11-17', '2026-11-17');
        $day = $report['days'][0];

        expect($day['date'])->toBe('2026-11-17')
            ->and($day['generations'])->toBe(1)
            ->and($day['credit_spend']['exact'])->toBe('0.014634375')
            ->and($day['provider_cost']['exact'])->toBe('0.011707500')
            // Profit is the 25% markup, exactly.
            ->and($day['profit']['exact'])->toBe('0.002926875');
    });

    it('includes days with no activity, matching the provider\'s zero-filled response', function () {
        billedOn('2026-11-17', $this->admin, 11_707_500, 14_634_375);
        fakeUsage(['2026-11-17' => '0.011707500']);

        $report = buildReport('2026-11-15', '2026-11-18');

        expect($report['days'])->toHaveCount(4)
            ->and($report['days'][0]['date'])->toBe('2026-11-15')
            ->and($report['days'][0]['generations'])->toBe(0)
            ->and($report['days'][0]['credit_spend']['exact'])->toBe('0.000000000');
    });

    it('sums many sub-cent generations without drift', function () {
        for ($i = 0; $i < 300; $i++) {
            billedOn('2026-11-17', $this->admin, 11_707_500, 14_634_375);
        }
        fakeUsage(['2026-11-17' => '3.512250000']);

        $report = buildReport('2026-11-17', '2026-11-17');

        // 300 x 0.0117075 = 3.51225 exactly; 300 x 0.014634375 = 4.3903125.
        expect($report['totals']['provider_cost']['exact'])->toBe('3.512250000')
            ->and($report['totals']['credit_spend']['exact'])->toBe('4.390312500')
            ->and($report['totals']['profit']['exact'])->toBe('0.878062500')
            ->and($report['days'][0]['reconciled'])->toBeTrue();
    });

    it('excludes failed generations entirely', function () {
        Generation::factory()->failed()->for($this->admin)->create(['billed_on_utc' => null]);
        fakeUsage(['2026-11-17' => '0.000000000']);

        $report = buildReport('2026-11-17', '2026-11-17');

        expect($report['totals']['generations'])->toBe(0)
            ->and($report['totals']['credit_spend']['exact'])->toBe('0.000000000');
    });
});

describe('the provider\'s UTC billing window', function () {
    it('buckets a generation on the day it completed, not the day it was created', function () {
        // Created 2026-11-18 23:59:50, completed 2026-11-19 00:00:10.
        Generation::factory()->succeeded()->for($this->admin)->create([
            'created_at' => CarbonImmutable::parse('2026-11-18 23:59:45', 'UTC'),
            'provider_created_at' => CarbonImmutable::parse('2026-11-18 23:59:50', 'UTC'),
            'provider_completed_at' => CarbonImmutable::parse('2026-11-19 00:00:10', 'UTC'),
            'billed_on_utc' => '2026-11-19',
            'provider_cost_nanodollars' => 11_707_500,
            'charged_nanodollars' => 14_634_375,
        ]);

        fakeUsage(['2026-11-18' => '0.000000000', '2026-11-19' => '0.011707500']);

        $report = buildReport('2026-11-18', '2026-11-19');
        $byDate = collect($report['days'])->keyBy('date');

        // The provider bills this on the 19th. So must we.
        expect($byDate['2026-11-18']['generations'])->toBe(0)
            ->and($byDate['2026-11-19']['generations'])->toBe(1)
            ->and($byDate['2026-11-19']['reconciled'])->toBeTrue()
            ->and($byDate['2026-11-18']['reconciled'])->toBeTrue();
    });
});

describe('reconciliation against GET /v1/usage', function () {
    it('reports a zero delta when our figures match the provider exactly', function () {
        billedOn('2026-11-17', $this->admin, 11_707_500, 14_634_375);
        fakeUsage(['2026-11-17' => '0.011707500']);

        $report = buildReport('2026-11-17', '2026-11-17');

        expect($report['days'][0]['delta']['exact'])->toBe('0.000000000')
            ->and($report['days'][0]['reconciled'])->toBeTrue()
            ->and($report['reconciliation']['available'])->toBeTrue()
            ->and($report['reconciliation']['matches'])->toBeTrue();
    });

    it('surfaces a discrepancy rather than hiding it', function () {
        billedOn('2026-11-17', $this->admin, 11_707_500, 14_634_375);

        // The provider says it billed more than we recorded -- exactly the
        // signature of a generation we paid for but never settled.
        fakeUsage(['2026-11-17' => '0.023415000']);

        $report = buildReport('2026-11-17', '2026-11-17');

        expect($report['days'][0]['reconciled'])->toBeFalse()
            ->and($report['days'][0]['delta']['exact'])->toBe('0.011707500')
            ->and($report['reconciliation']['matches'])->toBeFalse();
    });

    it('detects a discrepancy of a single nanodollar', function () {
        billedOn('2026-11-17', $this->admin, 11_707_500, 14_634_375);
        fakeUsage(['2026-11-17' => '0.011707501']);

        $report = buildReport('2026-11-17', '2026-11-17');

        // Exact integer comparison, not a tolerance.
        expect($report['days'][0]['reconciled'])->toBeFalse()
            ->and($report['days'][0]['delta']['exact'])->toBe('0.000000001');
    });

    it('says so explicitly when the usage endpoint is unavailable', function () {
        billedOn('2026-11-17', $this->admin, 11_707_500, 14_634_375);

        Http::fake(['*/usage*' => Http::response(['error' => [
            'code' => 'overloaded', 'type' => 'api_error', 'message' => 'Temporarily overloaded.',
        ]], 503)]);

        $report = buildReport('2026-11-17', '2026-11-17');

        // Our own numbers are still shown, but never presented as verified.
        expect($report['reconciliation']['available'])->toBeFalse()
            ->and($report['reconciliation']['matches'])->toBeNull()
            ->and($report['reconciliation']['error'])->not->toBeNull()
            ->and($report['days'][0]['provider_reported_cost'])->toBeNull()
            ->and($report['days'][0]['delta'])->toBeNull()
            ->and($report['days'][0]['reconciled'])->toBeNull()
            ->and($report['totals']['provider_cost']['exact'])->toBe('0.011707500');
    });

    it('survives a timeout to the usage endpoint', function () {
        Http::fake(['*/usage*' => fn () => throw new ConnectionException('timed out')]);

        $report = buildReport('2026-11-17', '2026-11-17');

        expect($report['reconciliation']['available'])->toBeFalse()
            ->and($report['reconciliation']['error'])->toContain('Pixelforge');
    });
});

describe('per-user totals', function () {
    it('breaks spend down by user, highest first', function () {
        $alice = User::factory()->create(['name' => 'Alice']);
        $bob = User::factory()->create(['name' => 'Bob']);

        billedOn('2026-11-17', $alice, 11_707_500, 14_634_375);
        billedOn('2026-11-17', $alice, 11_707_500, 14_634_375);
        billedOn('2026-11-17', $bob, 11_707_500, 14_634_375);

        fakeUsage(['2026-11-17' => '0.035122500']);

        $report = buildReport('2026-11-17', '2026-11-17');

        expect($report['users'])->toHaveCount(2)
            ->and($report['users'][0]['name'])->toBe('Alice')
            ->and($report['users'][0]['generations'])->toBe(2)
            ->and($report['users'][0]['credit_spend']['exact'])->toBe('0.029268750')
            ->and($report['users'][1]['name'])->toBe('Bob')
            ->and($report['users'][1]['credit_spend']['exact'])->toBe('0.014634375');
    });

    it('spans users and days together', function () {
        $alice = User::factory()->create(['name' => 'Alice']);
        $bob = User::factory()->create(['name' => 'Bob']);

        billedOn('2026-11-16', $alice, 11_707_500, 14_634_375);
        billedOn('2026-11-17', $bob, 11_707_500, 14_634_375);

        fakeUsage(['2026-11-16' => '0.011707500', '2026-11-17' => '0.011707500']);

        $report = buildReport('2026-11-16', '2026-11-17');

        expect($report['totals']['generations'])->toBe(2)
            ->and($report['users'])->toHaveCount(2)
            ->and($report['totals']['credit_spend']['exact'])->toBe('0.029268750');
    });
});

describe('access', function () {
    it('lets an admin see the report page', function () {
        fakeUsage(['2026-11-17' => '0.000000000']);

        $this->actingAs($this->admin)
            ->get(route('admin.report'))
            ->assertSuccessful();
    });

    it('hides the report from an ordinary user', function () {
        // 404 rather than 403, so the admin area is not advertised.
        $this->actingAs(User::factory()->create(['is_admin' => false]))
            ->get(route('admin.report'))
            ->assertNotFound();
    });

    it('requires signing in', function () {
        $this->get(route('admin.report'))->assertRedirect(route('login'));
    });
});
