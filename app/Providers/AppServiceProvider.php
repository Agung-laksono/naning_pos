<?php

namespace App\Providers;

use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Vite;
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
        Vite::prefetch(concurrency: 3);

        // Jika diakses lewat Ngrok atau reverse proxy HTTPS, paksa skema HTTPS
        if (
            (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ||
            (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ||
            str_contains(request()->header('host', ''), 'ngrok')
        ) {
            URL::forceScheme('https');
        }
    }
}
