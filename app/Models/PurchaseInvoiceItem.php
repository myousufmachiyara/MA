<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseInvoiceItem extends Model
{
    protected $fillable = [
        'purchase_invoice_id',
        'item_id',
        'variation_id',
        'quantity',
        'unit',
        'price',
        'remarks',        
    ];

    public function invoice()
    {
        return $this->belongsTo(PurchaseInvoice::class, 'purchase_invoice_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'item_id');
    }

    public function variation()
    {
        return $this->belongsTo(ProductVariation::class, 'variation_id');
    }
    
    public function measurementUnit()
    {
        return $this->belongsTo(MeasurementUnit::class, 'unit');
    }
}
