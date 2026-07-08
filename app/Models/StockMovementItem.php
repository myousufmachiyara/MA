<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockMovementItem extends Model
{
    protected $table = 'stock_movement_items';

    protected $fillable = [
        'stock_adjustment_id', 'item_id', 'variation_id', 'direction',
        'quantity', 'unit_cost', 'stock_before', 'stock_after', 'remarks',
    ];

    public function adjustment()
    {
        return $this->belongsTo(StockAdjustment::class, 'stock_adjustment_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'item_id');
    }

    public function variation()
    {
        return $this->belongsTo(ProductVariation::class, 'variation_id');
    }
}