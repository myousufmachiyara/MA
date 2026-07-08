<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class StockAdjustment extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'adjustment_no', 'adjustment_date', 'location_id', 'reason_type', 'remarks',
        'total_increase_value', 'total_decrease_value', 'created_by', 'updated_by',
    ];

    public function items()
    {
        return $this->hasMany(StockMovementItem::class, 'stock_adjustment_id');
    }

    public function location()
    {
        return $this->belongsTo(Location::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}