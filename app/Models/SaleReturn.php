<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class SaleReturn extends Model
{
    use HasFactory;

    protected $fillable = [
        'account_id',
        'return_date',
        'sale_invoice_no',
        'remarks',
        'created_by',
    ];

    // Relationships
    public function customer()
    {
        return $this->belongsTo(ChartOfAccounts::class, 'account_id');
    }

    public function items()
    {
        return $this->hasMany(SaleReturnItem::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function vouchers()
    {
        return $this->morphMany(Voucher::class, 'reference');
    }

    public function invoice()
    {
        return $this->belongsTo(SaleInvoice::class, 'sale_invoice_id');
    }
}
