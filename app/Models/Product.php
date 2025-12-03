<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\AuditLog;  // Import the AuditLog model

class Product extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'product_category_id',
        'brand_id',
        'sku',
        'name',
        'base_unit',
        'cost_price',
        'selling_price',
        'current_stock',
        'reorder_level',
        'status',
    ];

    protected $casts = [
        'cost_price'    => 'decimal:2',
        'selling_price' => 'decimal:2',
        'current_stock' => 'decimal:2',
    ];

    public function category()
    {
        return $this->belongsTo(ProductCategory::class, 'product_category_id');
    }

    public function brand()
    {
        return $this->belongsTo(Brand::class, 'brand_id');
    }
    
    public function batches()
    {
        return $this->hasMany(ProductBatch::class);
    }

    public function purchaseItems()
    {
        return $this->hasMany(PurchaseItem::class);
    }

    public function saleItems()
    {
        return $this->hasMany(SaleItem::class);
    }

    public function stockMovements()
    {
        return $this->hasMany(StockMovement::class);
    }

    // Model events to track changes for audit logging
    protected static function booted()
{
    static::created(function ($product) {
        AuditLog::create([
            'table_name' => 'products',
            'action' => 'created',
            'user_id' => auth()->id(),  // Assuming you are using auth()
            'old_data' => null,
            'new_data' => json_encode($product->getAttributes()),
        ]);
    });

    static::updated(function ($product) {
        AuditLog::create([
            'table_name' => 'products',
            'action' => 'updated',
            'user_id' => auth()->id(),
            'old_data' => json_encode($product->getOriginal()),
            'new_data' => json_encode($product->getChanges()),
        ]);
    });

    static::deleted(function ($product) {
        AuditLog::create([
            'table_name' => 'products',
            'action' => 'deleted',
            'user_id' => auth()->id(),
            'old_data' => json_encode($product->getOriginal()),
            'new_data' => null,
        ]);
    });
}
}