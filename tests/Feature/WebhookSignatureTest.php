<?php

declare(strict_types=1);

use App\Models\Generation;
use App\Models\WebhookEvent;
use App\Pixelforge\WebhookSignature;
use Tests\Support\ProviderPayload;

beforeEach(function () {
    $this->generation = Generation::factory()->queued()->create();
});

it('accepts a correctly signed delivery', function () {
    deliverWebhook(ProviderPayload::eventFor($this->generation, 'processing'))
        ->assertNoContent();

    expect(WebhookEvent::query()->count())->toBe(1);
});

it('rejects a delivery signed with the wrong secret and records nothing', function () {
    deliverWebhook(
        ProviderPayload::eventFor($this->generation, 'succeeded'),
        secret: 'whsec_an_attacker_guess',
    )->assertStatus(400);

    // Nothing unverified is ever persisted.
    expect(WebhookEvent::query()->count())->toBe(0)
        ->and($this->generation->refresh()->status->value)->toBe('queued');
});

it('rejects a delivery whose body was tampered with after signing', function () {
    $event = ProviderPayload::eventFor($this->generation, 'succeeded');

    // Signature computed over the real body, but a different body delivered --
    // which is why the HMAC must be taken over the raw request, not a re-encode.
    deliverWebhook($event, rawBodyOverride: json_encode([...$event, 'type' => 'generation.failed']))
        ->assertStatus(400);

    expect(WebhookEvent::query()->count())->toBe(0);
});

it('rejects a correctly signed but stale delivery as a replay', function () {
    deliverWebhook(
        ProviderPayload::eventFor($this->generation, 'succeeded'),
        timestamp: time() - 301,
    )->assertStatus(400);

    expect(WebhookEvent::query()->count())->toBe(0);
});

it('accepts a delivery just inside the freshness tolerance', function () {
    deliverWebhook(
        ProviderPayload::eventFor($this->generation, 'processing'),
        timestamp: time() - 299,
    )->assertNoContent();
});

it('rejects a delivery with no signature header', function () {
    $this->postJson('/webhooks/pixelforge', ProviderPayload::eventFor($this->generation, 'succeeded'))
        ->assertStatus(400);

    expect(WebhookEvent::query()->count())->toBe(0);
});

it('rejects a malformed signature header', function () {
    $event = ProviderPayload::eventFor($this->generation, 'succeeded');

    $this->call(
        method: 'POST',
        uri: '/webhooks/pixelforge',
        server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_PIXELFORGE_SIGNATURE' => 'this-is-not-a-signature',
        ],
        content: json_encode($event),
    )->assertStatus(400);
});

it('refuses to process webhooks when no signing secret is configured', function () {
    config(['services.pixelforge.webhook_secret' => '']);

    // A non-2xx makes the misconfiguration visible in the Pixelforge dashboard
    // rather than silently accepting unverified events.
    deliverWebhook(ProviderPayload::eventFor($this->generation, 'succeeded'), secret: 'anything')
        ->assertStatus(500);

    expect(WebhookEvent::query()->count())->toBe(0);
});

it('acknowledges a ping test event', function () {
    deliverWebhook([
        'id' => 'evt_ping_1',
        'object' => 'event',
        'type' => 'ping',
        'created_at' => now()->toIso8601ZuluString(),
        'data' => ['object' => null],
    ])->assertNoContent();

    expect(WebhookEvent::query()->where('event_id', 'evt_ping_1')->first()->wasApplied())->toBeTrue();
});

it('is reachable without a CSRF token', function () {
    // Pixelforge has no session and no CSRF token; the route must not require one.
    deliverWebhook(ProviderPayload::eventFor($this->generation, 'processing'))
        ->assertNoContent();
})->group('csrf');

describe('payload shape, after the signature verifies', function () {
    it('rejects a body that is not a JSON object', function () {
        // Correctly signed, but a JSON array rather than an event object.
        deliverWebhook([], rawBodyOverride: json_encode([1, 2, 3]))->assertStatus(400);

        expect(WebhookEvent::query()->count())->toBe(0);
    });

    it('rejects a body with no event id', function () {
        $event = ProviderPayload::eventFor($this->generation, 'succeeded');
        unset($event['id']);

        // The id is never taken from the unsigned header, so this must fail.
        deliverWebhook($event)->assertStatus(400);

        expect(WebhookEvent::query()->count())->toBe(0);
    });

    it('rejects a non-string event type instead of coercing it', function () {
        $event = ProviderPayload::eventFor($this->generation, 'succeeded');
        $event['type'] = ['generation.succeeded'];

        deliverWebhook($event)->assertStatus(400);

        expect(WebhookEvent::query()->count())->toBe(0);
    });

    it('rejects an empty event type', function () {
        $event = ProviderPayload::eventFor($this->generation, 'succeeded');
        $event['type'] = '   ';

        deliverWebhook($event)->assertStatus(400);
    });

    it('ignores the unsigned id header entirely', function () {
        // Headers are not covered by the HMAC, so a mutated id header must not
        // be able to slip a duplicate past deduplication.
        $event = ProviderPayload::eventFor($this->generation, 'succeeded', eventId: 'evt_signed_id');

        deliverWebhook($event)->assertNoContent();
        test()->call(
            method: 'POST',
            uri: '/webhooks/pixelforge',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_PIXELFORGE_SIGNATURE' => WebhookSignature::sign(
                    json_encode($event, JSON_THROW_ON_ERROR),
                    (string) config('services.pixelforge.webhook_secret'),
                ),
                'HTTP_PIXELFORGE_EVENT_ID' => 'evt_attacker_supplied',
            ],
            content: json_encode($event, JSON_THROW_ON_ERROR),
        )->assertNoContent();

        // One record, keyed on the id inside the signed body.
        expect(WebhookEvent::query()->count())->toBe(1)
            ->and(WebhookEvent::query()->first()->event_id)->toBe('evt_signed_id');
    });
});
