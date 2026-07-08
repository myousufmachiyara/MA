<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ChartOfAccounts extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'shoa_id',
        'name',
        'account_code',
        'account_type',
        'customer_type',
        'is_gst_registered',
        'gst_number',
        'filer_status',
        'wht_applicable',
        'wht_rate',
        'receivables',
        'payables',
        'credit_limit',
        'opening_date',
        'remarks',
        'address',
        'contact_no',
        'linked_user_id',
        'is_system_account',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'is_gst_registered'  => 'boolean',
        'wht_applicable'     => 'boolean',
        'is_system_account'  => 'boolean',
        'is_active'          => 'boolean',
        'wht_rate'           => 'decimal:2',
        'receivables'        => 'decimal:2',
        'payables'           => 'decimal:2',
        'credit_limit'       => 'decimal:2',
        'opening_date'       => 'date',
    ];

    // ── Relationships ──────────────────────────────────────────────

    public function subHeadOfAccount()
    {
        return $this->belongsTo(SubHeadOfAccounts::class, 'shoa_id', 'id');
    }

    public function purchaseInvoices()
    {
        return $this->hasMany(PurchaseInvoice::class, 'vendor_id');
    }

    // app/Models/ChartOfAccounts.php
    public function saleInvoices()
    {
        return $this->hasMany(SaleInvoice::class, 'customer_id');
    }

    // A delivery-manager clearing account (or any user-linked account) belongs to a User
    public function linkedUser()
    {
        return $this->belongsTo(User::class, 'linked_user_id');
    }

    // ── Scopes ──────────────────────────────────────────────────────

    public function scopeCustomers($query)
    {
        return $query->where('account_type', 'customer');
    }

    public function scopeVendors($query)
    {
        return $query->where('account_type', 'vendor');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeWhtApplicable($query)
    {
        return $query->where('wht_applicable', true);
    }

    // ── Business helpers ──────────────────────────────────────────

    /**
     * Suggest a WHT rate based on customer type + filer status.
     * These are placeholder slabs — plug in your actual FBR rate card.
     * Always returns a rate; the UI lets the user override per account.
     */
    public static function suggestWhtRate(?string $customerType, ?string $filerStatus): float
    {
        if ($filerStatus === 'filer') {
            return $customerType === 'wholesaler' ? 0.5 : 1.0;
        }
        // non-filer rates are typically higher
        return $customerType === 'wholesaler' ? 1.0 : 2.0;
    }

    /**
     * Get (or create) the Delivery Manager Clearing account for a given user.
     * Call this when a delivery-manager mobile user is created/activated,
     * so every delivery manager automatically has a clearing sub-ledger
     * without needing a manual COA entry.
     */
    public static function getOrCreateDeliveryClearingAccount(User $user): self
    {
        $existing = self::where('linked_user_id', $user->id)
            ->where('account_type', 'delivery_clearing')
            ->first();

        if ($existing) {
            return $existing;
        }

        // "Delivery Manager Clearing" accounts sit under Assets (cash-in-transit).
        // Adjust shoa_id lookup to whichever sub-head you use for this in your seeder.
        $subHead = SubHeadOfAccounts::firstOrCreate(
            ['name' => 'Delivery Manager Clearing', 'hoa_id' => 1],
        );

        $prefix = '1' . str_pad($subHead->id, 2, '0', STR_PAD_LEFT);
        $lastCode = self::withTrashed()
            ->where('account_code', 'like', $prefix . '%')
            ->max('account_code');
        $nextNumber = $lastCode ? (intval(substr($lastCode, strlen($prefix))) + 1) : 1;

        return self::create([
            'shoa_id'            => $subHead->id,
            'account_code'       => $prefix . str_pad($nextNumber, 3, '0', STR_PAD_LEFT),
            'name'               => 'Delivery Clearing - ' . $user->name,
            'account_type'       => 'delivery_clearing',
            'linked_user_id'     => $user->id,
            'receivables'        => 0,
            'payables'           => 0,
            'credit_limit'       => 0,
            'opening_date'       => now(),
            'is_system_account'  => true,
            'created_by'         => auth()->id() ?? $user->id,
            'updated_by'         => auth()->id() ?? $user->id,
        ]);
    }
}