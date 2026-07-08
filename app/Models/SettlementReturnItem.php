<?php
// app/Models/SettlementReturnItem.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SettlementReturnItem extends Model
{
    protected $fillable = ['settlement_allocation_id', 'item_id', 'variation_id', 'quantity', 'price', 'cost_price', 'line_value'];

    public function product()
    {
        return $this->belongsTo(Product::class, 'item_id');
    }

    public function variation()
    {
        return $this->belongsTo(ProductVariation::class, 'variation_id');
    }
}