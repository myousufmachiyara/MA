<?php
// app/Models/Settlement.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Settlement extends Model
{
    protected $fillable = [
        'settlement_no', 'dispatch_trip_id', 'settlement_date',
        'total_cash_received', 'total_returned_value', 'total_wht_amount',
        'cleared_to_office', 'cleared_at', 'cleared_by', 'remarks', 'created_by',
    ];

    protected $casts = ['cleared_to_office' => 'boolean', 'cleared_at' => 'datetime'];

    public function dispatchTrip()
    {
        return $this->belongsTo(DispatchTrip::class);
    }

    public function allocations()
    {
        return $this->hasMany(SettlementAllocation::class);
    }
}