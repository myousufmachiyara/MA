<?php
// app/Models/SettlementAllocation.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SettlementAllocation extends Model
{
    protected $fillable = ['settlement_id', 'sale_invoice_id', 'wht_amount', 'returned_value', 'cash_allocated', 'balance_after'];

    public function settlement()
    {
        return $this->belongsTo(Settlement::class);
    }

    public function invoice()
    {
        return $this->belongsTo(SaleInvoice::class, 'sale_invoice_id');
    }

    public function returnItems()
    {
        return $this->hasMany(SettlementReturnItem::class);
    }
}