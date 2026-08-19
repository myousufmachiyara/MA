<?php

namespace App\Http\Controllers;

use App\Models\SaleInvoice;
use App\Models\SaleInvoiceItem;
use App\Models\DispatchTrip;
use App\Models\ChartOfAccounts;
use Illuminate\Http\Request;
use Carbon\Carbon;

class SalesReportController extends Controller
{
    public function saleReports(Request $request)
    {
        $customers = ChartOfAccounts::where('account_type', 'customer')
            ->orderBy('name')
            ->get();

        $from = $request->from_date ?? Carbon::now()->startOfMonth()->toDateString();
        $to   = $request->to_date ?? Carbon::now()->toDateString();

        $reports = [
            'sale_register'   => $this->saleRegister($request, $from, $to),
            'dispatch_report' => $this->dispatchReport($request, $from, $to),
            'item_wise'       => $this->itemWise($request, $from, $to),
            'customer_wise'   => $this->customerWise($request, $from, $to),
            'monthly_summary' => $this->monthlySummary($request),
        ];

        return view(
            'reports.sales_reports',
            compact('reports', 'customers', 'from', 'to')
        );
    }

    // ── TAB 1: SALE REGISTER ─────────────────────────────────────

    private function saleRegister(Request $request, string $from, string $to)
    {
        $query = SaleInvoice::with(['customer', 'dispatchTrip'])
            ->whereBetween('invoice_date', [$from, $to]);

        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }
        if ($request->filled('source')) {
            $request->source === 'manual'
                ? $query->whereNull('dispatch_trip_id')
                : $query->whereNotNull('dispatch_trip_id');
        }

        return $query->orderByDesc('invoice_date')->get();
    }

    // ── TAB 2: DISPATCH REPORT ───────────────────────────────────

    private function dispatchReport(Request $request, string $from, string $to)
    {
        $query = DispatchTrip::with(['deliveryManager', 'invoices'])
            ->whereBetween('trip_date', [$from, $to]);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        return $query->orderByDesc('trip_date')->get();
    }

    // ── TAB 3: ITEM-WISE SALE ────────────────────────────────────

    private function itemWise(Request $request, string $from, string $to)
    {
        $query = SaleInvoiceItem::with(['product', 'variation'])
            ->whereHas('invoice', function ($q) use ($from, $to, $request) {
                $q->whereBetween('invoice_date', [$from, $to]);
                if ($request->filled('customer_id')) {
                    $q->where('customer_id', $request->customer_id);
                }
            });

        $items = $query->get();

        return $items->groupBy(fn ($item) => $item->item_id . '-' . ($item->variation_id ?? '0'))
            ->map(function ($group) {
                $first    = $group->first();
                $qty      = $group->sum('quantity');
                $revenue  = $group->sum(fn ($i) => $i->quantity * $i->price);
                $cogs     = $group->sum(fn ($i) => $i->quantity * $i->cost_price);

                return [
                    'item'      => $first->product->name ?? 'N/A',
                    'variation' => $first->variation->sku ?? '—',
                    'quantity'  => $qty,
                    'revenue'   => $revenue,
                    'cogs'      => $cogs,
                    'profit'    => $revenue - $cogs,
                ];
            })
            ->sortByDesc('revenue')
            ->values();
    }

    // ── TAB 4: CUSTOMER-WISE SALE ────────────────────────────────

    private function customerWise(Request $request, string $from, string $to)
    {
        $query = ChartOfAccounts::where('account_type', 'customer')
            ->withCount(['saleInvoices as invoice_count' => function ($q) use ($from, $to) {
                $q->whereBetween('invoice_date', [$from, $to]);
            }])
            ->with(['saleInvoices' => function ($q) use ($from, $to) {
                $q->whereBetween('invoice_date', [$from, $to]);
            }]);

        if ($request->filled('customer_id')) {
            $query->where('id', $request->customer_id);
        }

        return $query->get()
            ->map(function ($customer) {
                $totalAmount = $customer->saleInvoices->sum('total_amount');
                $totalQty    = $customer->saleInvoices->sum('total_quantity');
                $totalPaid   = $customer->saleInvoices->sum('paid_amount');

                return [
                    'customer'       => $customer,
                    'invoice_count'  => $customer->invoice_count,
                    'total_quantity' => $totalQty,
                    'total_amount'   => $totalAmount,
                    'total_paid'     => $totalPaid,
                    'outstanding'    => $totalAmount - $totalPaid,
                ];
            })
            ->filter(fn ($row) => $row['invoice_count'] > 0)
            ->sortByDesc('total_amount')
            ->values();
    }

    private function monthlySummary(Request $request)
    {
        $year = $request->filled('year') ? (int) $request->year : now()->year;

        $invoices = SaleInvoice::whereYear('invoice_date', $year)->get();

        $monthly = collect(range(1, 12))->map(function ($month) use ($invoices) {
            $monthInvoices = $invoices->filter(fn ($i) => \Carbon\Carbon::parse($i->invoice_date)->month == $month);
            return [
                'month'   => \Carbon\Carbon::create()->month($month)->format('F'),
                'count'   => $monthInvoices->count(),
                'amount'  => $monthInvoices->sum('total_amount'),
                'cogs'    => $monthInvoices->sum('cogs_amount'),
                'profit'  => $monthInvoices->sum('total_amount') - $monthInvoices->sum('cogs_amount'),
            ];
        });

        $bookerBreakdown = SaleOrder::whereYear('order_date', $year)
            ->where('status', 'invoiced')
            ->with('booker')
            ->get()
            ->groupBy(fn ($o) => $o->booker->name ?? 'N/A')
            ->map(fn ($group) => ['count' => $group->count(), 'amount' => $group->sum('total_amount')]);

        return ['year' => $year, 'monthly' => $monthly, 'bookerBreakdown' => $bookerBreakdown];
    }
}