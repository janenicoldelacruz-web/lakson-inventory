<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Sale extends Model
{
    protected $fillable = [
        'sale_date',
        'sale_type',
        'customer_name',
        'customer_contact',
        'status',
        'total_amount',
    ];

    protected $casts = [
        'sale_date'    => 'date',
        'total_amount' => 'decimal:2',
    ];

    public function items()
    {
        return $this->hasMany(SaleItem::class);
    }

}
