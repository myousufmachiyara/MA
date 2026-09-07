<?php
// app/Models/TripAdhocSaleItem.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TripAdhocSaleItem extends Model
{
    protected $fillable = ['trip_adhoc_sale_id', 'product_id', 'variation_id', 'quantity', 'price'];

    public function product() { return $this->belongsTo(Product::class); }
    public function variation() { return $this->belongsTo(ProductVariation::class, 'variation_id'); }
}