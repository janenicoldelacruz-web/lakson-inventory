<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockMovement extends Model
{
    protected $fillable = [
        'product_id',
        'batch_id',
        'movement_type',
        'reference_type',
        'reference_id',
        'quantity_change',
        'remarks',
    ];

    protected $casts = [
        'quantity_change' => 'decimal:2',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function batch()
    {
        return $this->belongsTo(ProductBatch::class, 'batch_id');
    }
}
