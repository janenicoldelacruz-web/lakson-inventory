<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductBatch extends Model
{
    protected $fillable = [
        'product_id',
        'batch_code',
        'expiry_date',
        'quantity',
    ];

    protected $casts = [
        'expiry_date' => 'date',
        'quantity'    => 'decimal:2',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function purchaseItems()
    {
        return $this->hasMany(PurchaseItem::class, 'batch_id');
    }

    public function saleItems()
    {
        return $this->hasMany(SaleItem::class, 'batch_id');
    }

    public function stockMovements()
    {
        return $this->hasMany(StockMovement::class, 'batch_id');
    }
}
