<?php

namespace App\Providers;

use App\Scoring\Contracts\ScoringEngine;
use App\Scoring\InterpreterEngine;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(ScoringEngine::class, InterpreterEngine::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
