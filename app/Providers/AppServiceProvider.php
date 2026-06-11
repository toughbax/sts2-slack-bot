<?php

namespace App\Providers;

use App\Import\FixtureProvider;
use App\Import\UntappedProvider;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->when(FixtureProvider::class)->needs('$path')->give(fn () => config('sts.fixture_path'));
        $this->app->when(UntappedProvider::class)->needs('$baseUrl')->give(fn () => config('sts.untapped.base_url'));
        $this->app->when(UntappedProvider::class)->needs('$delayMs')->give(fn () => config('sts.untapped.delay_ms'));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
