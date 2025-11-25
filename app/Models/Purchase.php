<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Purchase extends Model
{
    protected $fillable = [
        'purchase_date',
        'supplier_name',
        'total_cost',
    ];

    protected $casts = [
        'purchase_date' => 'date',
        'total_cost'    => 'decimal:2',
    ];

    public function items()
    {
        return $this->hasMany(PurchaseItem::class);
    }
}
