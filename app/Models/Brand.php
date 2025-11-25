<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

class Brand extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'product_category_id',
        'name',
    ];

    /**
     * A brand belongs to one category
     */
    public function category()
    {
        return $this->belongsTo(ProductCategory::class, 'product_category_id');
    }

    /**
     * A brand can be associated with many products
     */
    public function products()
    {
        return $this->hasMany(Product::class, 'brand_id');
    }
}
