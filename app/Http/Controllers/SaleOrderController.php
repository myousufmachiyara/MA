<?php

namespace App\Http\Controllers;

use App\Models\SaleOrder;
use App\Models\Product;
use App\Models\User;
use App\Models\ChartOfAccounts;
use App\Models\MeasurementUnit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Carbon\Carbon;

class SaleOrderController extends Controller
{
    public function index(Request $request)
    {
        $query = SaleOrder::with(['customer', 'booker', 'items.product', 'items.variation']);

        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }
        if ($request->filled('booker_id')) {
            $query->where('booker_id', $request->booker_id);
        }
        if ($request->filled('date_from')) {
            $query->whereDate('order_date', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('order_date', '<=', $request->date_to);
        }

        $orders  = $query->latest()->get();
        $bookers = User::where('user_type', 'mobile')->get(['id', 'name']);

        return view('sale_orders.index', compact('orders', 'bookers'));
    }

    public function create()
    {
        $customers = ChartOfAccounts::where('account_type', 'customer')->where('is_active', true)->orderBy('name')->get();
        $products  = Product::with('variations')->orderBy('name')->get();
        $units     = MeasurementUnit::all();

        return view('sale_orders.create', compact('customers', 'products', 'units'));
    }

    /**
     * Web-side order booking — for walk-in customers handled directly at the
     * office, not via a mobile order booker. Flows into the exact same
     * Sale Order pool as mobile-booked orders (Dispatch Trip merges both
     * identically) — this is just an alternate entry point, not a shortcut.
     */
    public function store(Request $request)
    {
        $request->validate([
            'customer_id'           => 'required|exists:chart_of_accounts,id',
            'order_date'            => 'required|date',
            'payment_terms'         => 'required|in:cash,credit',
            'remarks'               => 'nullable|string',
            'items'                 => 'required|array|min:1',
            'items.*.item_id'       => 'required|exists:products,id',
            'items.*.variation_id'  => 'nullable|exists:product_variations,id',
            'items.*.quantity'      => 'required|numeric|min:0.01',
            'items.*.unit'          => 'required|exists:measurement_units,id',
            'items.*.price'         => 'required|numeric|min:0',
        ]);

        DB::beginTransaction();

        try {
            $last    = SaleOrder::withTrashed()->lockForUpdate()->orderByDesc('id')->first();
            $orderNo = str_pad($last ? intval($last->order_no ?? 0) + 1 : 1, 6, '0', STR_PAD_LEFT);

            $order = SaleOrder::create([
                'order_no'      => $orderNo,
                'customer_id'   => $request->customer_id,
                'booker_id'     => auth()->id(), // office staff who booked it
                'order_date'    => $request->order_date,
                'status'        => 'confirmed', // office-created — no review step needed
                'payment_terms' => $request->payment_terms,
                'remarks'       => $request->remarks,
                'local_uuid'    => Str::uuid(),
                'sync_status'   => 'synced',
                'booked_at'     => now(),
                'synced_at'     => now(),
            ]);

            $totalAmount = 0;
            $totalQty    = 0;

            foreach ($request->items as $itemData) {
                $qty   = (float) $itemData['quantity'];
                $price = (float) $itemData['price'];
                $totalAmount += $qty * $price;
                $totalQty    += $qty;

                $order->items()->create([
                    'item_id'      => $itemData['item_id'],
                    'variation_id' => $itemData['variation_id'] ?? null,
                    'quantity'     => $qty,
                    'unit'         => $itemData['unit'],
                    'price'        => $price,
                ]);
            }

            $order->update(['total_amount' => $totalAmount, 'total_quantity' => $totalQty]);

            DB::commit();
            Log::info('[SaleOrder] Booked via web (walk-in)', ['id' => $order->id, 'by' => auth()->id()]);

            return redirect()->route('sale_orders.index')->with('success', "Order SO-{$orderNo} booked successfully.");

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('[SaleOrder] Web store error', ['message' => $e->getMessage()]);
            return back()->withInput()->with('error', 'Something went wrong: ' . $e->getMessage());
        }
    }

    public function edit($id)
    {
        $order    = SaleOrder::with(['items.product.variations', 'items.variation'])->findOrFail($id);
        $products = Product::with('variations')->orderBy('name')->get();
        $units    = MeasurementUnit::all();

        return view('sale_orders.edit', compact('order', 'products', 'units'));
    }

    /**
     * Manager can adjust quantities/prices before merging into a dispatch trip
     * (e.g. stock shortage means only partial qty can actually be fulfilled).
     */
    public function update(Request $request, $id)
    {
        $order = SaleOrder::with('items')->findOrFail($id);

        if (in_array($order->status, ['merged', 'invoiced'])) {
            return back()->with('error', 'This order is already merged into a dispatch trip and can no longer be edited here.');
        }

        $request->validate([
            'items'                 => 'required|array|min:1',
            'items.*.item_id'       => 'required|exists:products,id',
            'items.*.variation_id'  => 'nullable|exists:product_variations,id',
            'items.*.quantity'      => 'required|numeric|min:0.01',
            'items.*.unit'          => 'required|exists:measurement_units,id',
            'items.*.price'         => 'required|numeric|min:0',
        ]);

        $order->items()->delete();
        $totalAmount = 0;
        $totalQty    = 0;

        foreach ($request->items as $itemData) {
            $qty   = (float) $itemData['quantity'];
            $price = (float) $itemData['price'];
            $totalAmount += $qty * $price;
            $totalQty    += $qty;

            $order->items()->create([
                'item_id'      => $itemData['item_id'],
                'variation_id' => $itemData['variation_id'] ?? null,
                'quantity'     => $qty,
                'unit'         => $itemData['unit'],
                'price'        => $price,
            ]);
        }

        $order->update(['total_amount' => $totalAmount, 'total_quantity' => $totalQty]);

        Log::info('[SaleOrder] Updated by manager', ['id' => $id, 'by' => auth()->id()]);

        return redirect()->route('sale_orders.index')->with('success', "Order SO-{$id} updated successfully.");
    }

    public function cancel($id)
    {
        $order = SaleOrder::findOrFail($id);

        if (in_array($order->status, ['merged', 'invoiced'])) {
            return back()->with('error', 'Cannot cancel — already merged into a dispatch trip.');
        }

        $order->update(['status' => 'cancelled']);
        return back()->with('success', 'Order cancelled.');
    }

    /**
     * Flattens every order's items into one row per item, for
     * item/rate-level export — same filters as the index view.
    */
    private function buildExportRows(Request $request)
    {
        $query = SaleOrder::with(['customer', 'booker', 'items.product', 'items.variation']);

        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }
        if ($request->filled('booker_id')) {
            $query->where('booker_id', $request->booker_id);
        }
        if ($request->filled('date_from')) {
            $query->whereDate('order_date', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('order_date', '<=', $request->date_to);
        }

        $orders = $query->orderBy('order_date')->get();
        $rows   = [];

        foreach ($orders as $order) {
            foreach ($order->items as $item) {
                $rows[] = [
                    'order_no'   => $order->order_no,
                    'order_date' => Carbon::parse($order->order_date)->format('d-M-Y'),
                    'customer'   => $order->customer->name ?? 'N/A',
                    'booker'     => $order->booker->name ?? 'N/A',
                    'status'     => ucfirst($order->status),
                    'item'       => $item->product->name ?? 'N/A',
                    'variation'  => $item->variation->sku ?? '—',
                    'quantity'   => $item->quantity,
                    'price'      => $item->price,
                    'amount'     => $item->quantity * $item->price,
                ];
            }
        }

        return $rows;
    }

    public function exportPdf(Request $request)
    {
        $rows = $this->buildExportRows($request);

        $pdf = new \TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        $pdf->SetCreator('BillTrix');
        $pdf->SetTitle('Booked Orders Report');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(10, 10, 10);
        $pdf->SetAutoPageBreak(true, 15);
        $pdf->AddPage('L'); // landscape — many columns

        $pdf->SetFont('helvetica', 'B', 14);
        $pdf->Cell(0, 8, 'Booked Orders — Item Detail Report', 0, 1, 'C');
        $pdf->SetFont('helvetica', '', 9);
        $rangeLabel = ($request->date_from ?? 'All') . ' to ' . ($request->date_to ?? 'All');
        $pdf->Cell(0, 6, 'Date Range: ' . $rangeLabel, 0, 1, 'C');
        $pdf->Ln(3);

        $html = '<table border="0.5" cellpadding="4" style="font-size:9px;">
            <thead><tr style="background-color:#1a1a2e;color:#fff;font-weight:bold;">
                <th width="8%">Order #</th><th width="8%">Date</th><th width="12%">Customer</th>
                <th width="10%">Booker</th><th width="9%">Status</th><th width="15%">Item</th>
                <th width="10%">Variation</th><th width="7%">Qty</th><th width="8%">Price</th><th width="8%">Amount</th>
            </tr></thead><tbody>';

        $totalAmount = 0;
        foreach ($rows as $r) {
            $totalAmount += $r['amount'];
            $html .= '<tr>
                <td>SO-' . e($r['order_no']) . '</td>
                <td>' . e($r['order_date']) . '</td>
                <td>' . e($r['customer']) . '</td>
                <td>' . e($r['booker']) . '</td>
                <td>' . e($r['status']) . '</td>
                <td>' . e($r['item']) . '</td>
                <td>' . e($r['variation']) . '</td>
                <td align="right">' . number_format($r['quantity'], 2) . '</td>
                <td align="right">' . number_format($r['price'], 2) . '</td>
                <td align="right">' . number_format($r['amount'], 2) . '</td>
            </tr>';
        }

        $html .= '<tr style="background-color:#f2f2f2;font-weight:bold;">
            <td colspan="9" align="right">Total</td><td align="right">' . number_format($totalAmount, 2) . '</td>
        </tr></tbody></table>';

        $pdf->writeHTML($html, true, false, false, false, '');

        return $pdf->Output('booked_orders_' . now()->format('Ymd_His') . '.pdf', 'I');
    }

    public function exportExcel(Request $request)
    {
        $rows = $this->buildExportRows($request);

        $filename = 'booked_orders_' . now()->format('Ymd_His') . '.csv';

        $headers = [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        return response()->stream(function () use ($rows) {
            $handle = fopen('php://output', 'w');
            fputs($handle, "\xEF\xBB\xBF"); // UTF-8 BOM — keeps Excel from mangling special characters

            fputcsv($handle, ['Order #', 'Date', 'Customer', 'Booker', 'Status', 'Item', 'Variation', 'Qty', 'Price', 'Amount']);

            foreach ($rows as $r) {
                fputcsv($handle, [
                    'SO-' . $r['order_no'],
                    $r['order_date'],
                    $r['customer'],
                    $r['booker'],
                    $r['status'],
                    $r['item'],
                    $r['variation'],
                    $r['quantity'],
                    $r['price'],
                    $r['amount'],
                ]);
            }

            fclose($handle);
        }, 200, $headers);
    }
}