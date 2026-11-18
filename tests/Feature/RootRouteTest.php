<?php

declare(strict_types=1);

use App\Models\User;

it('sends a guest to the login page', function () {
    $this->get('/')->assertRedirect(route('login'));
});

it('sends a signed-in user straight to the generate page', function () {
    $this->actingAs(User::factory()->create())
        ->get('/')
        ->assertRedirect(route('generations.create'));
});

it('keeps the health check available', function () {
    $this->get('/up')->assertSuccessful();
});
