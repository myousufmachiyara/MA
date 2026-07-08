<?php
// app/Models/SaleOrder.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SaleOrder extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'order_no', 'customer_id', 'booker_id', 'order_date', 'status',
        'total_amount', 'total_quantity', 'payment_terms', 'remarks',
        'local_uuid', 'sync_status', 'booked_at', 'synced_at',
    ];

    protected $casts = [
        'booked_at' => 'datetime',
        'synced_at' => 'datetime',
    ];

    public function items()
    {
        return $this->hasMany(SaleOrderItem::class);
    }

    public function customer()
    {
        return $this->belongsTo(ChartOfAccounts::class, 'customer_id');
    }

    public function booker()
    {
        return $this->belongsTo(User::class, 'booker_id');
    }

    public function scopeUnmerged($query)
    {
        return $query->whereIn('status', ['draft', 'confirmed']);
    }
}