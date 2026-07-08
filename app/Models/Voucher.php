<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Voucher extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'voucher_no', 'voucher_type', 'voucher_date',
        'reference_type', 'reference_id',
        'ac_dr_sid', 'ac_cr_sid', 'amount',
        'remarks', 'attachments', 'created_by',
    ];

    protected $casts = [
        'voucher_date' => 'date',
        'attachments'  => 'array',
        'amount'       => 'decimal:2',
    ];

    public function debitAccount()
    {
        return $this->belongsTo(ChartOfAccounts::class, 'ac_dr_sid', 'id');
    }

    public function creditAccount()
    {
        return $this->belongsTo(ChartOfAccounts::class, 'ac_cr_sid', 'id');
    }

    public function entries()
    {
        return $this->hasMany(AccountingEntry::class);
    }

    public function reference()
    {
        return $this->morphTo();
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeType($query, $type)
    {
        return $query->where('voucher_type', $type);
    }

    // ── Accessors ────────────────────────────────────────────────

    public function getIsAutoAttribute(): bool
    {
        return !is_null($this->reference_type);
    }

    public function getIsSimpleAttribute(): bool
    {
        return !$this->is_auto;
    }

    /**
     * FIX: multi-line vouchers (postEntries) read from `entries`.
     * Manual vouchers (post) still read from the single ac_dr_sid pair.
     */
    public function getDisplayDebitsAttribute(): array
    {
        if ($this->is_auto) {
            return $this->entries
                ->where('debit', '>', 0)
                ->map(fn ($e) => ['account' => $e->account->name ?? 'N/A', 'amount' => (float) $e->debit])
                ->values()->all();
        }

        return [['account' => $this->debitAccount->name ?? 'N/A', 'amount' => (float) $this->amount]];
    }

    public function getDisplayCreditsAttribute(): array
    {
        if ($this->is_auto) {
            return $this->entries
                ->where('credit', '>', 0)
                ->map(fn ($e) => ['account' => $e->account->name ?? 'N/A', 'amount' => (float) $e->credit])
                ->values()->all();
        }

        return [['account' => $this->creditAccount->name ?? 'N/A', 'amount' => (float) $this->amount]];
    }

    public function getDisplayTotalAttribute(): float
    {
        return $this->is_auto ? (float) $this->entries->sum('debit') : (float) $this->amount;
    }

    private const REFERENCE_LABELS = [
        \App\Models\PurchaseInvoice::class  => 'Purchase Invoice',
        \App\Models\SaleInvoice::class      => 'Sale Invoice',
        \App\Models\SaleReturn::class       => 'Sale Return',
        \App\Models\StockAdjustment::class  => 'Stock Adjustment',
        \App\Models\Settlement::class       => 'Settlement',
    ];

    public function getReferenceLabelAttribute(): ?string
    {
        if (!$this->reference_type || !$this->reference_id) return null;

        $label = self::REFERENCE_LABELS[$this->reference_type] ?? class_basename($this->reference_type);
        return "{$label} #{$this->reference_id}";
    }

    public function getReferenceLinkAttribute(): ?string
    {
        if (!$this->reference_type || !$this->reference_id) return null;

        return match ($this->reference_type) {
            \App\Models\PurchaseInvoice::class => route('purchase_invoices.print', $this->reference_id),
            \App\Models\SaleInvoice::class     => route('sale_invoices.print', $this->reference_id),
            \App\Models\SaleReturn::class      => route('sale_return.show', $this->reference_id),
            \App\Models\StockAdjustment::class => route('stock_adjustments.show', $this->reference_id),
            \App\Models\Settlement::class      => route('settlements.show', $this->reference_id),
            default => null,
        };
    }
}