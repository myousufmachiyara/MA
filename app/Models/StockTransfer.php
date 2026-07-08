<?php
// app/Models/StockTransfer.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class StockTransfer extends Model
{
    use SoftDeletes;

    protected $fillable = ['transfer_no', 'transfer_date', 'from_location_id', 'to_location_id', 'remarks', 'created_by'];

    public function items()
    {
        return $this->hasMany(StockTransferItem::class);
    }

    public function fromLocation()
    {
        return $this->belongsTo(Location::class, 'from_location_id');
    }

    public function toLocation()
    {
        return $this->belongsTo(Location::class, 'to_location_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}