<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductVariation extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'product_id',
        'sku',
        'selling_price',
        'cost_price', // FIX: was missing — PurchaseInvoiceController's update() was silently failing to save this
        'stock_quantity',
    ];

    protected $casts = [
        'selling_price'  => 'decimal:2',
        'cost_price'     => 'decimal:4', // update from decimal:2 if it's currently 2
        'stock_quantity' => 'decimal:2',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function purchaseItems()
    {
        return $this->hasMany(PurchaseInvoiceItem::class, 'variation_id');
    }

    public function saleItems()
    {
        return $this->hasMany(SaleInvoiceItem::class, 'variation_id');
    }

    public function attributeValues()
    {
        return $this->belongsToMany(AttributeValue::class, 'product_variation_attribute_values')->withTimestamps();
    }

    public function values()
    {
        return $this->hasMany(ProductVariationAttributeValue::class);
    }

    public function getMarginAttribute(): float
    {
        return round($this->selling_price - $this->cost_price, 2);
    }
}