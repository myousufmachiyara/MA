<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SaleInvoice extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'invoice_no',
        'date',
        'remarks',
        'account_id',
        'type',
        'discount',
        'created_by',
    ];

    public function items()
    {
        return $this->hasMany(SaleInvoiceItem::class);
    }

    public function account()
    {
        return $this->belongsTo(ChartOfAccounts::class, 'account_id');
    }
}

