<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SaleReturnItem extends Model
{
    protected $fillable = [
        'sale_id',
        'sale_item_id',
        'product_id',
        'batch_id',
        'quantity',
        'unit_price',
        'line_total',
        'unit_cost_at_return',
        'line_cost',
    ];

    protected $casts = [
        'quantity'           => 'decimal:2',
        'unit_price'         => 'decimal:2',
        'line_total'         => 'decimal:2',
        'unit_cost_at_return'=> 'decimal:2',
        'line_cost'          => 'decimal:2',
    ];

    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }

    public function saleItem()
    {
        return $this->belongsTo(SaleItem::class, 'sale_item_id');
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
