<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccountingEntry extends Model
{
    protected $fillable = [
        'voucher_id',
        'account_id',
        'debit',
        'credit',
        'narration',
    ];

    protected $casts = [
        'debit'  => 'decimal:2',
        'credit' => 'decimal:2',
    ];

    public function voucher()
    {
        return $this->belongsTo(Voucher::class);
    }

    public function account()
    {
        return $this->belongsTo(ChartOfAccounts::class, 'account_id');
    }
}