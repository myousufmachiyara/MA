<?php
// app/Models/SaleInvoice.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SaleInvoice extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'invoice_no', 'customer_id', 'sale_order_id', 'dispatch_trip_id', 'location_id', 'invoice_date',
        'payment_terms', 'is_tax_invoice', 'gst_type', 'gst_rate', 'gst_amount',
        'wht_applicable', 'wht_rate', 'wht_amount',
        'total_quantity', 'net_amount', 'total_amount', 'cogs_amount', 'paid_amount',
        'remarks', 'created_by', 'updated_by',
    ];

    public function items()
    {
        return $this->hasMany(SaleInvoiceItem::class);
    }

    public function customer()
    {
        return $this->belongsTo(ChartOfAccounts::class, 'customer_id');
    }

    public function saleOrder()
    {
        return $this->belongsTo(SaleOrder::class);
    }

    public function dispatchTrip()
    {
        return $this->belongsTo(DispatchTrip::class);
    }

    public function getBalanceDueAttribute(): float
    {
        return round($this->total_amount - $this->paid_amount, 2);
    }

    public function location()
    {
        return $this->belongsTo(Location::class);
    }
}