<?php

declare(strict_types=1);

namespace App\Providers;

use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\PricingCatalogRepository;
use App\Pixelforge\PricingEngine;
use Illuminate\Support\ServiceProvider;

class PixelforgeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PixelforgeClient::class, fn () => PixelforgeClient::fromConfig());

        $this->app->singleton(PricingCatalogRepository::class, fn ($app) => new PricingCatalogRepository(
            client: $app->make(PixelforgeClient::class),
        ));

        // The markup lives in configuration, but it is read once here so there is
        // a single engine instance and a single source of the rate.
        $this->app->singleton(PricingEngine::class, fn () => new PricingEngine(
            markupPercent: (int) config('services.pixelforge.markup_percent', 25),
        ));
    }
}
