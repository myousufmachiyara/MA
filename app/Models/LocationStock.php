<?php
// app/Models/LocationStock.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LocationStock extends Model
{
    protected $fillable = ['item_id', 'variation_id', 'location_id', 'quantity'];

    public function product()
    {
        return $this->belongsTo(Product::class, 'item_id');
    }

    public function variation()
    {
        return $this->belongsTo(ProductVariation::class, 'variation_id');
    }

    public function location()
    {
        return $this->belongsTo(Location::class);
    }
}