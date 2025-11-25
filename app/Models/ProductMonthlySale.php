<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductMonthlySale extends Model
{
    protected $fillable = [
        'product_id',
        'year',
        'month',
        'total_quantity_sold',
        'total_sales_amount'
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
