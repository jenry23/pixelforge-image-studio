<?php

declare(strict_types=1);

use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\Http;

describe('signed-out access', function () {
    it('redirects a guest away from every authenticated page', function (string $uri) {
        $this->get($uri)->assertRedirect(route('login'));
    })->with([
        '/generate',
        '/history',
        '/generate/estimate?size=1024x1024&quality=low',
        '/admin/report',
        '/profile',
        '/dashboard',
    ]);

    it('rejects a generation submitted while signed out', function () {
        $this->post(route('generations.store'), [
            'prompt' => 'should never run',
            'size' => '1024x1024',
            'quality' => 'low',
        ])->assertRedirect(route('login'));

        expect(Generation::query()->count())->toBe(0);
    });

    it('rejects a credit purchase made while signed out', function () {
        $this->post(route('credits.store'), ['amount_usd' => '20.00'])
            ->assertRedirect(route('login'));

        expect(CreditTransaction::query()->count())->toBe(0);
    });
});

describe('admin authorisation', function () {
    it('keeps billing hidden from an ordinary user', function () {
        $this->actingAs(User::factory()->create(['is_admin' => false]))
            ->get(route('admin.report'))
            ->assertNotFound();
    });

    it('allows an operator through', function () {
        Http::fake(['*/usage*' => Http::response([
            'object' => 'usage', 'data' => [], 'total' => ['cost_usd' => '0.000000000'],
        ])]);

        $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->get(route('admin.report'))
            ->assertSuccessful();
    });
});

describe('stale page protection', function () {
    it('marks authenticated pages as no-store so the browser cannot replay them', function (string $uri) {
        $response = $this->actingAs(User::factory()->create())->get($uri);

        expect($response->headers->get('Cache-Control'))->toContain('no-store');
    })->with(['/generate', '/history', '/profile']);

    it('tells Inertia to clear its history on logout', function () {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('logout'))->assertRedirect('/');

        // Inertia drops the key that decrypts previously stored pages, so a
        // Back navigation cannot re-render the signed-in page from history.
        expect(session('inertia.clear_history'))->toBeTrue();
        $this->assertGuest();
    });

    it('invalidates the session and rotates the CSRF token on logout', function () {
        $user = User::factory()->create();

        $this->actingAs($user);
        $before = session()->token();

        $this->post(route('logout'));

        expect(session()->token())->not->toBe($before);
        $this->assertGuest();
    });
});
