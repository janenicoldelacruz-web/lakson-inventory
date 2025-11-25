<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SaleItem extends Model
{
    protected $fillable = [
        'sale_id',
        'product_id',
        'batch_id',
        'quantity',
        'unit_price',
        'line_total',
        'unit_cost_at_sale',
        'line_cost',
    ];

    protected $casts = [
        'quantity'          => 'decimal:2',
        'unit_price'        => 'decimal:2',
        'line_total'        => 'decimal:2',
        'unit_cost_at_sale' => 'decimal:2',
        'line_cost'         => 'decimal:2',
    ];

    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function batch()
    {
        return $this->belongsTo(ProductBatch::class, 'batch_id');
    }
}
