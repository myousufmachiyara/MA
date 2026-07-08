<?php

namespace App\Http\Controllers;

use App\Models\PurchaseInvoice;
use App\Models\ChartOfAccounts;
use Illuminate\Http\Request;
use Carbon\Carbon;

class PurchaseReportController extends Controller
{
    public function purchaseReports(Request $request)
    {
        $vendors = ChartOfAccounts::where('account_type', 'vendor')->orderBy('name')->get();

        $from = $request->from_date ?? Carbon::now()->startOfMonth()->toDateString();
        $to   = $request->to_date   ?? Carbon::now()->toDateString();

        $reports = [
            'purchase_register' => $this->purchaseRegister($request, $from, $to),
            'vendor_wise'        => $this->vendorWise($request, $from, $to),
        ];

        return view('reports.purchase_reports', compact('reports', 'vendors', 'from', 'to'));
    }

    // ── TAB 1: PURCHASE REGISTER ─────────────────────────────────

    private function purchaseRegister(Request $request, string $from, string $to)
    {
        $query = PurchaseInvoice::with(['vendor', 'items'])
            ->whereBetween('invoice_date', [$from, $to]);

        if ($request->filled('vendor_id')) {
            $query->where('vendor_id', $request->vendor_id);
        }
        if ($request->has('view_deleted')) {
            $query->onlyTrashed();
        }

        return $query->orderByDesc('invoice_date')->get();
    }

    // ── TAB 2: VENDOR-WISE PURCHASE ──────────────────────────────

    private function vendorWise(Request $request, string $from, string $to)
    {
        $query = ChartOfAccounts::where('account_type', 'vendor')
            ->withCount(['purchaseInvoices as invoice_count' => function ($q) use ($from, $to) {
                $q->whereBetween('invoice_date', [$from, $to]);
            }])
            ->with(['purchaseInvoices' => function ($q) use ($from, $to) {
                $q->whereBetween('invoice_date', [$from, $to]);
            }]);

        if ($request->filled('vendor_id')) {
            $query->where('id', $request->vendor_id);
        }

        return $query->get()
            ->map(function ($vendor) {
                $totalAmount = $vendor->purchaseInvoices->sum('total_amount');
                $totalQty    = $vendor->purchaseInvoices->sum('total_quantity');

                return [
                    'vendor'         => $vendor,
                    'invoice_count'  => $vendor->invoice_count,
                    'total_amount'   => $totalAmount,
                    'total_quantity' => $totalQty,
                ];
            })
            ->filter(fn ($row) => $row['invoice_count'] > 0)
            ->sortByDesc('total_amount')
            ->values();
    }
}