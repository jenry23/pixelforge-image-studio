<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\Hash;

it('creates an admin with a password typed at the prompt', function () {
    $this->artisan('pixelforge:create-admin')
        ->expectsQuestion('Name', 'Ops Lead')
        ->expectsQuestion('Email', 'ops@imagestudio.test')
        ->expectsQuestion('Password', 'a-long-enough-secret')
        ->expectsQuestion('Confirm password', 'a-long-enough-secret')
        ->assertSuccessful();

    $admin = User::query()->where('email', 'ops@imagestudio.test')->sole();

    expect($admin->is_admin)->toBeTrue()
        ->and($admin->email_verified_at)->not->toBeNull()
        ->and(Hash::check('a-long-enough-secret', $admin->password))->toBeTrue()
        // Not the shared demo password, and no starting credit.
        ->and(Hash::check('password', $admin->password))->toBeFalse()
        ->and($admin->balance()->isZero())->toBeTrue();
});

it('refuses a password that does not match its confirmation', function () {
    $this->artisan('pixelforge:create-admin')
        ->expectsQuestion('Name', 'Ops Lead')
        ->expectsQuestion('Email', 'ops@imagestudio.test')
        ->expectsQuestion('Password', 'a-long-enough-secret')
        ->expectsQuestion('Confirm password', 'something-else-entirely')
        ->assertFailed();

    expect(User::query()->where('email', 'ops@imagestudio.test')->exists())->toBeFalse();
});

it('refuses a short password', function () {
    $this->artisan('pixelforge:create-admin')
        ->expectsQuestion('Name', 'Ops Lead')
        ->expectsQuestion('Email', 'ops@imagestudio.test')
        ->expectsQuestion('Password', 'short')
        ->expectsQuestion('Confirm password', 'short')
        ->assertFailed();

    expect(User::query()->where('email', 'ops@imagestudio.test')->exists())->toBeFalse();
});

it('will not take an email that already exists', function () {
    User::factory()->create(['email' => 'taken@imagestudio.test']);

    $this->artisan('pixelforge:create-admin', ['--email' => 'taken@imagestudio.test', '--name' => 'Ops'])
        ->assertFailed();
});

it('refuses to seed demo accounts outside local and testing', function () {
    app()->detectEnvironment(fn (): string => 'production');

    expect(fn () => (new DemoSeeder)->run())
        ->toThrow(RuntimeException::class, 'will not run in production');

    // The known-password admin was never created.
    expect(User::query()->where('email', 'admin@example.com')->exists())->toBeFalse();
});
