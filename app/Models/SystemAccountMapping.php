<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SystemAccountMapping extends Model
{
    protected $fillable = ['key', 'label', 'description', 'account_id', 'updated_by'];

    public function account()
    {
        return $this->belongsTo(ChartOfAccounts::class, 'account_id');
    }
}