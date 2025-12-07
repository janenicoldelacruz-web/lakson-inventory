<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use App\Observers\AuditLogObserver;

use App\Models\Product;
use App\Models\Sale;
use App\Models\Purchase;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\ProductCategory;
use App\Models\Brand;
use App\Models\ProductBatch;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event listener mappings for the application.
     *
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        // you can keep your events here if you have any
    ];

    public function boot(): void
    {
        parent::boot();

        // Attach one observer to many models
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
