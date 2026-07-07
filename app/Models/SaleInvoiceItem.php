<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SaleInvoiceItem extends Model
{
    protected $fillable = [
        'sale_invoice_id',
        'product_id',
        'variation_id',
        'sale_price',
        'quantity',
    ];

    public function saleInvoice()
    {
        return $this->belongsTo(SaleInvoice::class, 'sale_invoice_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function variation()
    {
        return $this->belongsTo(ProductVariation::class, 'variation_id');
    }
}

