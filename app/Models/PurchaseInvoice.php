<?php
// app/Models/PurchaseInvoice.php — replace with this version

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PurchaseInvoice extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'invoice_no', 'vendor_id', 'purchase_order_id', 'invoice_date',
        'bill_no', 'ref_no', 'remarks',
        'total_amount', 'total_quantity', 'net_amount',
        'is_tax_invoice', 'gst_type', 'gst_rate', 'gst_amount',
        'created_by', 'updated_by',
    ];

    public function items()
    {
        return $this->hasMany(PurchaseInvoiceItem::class, 'purchase_invoice_id');
    }

    public function vendor()
    {
        return $this->belongsTo(ChartOfAccounts::class, 'vendor_id');
    }

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function attachments()
    {
        return $this->hasMany(PurchaseInvoiceAttachment::class);
    }
}