<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

// Custom middleware
use App\Http\Middleware\RoleMiddleware;

return Application::configure(basePath: dirname(__DIR__))

    // Routing
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )

    // Middleware
    ->withMiddleware(function (Middleware $middleware): void {

        // CORS middleware (Laravel 12 auto-registers this)
        $middleware->append(\Illuminate\Http\Middleware\HandleCors::class);

        // Alias custom middleware
        $middleware->alias([
            'role' => RoleMiddleware::class,
        ]);
    })

    // ❌ REMOVE ->withProviders() completely 
    // Laravel 12 auto-loads framework service providers.

    // Exceptions
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })

    ->create();
