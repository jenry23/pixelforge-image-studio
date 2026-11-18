<?php

use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\CreditController;
use App\Http\Controllers\GenerationController;
use App\Http\Controllers\PixelforgeWebhookController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => auth()->check()
    ? redirect()->route('generations.create')
    : redirect()->route('login'));

Route::middleware(['auth', 'verified'])->group(function () {
    Route::redirect('/dashboard', '/generate')->name('dashboard');

    Route::get('/generate', [GenerationController::class, 'create'])->name('generations.create');
    Route::post('/generate', [GenerationController::class, 'store'])->name('generations.store');

    // Debounced live quote. GET because it is a pure read with no side effects,
    // which also keeps it clear of CSRF plumbing. One implementation of the
    // pricing maths, in PHP, shared by this endpoint and the submission path.
    Route::get('/generate/estimate', [GenerationController::class, 'estimate'])
        ->name('generations.estimate');

    Route::get('/history', [GenerationController::class, 'index'])->name('generations.index');

    Route::post('/credits', [CreditController::class, 'store'])->name('credits.store');
});

Route::middleware(['auth', 'verified', 'admin'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::get('/report', ReportController::class)->name('report');
    });

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

/*
 * Pixelforge webhooks.
 *
 * Deliberately outside the auth and CSRF-protected flows: Pixelforge has no
 * session and no CSRF token. Authenticity comes from the HMAC signature on the
 * raw body instead, which is checked before anything in the payload is trusted.
 * The CSRF exclusion is declared in bootstrap/app.php.
 */
Route::post('/webhooks/pixelforge', PixelforgeWebhookController::class)
    ->name('webhooks.pixelforge');

require __DIR__.'/auth.php';
