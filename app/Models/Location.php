<?php
// app/Models/Location.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Location extends Model
{
    use SoftDeletes;

    protected $fillable = ['name', 'code', 'address', 'contact_no', 'is_default', 'is_active', 'created_by'];

    protected $casts = ['is_default' => 'boolean', 'is_active' => 'boolean'];

    public function stocks()
    {
        return $this->hasMany(LocationStock::class);
    }
}