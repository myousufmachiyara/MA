<?php
// app/Models/DispatchTripOrder.php — pivot model, used only if you need extra logic later

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DispatchTripOrder extends Model
{
    protected $fillable = ['dispatch_trip_id', 'sale_order_id'];
}