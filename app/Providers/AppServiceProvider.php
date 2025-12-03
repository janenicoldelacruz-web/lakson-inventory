<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Route;
use App\Http\Middleware\CorsMiddleware;

class AppServiceProvider extends ServiceProvider
{
    public function boot()
    {
        // Register CorsMiddleware globally for web and api routes
        Route::middleware([CorsMiddleware::class])->group(function () {
            // Any route added here will automatically have the CORS middleware applied.
        });
    }

    public function register()
    {
        //
    }
}
