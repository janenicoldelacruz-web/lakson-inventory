<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

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
}
