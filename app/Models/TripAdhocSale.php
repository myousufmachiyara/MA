<?php
// app/Models/TripAdhocSale.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TripAdhocSale extends Model
{
    protected $fillable = ['dispatch_trip_id', 'customer_id', 'existing_sale_invoice_id', 'payment_terms', 'remarks', 'status', 'processed_sale_invoice_id', 'created_by'];

    public function items() { return $this->hasMany(TripAdhocSaleItem::class); }
    public function customer() { return $this->belongsTo(ChartOfAccounts::class, 'customer_id'); }
    public function dispatchTrip() { return $this->belongsTo(DispatchTrip::class); }
    public function existingInvoice() { return $this->belongsTo(SaleInvoice::class, 'existing_sale_invoice_id'); }
}