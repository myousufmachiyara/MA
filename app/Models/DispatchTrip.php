<?php
// app/Models/DispatchTrip.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class DispatchTrip extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'trip_no', 'trip_date', 'vehicle_no', 'delivery_manager_id', 'status',
        'total_orders', 'total_amount', 'remarks', 'created_by', 'updated_by',
    ];

    public function deliveryManager()
    {
        return $this->belongsTo(User::class, 'delivery_manager_id');
    }

    public function orders()
    {
        return $this->belongsToMany(SaleOrder::class, 'dispatch_trip_orders');
    }

    public function invoices()
    {
        return $this->hasMany(SaleInvoice::class);
    }
}