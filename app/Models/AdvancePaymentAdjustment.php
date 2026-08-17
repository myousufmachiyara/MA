<?php
// app/Models/AdvancePaymentAdjustment.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdvancePaymentAdjustment extends Model
{
    protected $fillable = ['advance_payment_id', 'invoice_type', 'invoice_id', 'amount_adjusted', 'adjustment_date', 'created_by'];

    public function advancePayment() { return $this->belongsTo(AdvancePayment::class); }
}