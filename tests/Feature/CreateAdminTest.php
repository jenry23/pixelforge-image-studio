<?php

declare(strict_types=1);

use App\Models\User;
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

it('takes the password from the environment when there is no tty', function () {
    putenv('ADMIN_INITIAL_PASSWORD=a-long-enough-secret');

    $this->artisan('pixelforge:create-admin', [
        '--name' => 'Ops Lead',
        '--email' => 'ops-ci@imagestudio.test',
        '--no-interaction' => true,
    ])->assertSuccessful();

    $admin = User::query()->where('email', 'ops-ci@imagestudio.test')->sole();

    expect($admin->is_admin)->toBeTrue()
        ->and(Hash::check('a-long-enough-secret', $admin->password))->toBeTrue();

    putenv('ADMIN_INITIAL_PASSWORD');
});

it('explains itself when there is no tty and no password in the environment', function () {
    putenv('ADMIN_INITIAL_PASSWORD');

    $this->artisan('pixelforge:create-admin', [
        '--name' => 'Ops Lead',
        '--email' => 'ops-none@imagestudio.test',
        '--no-interaction' => true,
    ])->expectsOutputToContain('ADMIN_INITIAL_PASSWORD')
        ->assertFailed();

    expect(User::query()->where('email', 'ops-none@imagestudio.test')->exists())->toBeFalse();
});
