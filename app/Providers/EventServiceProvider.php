<?php
namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Purchase;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\ProductCategory;
use App\Models\Brand;
use App\Models\ProductBatch;
use App\Observers\AuditLogObserver;

class EventServiceProvider extends ServiceProvider
{
    public function boot()
    {
        // Register the observer for multiple models
        Product::observe(AuditLogObserver::class);        // Track Product changes
        Sale::observe(AuditLogObserver::class);           // Track Sale changes
        Purchase::observe(AuditLogObserver::class);      // Track Purchase changes
        StockMovement::observe(AuditLogObserver::class); // Track StockMovement changes
        User::observe(AuditLogObserver::class);          // Track User changes
        ProductCategory::observe(AuditLogObserver::class); // Track ProductCategory changes
        Brand::observe(AuditLogObserver::class);          // Track Brand changes
        ProductBatch::observe(AuditLogObserver::class);   // Track ProductBatch changes
    }
}
