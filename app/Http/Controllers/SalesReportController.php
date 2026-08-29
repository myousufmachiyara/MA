<?php

namespace App\Http\Controllers;

use App\Models\SaleInvoice;
use App\Models\SaleInvoiceItem;
use App\Models\User;
use App\Models\SaleOrder;
use App\Models\SaleReturn;  
use App\Models\DispatchTrip;
use App\Models\ChartOfAccounts;
use Illuminate\Http\Request;
use Carbon\Carbon;
use App\Traits\ExportsCsv;

class SalesReportController extends Controller
{
    use ExportsCsv;

    public function saleReports(Request $request)
    {
        $customers = ChartOfAccounts::where('account_type', 'customer')
            ->orderBy('name')
            ->get();

        $from = $request->from_date ?? Carbon::now()->startOfMonth()->toDateString();
        $to   = $request->to_date ?? Carbon::now()->toDateString();
        $bookers = User::where('user_type', 'mobile')->orderBy('name')->get();

        $reports = [
            'sale_register'   => $this->saleRegister($request, $from, $to),
            'dispatch_report' => $this->dispatchReport($request, $from, $to),
            'item_wise'       => $this->itemWise($request, $from, $to),
            'customer_wise'   => $this->customerWise($request, $from, $to),
            'booker_wise' => $this->bookerWise($request, $from, $to),
            'monthly_summary' => $this->monthlySummary($request),
            'sale_return'     => $this->saleReturnRegister($request, $from, $to),
        ];
        return view('reports.sale_reports', compact('reports', 'from', 'to', 'customers', 'bookers'));
    }

    // ── TAB 1: SALE REGISTER ─────────────────────────────────────

    private function saleRegister(Request $request, string $from, string $to)
    {
        $query = SaleInvoice::with(['customer', 'items.product', 'items.variation'])
            ->whereBetween('invoice_date', [$from, $to]);

        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }

        $invoices = $query->orderBy('invoice_date')->get();
        $rows = collect();

        foreach ($invoices as $invoice) {
            $returnedValue = $invoice->returned_value;
            foreach ($invoice->items as $item) {
                $rows->push([
                    'invoice_no' => $invoice->invoice_no,
                    'date'       => Carbon::parse($invoice->invoice_date)->format('d-M-Y'),
                    'customer'   => $invoice->customer->name ?? 'N/A',
                    'item'       => $item->product->name ?? 'N/A',
                    'variation'  => $item->variation->sku ?? '—',
                    'qty'        => $item->quantity,
                    'rate'       => $item->price,
                    'amount'     => $item->quantity * $item->price,
                    'returned'   => $returnedValue > 0 ? true : false,
                ]);
            }
        }

        return $rows;
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

    private function saleReturnRegister(Request $request, string $from, string $to)
    {
        $query = SaleReturn::with(['customer', 'items.product', 'items.variation'])
            ->whereBetween('return_date', [$from, $to]);

        if ($request->filled('customer_id')) {
            $query->where('account_id', $request->customer_id);
        }

        return $query->latest('return_date')->get();
    }

    private function bookerWise(Request $request, string $from, string $to)
    {
        $query = SaleOrder::with('booker')
            ->where('status', 'invoiced')
            ->whereBetween('order_date', [$from, $to]);

        if ($request->filled('booker_id')) {
            $query->where('booker_id', $request->booker_id);
        }

        return $query->get()
            ->groupBy('booker_id')
            ->map(function ($orders) {
                return [
                    'booker'   => $orders->first()->booker,
                    'count'    => $orders->count(),
                    'quantity' => $orders->sum(fn ($o) => $o->items->sum('quantity') ?? $o->total_quantity),
                    'amount'   => $orders->sum('total_amount'),
                ];
            })
            ->sortByDesc('amount')
            ->values();
    }

    public function exportExcel(Request $request, string $tab)
    {
        $from = $request->from_date ?? Carbon::now()->startOfMonth()->toDateString();
        $to   = $request->to_date   ?? Carbon::now()->toDateString();

        switch ($tab) {
            case 'sale_register':
                $rows = $this->saleRegister($request, $from, $to)
                    ->map(fn ($r) => [$r['invoice_no'], $r['date'], $r['customer'], $r['item'], $r['variation'], $r['qty'], $r['rate'], $r['amount']]);
                return $this->exportCsv(['Invoice No.', 'Date', 'Customer Name', 'Item', 'Variation', 'Qty', 'Rate', 'Amount'], $rows->toArray(), 'sale_register.csv');

            case 'dispatch_report':
                $rows = $this->dispatchReport($request, $from, $to)
                    ->map(fn ($t) => [
                        Carbon::parse($t->trip_date)->format('d-M-Y'),
                        $t->trip_no, $t->vehicle_no, $t->deliveryManager->name ?? 'N/A',
                        $t->total_orders, $t->invoices->count(), $t->total_amount, ucfirst($t->status),
                    ]);
                return $this->exportCsv(['Date', 'Trip #', 'Vehicle', 'Delivery Manager', 'Orders', 'Invoices', 'Amount', 'Status'], $rows->toArray(), 'dispatch_report.csv');

            case 'item_wise':
                $rows = $this->itemWise($request, $from, $to)
                    ->map(fn ($r) => [$r['item'], $r['variation'], $r['quantity'], $r['revenue'], $r['cogs'], $r['profit']]);
                return $this->exportCsv(['Item', 'Variation', 'Qty Sold', 'Revenue', 'COGS', 'Gross Profit'], $rows->toArray(), 'item_wise_sale.csv');

            case 'customer_wise':
                $rows = $this->customerWise($request, $from, $to)
                    ->map(fn ($r) => [$r['customer']->name, $r['invoice_count'], $r['total_quantity'], $r['total_amount'], $r['total_paid'], $r['outstanding']]);
                return $this->exportCsv(['Customer', 'Invoices', 'Qty', 'Total', 'Paid', 'Outstanding'], $rows->toArray(), 'customer_wise_sale.csv');

            case 'monthly_summary':
                $year = $request->filled('year') ? (int) $request->year : now()->year;
                $data = $this->monthlySummary($request);
                $rows = collect($data['monthly'])->map(fn ($m) => [$m['month'], $m['count'], $m['amount'], $m['cogs'], $m['profit']]);
                return $this->exportCsv(['Month', 'Invoices', 'Sales', 'COGS', 'Gross Profit'], $rows->toArray(), "monthly_summary_{$year}.csv");

            case 'sale_return':
                $rows = $this->saleReturnRegister($request, $from, $to)
                    ->map(fn ($r) => [
                        $r->id, Carbon::parse($r->return_date)->format('d-M-Y'), $r->sale_invoice_no ?? '—',
                        $r->customer->name ?? 'N/A', $r->items->count(),
                        $r->items->sum(fn ($i) => $i->qty * $i->price),
                    ]);
                return $this->exportCsv(['Return #', 'Date', 'Against Invoice', 'Customer', 'Items', 'Total Value'], $rows->toArray(), 'sale_return.csv');

            default:
                abort(404, 'Unknown report tab.');
        }
    }
}