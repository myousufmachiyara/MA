<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockMovement extends Model
{
    protected $fillable = [
        'item_id', 'variation_id', 'location_id', 'direction', 'quantity',
        'balance_after', 'reference_type', 'reference_id', 'remarks', 'created_by',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class, 'item_id');
    }

    public function variation()
    {
        return $this->belongsTo(ProductVariation::class, 'variation_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // FIX: was missing — needed by InventoryReportController's eager loads
    public function location()
    {
        return $this->belongsTo(Location::class);
    }

    private const REFERENCE_LABELS = [
        'purchase_invoice' => 'Purchase Invoice',
        'purchase_return'  => 'Purchase Return',
        'sale_invoice'     => 'Sale Invoice',
        'sale_return'      => 'Sale Return',
        'stock_adjustment' => 'Stock Adjustment',
        'stock_transfer'   => 'Stock Transfer',
    ];

    public function getReferenceLabelAttribute(): string
    {
        $label = self::REFERENCE_LABELS[$this->reference_type] ?? ucfirst(str_replace('_', ' ', $this->reference_type));
        return "{$label} #{$this->reference_id}";
    }

    public function getReferenceLinkAttribute(): ?string
    {
        return match ($this->reference_type) {
            'purchase_invoice' => route('purchase_invoices.print', $this->reference_id),
            'sale_invoice'     => route('sale_invoices.print', $this->reference_id),
            'sale_return'      => route('sale_return.show', $this->reference_id),
            'stock_adjustment' => route('stock_adjustments.show', $this->reference_id),
            default            => null,
        };
    }
}