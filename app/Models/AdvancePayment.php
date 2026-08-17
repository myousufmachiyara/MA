<?php
// app/Models/AdvancePayment.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class AdvancePayment extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'advance_no', 'party_id', 'party_type', 'payment_date',
        'cash_bank_account_id', 'amount', 'remaining_amount', 'remarks', 'created_by',
    ];

    public function party() { return $this->belongsTo(ChartOfAccounts::class, 'party_id'); }
    public function cashBankAccount() { return $this->belongsTo(ChartOfAccounts::class, 'cash_bank_account_id'); }
    public function adjustments() { return $this->hasMany(AdvancePaymentAdjustment::class); }
}