<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class SaleReturnItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'sale_return_id',
        'product_id',
        'variation_id',
        'qty',
        'price',
    ];

    // Relationships
    public function saleReturn()
    {
        return $this->belongsTo(SaleReturn::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function variation()
    {
        return $this->belongsTo(ProductVariation::class, 'variation_id');
    }
}
