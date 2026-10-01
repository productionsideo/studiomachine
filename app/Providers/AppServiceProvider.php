<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Toute l'interface est en français : les dates aussi (« mardi 6 octobre »).
        \Illuminate\Support\Carbon::setLocale('fr');
    }
}
