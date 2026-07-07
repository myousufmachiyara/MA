<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseReturnItem extends Model
{
    protected $fillable = ['purchase_return_id', 'item_id', 'variation_id' , 'purchase_invoice_id', 'quantity', 'unit_id', 'price'];

    public function item()
    {
        return $this->belongsTo(Product::class, 'item_id');
    }

    public function unit()
    {
        return $this->belongsTo(MeasurementUnit::class, 'unit_id');
    }

    public function return()
    {
        return $this->belongsTo(PurchaseReturn::class, 'purchase_return_id');
    }
    
    public function invoice()
    {
        return $this->belongsTo(PurchaseInvoice::class, 'purchase_invoice_id');
    }

    public function variation()
    {
        return $this->belongsTo(ProductVariation::class, 'variation_id');
    }
}
