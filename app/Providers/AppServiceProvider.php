<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

// Observer
use App\Observers\AuditLogObserver;

// Models to observe
use App\Models\Product;
use App\Models\Sale;
use App\Models\Purchase;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\ProductCategory;
use App\Models\Brand;
use App\Models\ProductBatch;

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
        // TEMP: prove that this boot() is executed
        //dd('AppServiceProvider boot() is running');

        // Attach the AuditLogObserver to all relevant models
        Product::observe(AuditLogObserver::class);
        Sale::observe(AuditLogObserver::class);
        Purchase::observe(AuditLogObserver::class);
        StockMovement::observe(AuditLogObserver::class);
        User::observe(AuditLogObserver::class);
        ProductCategory::observe(AuditLogObserver::class);
        Brand::observe(AuditLogObserver::class);
        ProductBatch::observe(AuditLogObserver::class);
    }
}
