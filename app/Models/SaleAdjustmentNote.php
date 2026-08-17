<?php
// app/Models/SaleAdjustmentNote.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SaleAdjustmentNote extends Model
{
    use SoftDeletes;

    protected $fillable = ['note_no', 'note_type', 'sale_invoice_id', 'note_date', 'amount', 'reason', 'remarks', 'created_by'];

    public function invoice() { return $this->belongsTo(SaleInvoice::class, 'sale_invoice_id'); }
}