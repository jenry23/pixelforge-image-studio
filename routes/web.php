<?php

use App\Http\Controllers\Admin\BillingExportController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\CreditController;
use App\Http\Controllers\GenerationController;
use App\Http\Controllers\PixelforgeWebhookController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => auth()->check()
    ? redirect()->route('generations.create')
    : redirect()->route('login'));

Route::middleware(['auth', 'verified', 'no-store', 'encrypt-history'])->group(function () {
    Route::redirect('/dashboard', '/generate')->name('dashboard');

    Route::get('/generate', [GenerationController::class, 'create'])->name('generations.create');
    Route::post('/generate', [GenerationController::class, 'store'])->name('generations.store');

    Route::get('/generate/estimate', [GenerationController::class, 'estimate'])
        ->name('generations.estimate');

    Route::get('/history', [GenerationController::class, 'index'])->name('generations.index');

    Route::post('/credits', [CreditController::class, 'store'])->name('credits.store');
});

Route::middleware(['auth', 'verified', 'admin', 'no-store', 'encrypt-history'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::get('/report', ReportController::class)->name('report');

        Route::get('/report/export.xlsx', [BillingExportController::class, 'workbook'])
            ->name('report.export.workbook');

        Route::get('/report/export/daily.csv', [BillingExportController::class, 'daily'])
            ->name('report.export.daily');

        Route::get('/report/export/by-user.csv', [BillingExportController::class, 'users'])
            ->name('report.export.users');
    });

Route::middleware(['auth', 'no-store', 'encrypt-history'])->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

Route::post('/webhooks/pixelforge', PixelforgeWebhookController::class)
    ->name('webhooks.pixelforge');

require __DIR__.'/auth.php';
