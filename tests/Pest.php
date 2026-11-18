<?php

use App\Actions\LedgerWriter;
use App\Models\Generation;
use App\Models\User;
use App\Pixelforge\Money;
use App\Pixelforge\WebhookSignature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Deliver a webhook to the app exactly as Pixelforge would: a raw JSON body
 * signed with the key's secret, plus the delivery headers.
 *
 * @param  array<string, mixed>  $event
 */
function deliverWebhook(
    array $event,
    ?string $secret = null,
    ?int $timestamp = null,
    int $deliveryAttempt = 1,
    ?string $rawBodyOverride = null,
): TestResponse {
    $rawBody = json_encode($event, JSON_THROW_ON_ERROR);
    $secret ??= (string) config('services.pixelforge.webhook_secret');

    // Signed over the body we claim to send, so a mismatch between the signed
    // bytes and the delivered bytes is exactly the tampering case.
    $signature = WebhookSignature::sign($rawBody, $secret, $timestamp);

    return test()->call(
        method: 'POST',
        uri: '/webhooks/pixelforge',
        server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_PIXELFORGE_SIGNATURE' => $signature,
            'HTTP_PIXELFORGE_EVENT_ID' => $event['id'] ?? 'evt_unknown',
            'HTTP_PIXELFORGE_EVENT_TYPE' => $event['type'] ?? 'unknown',
            'HTTP_PIXELFORGE_DELIVERY_ATTEMPT' => (string) $deliveryAttempt,
        ],
        content: $rawBodyOverride ?? $rawBody,
    );
}

/** The live GET /v1/pricing response, captured as a fixture. */
function pricingFixture(): array
{
    return json_decode(
        (string) file_get_contents(__DIR__.'/Fixtures/pricing.json'),
        true,
        flags: JSON_THROW_ON_ERROR
    );
}

/** Give a user spendable credits through the real ledger path. */
function fundUser(User $user, string $usd): User
{
    $ledger = app(LedgerWriter::class);

    $ledger->forUser($user->getKey(), fn (User $locked) => $ledger->topup(
        $locked,
        Money::fromUsdString($usd),
        'Test funding'
    ));

    return $user->refresh();
}

/**
 * A generation with a real credit hold against it, created the way the app
 * creates one, so settlement tests exercise genuine ledger state.
 */
function heldGeneration(User $user, string $holdUsd = '0.05', array $state = []): Generation
{
    $ledger = app(LedgerWriter::class);

    return $ledger->forUser($user->getKey(), function (User $locked) use ($ledger, $holdUsd, $state) {
        $generation = Generation::factory()->for($locked)->create($state);

        $ledger->hold($locked, $generation, Money::fromUsdString($holdUsd));

        return $generation->refresh();
    });
}
