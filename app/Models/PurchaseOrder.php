<?php
// app/Models/PurchaseOrder.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PurchaseOrder extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'order_no', 'vendor_id', 'order_date', 'status',
        'total_amount', 'total_quantity', 'remarks',
        'created_by', 'updated_by',
    ];

    public function items()
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function vendor()
    {
        return $this->belongsTo(ChartOfAccounts::class, 'vendor_id');
    }

    public function invoices()
    {
        return $this->hasMany(PurchaseInvoice::class, 'purchase_order_id');
    }

    /**
     * Recompute status from item-level received quantities.
     * Call this after every invoice create/update/delete that touches this PO.
     */
    public function refreshStatus(): void
    {
        $items = $this->items()->get();

        if ($items->isEmpty()) {
            return;
        }

        $totalReceived = $items->sum('received_quantity');

        if ($items->every(fn ($i) => $i->received_quantity >= $i->quantity)) {
            $this->status = 'completed';
        } elseif ($totalReceived > 0) {
            $this->status = 'partial';
        } else {
            $this->status = 'pending';
        }

        $this->save();
    }
}