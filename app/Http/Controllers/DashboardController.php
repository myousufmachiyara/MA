<?php

namespace App\Http\Controllers;

use App\Models\SaleInvoice;
use App\Models\PurchaseInvoice;
use App\Models\SaleOrder;
use App\Models\DispatchTrip;
use App\Models\ChartOfAccounts;
use App\Models\Voucher;
use App\Models\AccountingEntry;
use App\Models\LocationStock;
use App\Models\User;
use App\Models\PurchaseOrder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class DashboardController extends Controller
{
    public function index()
    {
        $user  = auth()->user();
        $today = Carbon::today()->toDateString();
        $data  = [];

        // ── Sales snapshot ──────────────────────────────────────
        if ($user->can('sale_invoices.index')) {
            $data['todaySales'] = (float) SaleInvoice::whereDate('invoice_date', $today)->sum('total_amount');
            $data['monthSales'] = (float) SaleInvoice::whereMonth('invoice_date', now()->month)
                ->whereYear('invoice_date', now()->year)->sum('total_amount');
            $data['recentSales'] = SaleInvoice::with('customer')->latest('invoice_date')->take(5)->get();
        }

        // ── Purchases snapshot ───────────────────────────────────
        if ($user->can('purchase_invoices.index')) {
            $data['todayPurchases'] = (float) PurchaseInvoice::whereDate('invoice_date', $today)->sum('total_amount');
            $data['monthPurchases'] = (float) PurchaseInvoice::whereMonth('invoice_date', now()->month)
                ->whereYear('invoice_date', now()->year)->sum('total_amount');
            $data['recentPurchases'] = PurchaseInvoice::with('vendor')->latest('invoice_date')->take(5)->get();
        }

        // ── Receivables / Payables ────────────────────────────────
        if ($user->can('reports.accounts')) {
            $data['receivables'] = $this->partyTotal('customer', 'debit');
            $data['payables']    = $this->partyTotal('vendor', 'credit');
        }

        // ── Sale pipeline status ───────────────────────────────────
        if ($user->can('sale_orders.index')) {
            $data['pendingOrders'] = SaleOrder::whereIn('status', ['draft', 'confirmed'])->count();
        }
        if ($user->can('dispatch_trips.index')) {
            $data['tripsAwaitingSettlement'] = DispatchTrip::where('status', 'dispatched')->count();
            $data['tripsPlanned']            = DispatchTrip::where('status', 'planned')->count();
        }

        // ── Stock alerts ─────────────────────────────────────────
        if ($user->can('reports.inventory')) {
            $data['lowStockItems'] = $this->lowStockItems();
        }

        // ── Mobile workforce ─────────────────────────────────────
        if ($user->can('mobile_users.index')) {
            $data['activeBookers']  = User::where('user_type', 'mobile')->where('mobile_role', 'booker')->where('is_active', true)->count();
            $data['activeDrivers']  = User::where('user_type', 'mobile')->where('mobile_role', 'delivery_manager')->where('is_active', true)->count();
        }

        // ── Pending Purchase Orders (no invoice raised yet) ──────────
        if ($user->can('purchase_orders.index')) {
            $pendingPOsQuery = PurchaseOrder::with('vendor')
                ->whereDoesntHave('invoices')
                ->where('status', '!=', 'cancelled');

            $data['pendingPOsCount'] = (clone $pendingPOsQuery)->count();
            $data['pendingPOs'] = $pendingPOsQuery->latest('order_date')->take(10)->get();
        }
        
        // Inside index():
        if ($user->can('coa.index')) {
            $data['unreviewedCustomers'] = ChartOfAccounts::where('account_type', 'customer')
                ->where('is_reviewed', false)
                ->with('linkedUser') // not applicable here, safe to omit if unused
                ->latest()
                ->get();
        }

        return view('home', $data);
    }

    /**
     * Cumulative party balance across all customer or vendor accounts,
     * combining simple vouchers + multi-line accounting_entries + COA opening balance.
     */
    private function partyTotal(string $accountType, string $nature): float
    {
        $ids = ChartOfAccounts::where('account_type', $accountType)->pluck('id');
        if ($ids->isEmpty()) return 0.0;

        $opening = $accountType === 'customer'
            ? (float) ChartOfAccounts::whereIn('id', $ids)->sum('receivables')
            : (float) ChartOfAccounts::whereIn('id', $ids)->sum('payables');

        $simpleDr = (float) Voucher::whereNull('reference_type')->whereIn('ac_dr_sid', $ids)->whereNull('deleted_at')->sum('amount');
        $simpleCr = (float) Voucher::whereNull('reference_type')->whereIn('ac_cr_sid', $ids)->whereNull('deleted_at')->sum('amount');

        $complexDr = (float) AccountingEntry::whereIn('account_id', $ids)->whereHas('voucher', fn ($q) => $q->whereNull('deleted_at'))->sum('debit');
        $complexCr = (float) AccountingEntry::whereIn('account_id', $ids)->whereHas('voucher', fn ($q) => $q->whereNull('deleted_at'))->sum('credit');

        $totalDr = $simpleDr + $complexDr;
        $totalCr = $simpleCr + $complexCr;

        return $nature === 'debit' ? ($opening + $totalDr - $totalCr) : ($opening + $totalCr - $totalDr);
    }

    /**
     * Items at or below 10 units, summed across all locations —
     * reads location_stocks since it's maintained for both simple
     * and variable products regardless of variation.
     */
    private function lowStockItems()
    {
        return LocationStock::select('item_id', 'variation_id')
            ->selectRaw('SUM(quantity) as total_qty')
            ->groupBy('item_id', 'variation_id')
            ->havingRaw('SUM(quantity) <= 10')
            ->with(['product:id,name', 'variation:id,sku'])
            ->orderBy('total_qty')
            ->take(10)
            ->get();
    }
}