<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PurchaseInvoiceItemPart extends Model
{
    use HasFactory;

    protected $fillable = [
        'purchase_invoice_item_id',
        'product_id',
        'variation_id',
        'qty',
        'rate',
        'stone',
        'total',
        'part_description',
    ];

    public function item()
    {
        return $this->belongsTo(PurchaseInvoiceItem::class, 'purchase_invoice_item_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function variation()
    {
        return $this->belongsTo(ProductVariation::class);
    }
}

