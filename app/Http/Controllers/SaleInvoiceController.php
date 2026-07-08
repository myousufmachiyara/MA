<?php

namespace App\Http\Controllers;

use App\Models\SaleInvoice;
use App\Models\Product;
use App\Models\ProductVariation;
use App\Models\ChartOfAccounts;
use App\Models\MeasurementUnit;
use App\Models\Location;
use App\Models\Voucher;
use App\Services\StockService;
use App\Services\VoucherService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class SaleInvoiceController extends Controller
{
    private function inventoryAccount(): ChartOfAccounts
    {
        return ChartOfAccounts::where('account_code', '104001')->firstOrFail();
    }

    private function salesRevenueAccount(): ChartOfAccounts
    {
        return ChartOfAccounts::where('account_code', '401001')->firstOrFail();
    }

    private function cogsAccount(): ChartOfAccounts
    {
        return ChartOfAccounts::where('account_code', '501001')->firstOrFail();
    }

    private function gstPayableAccount(): ChartOfAccounts
    {
        return ChartOfAccounts::where('account_code', '203001')->firstOrFail();
    }

    public function index(Request $request)
    {
        $query = SaleInvoice::with(['customer', 'dispatchTrip', 'location']);

        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }
        if ($request->filled('source')) {
            $request->source === 'manual'
                ? $query->whereNull('dispatch_trip_id')
                : $query->whereNotNull('dispatch_trip_id');
        }

        $invoices = $query->latest()->get();

        return view('sale_invoices.index', compact('invoices'));
    }

    public function create()
    {
        $products  = Product::with('variations')->orderBy('name')->get();
        $customers = ChartOfAccounts::where('account_type', 'customer')->where('is_active', true)->orderBy('name')->get();
        $units     = MeasurementUnit::all();
        $locations = Location::where('is_active', true)->orderBy('name')->get();

        return view('sale_invoices.create', compact('products', 'customers', 'units', 'locations'));
    }

    /**
     * STORE — manual/direct sale, e.g. a counter sale with no booker/trip involved.
     *
     * Accounting entries (identical math to Dispatch Trip's auto-generated invoice):
     *   Dr Customer / Cr Sales Revenue      (net amount)
     *   Dr Customer / Cr GST Payable        (if taxable)
     *   Dr COGS / Cr Inventory              (cost of goods sold)
     */
    public function store(Request $request)
    {
        $request->validate([
            'customer_id'          => 'required|exists:chart_of_accounts,id',
            'invoice_date'         => 'required|date',
            'location_id'          => 'nullable|exists:locations,id',
            'payment_terms'        => 'required|in:cash,credit',
            'apply_gst'            => 'nullable|boolean',
            'gst_type'             => 'nullable|required_if:apply_gst,1|in:inclusive,exclusive',
            'gst_rate'             => 'nullable|required_if:apply_gst,1|numeric|min:0|max:100',
            'remarks'              => 'nullable|string',
            'items'                => 'required|array|min:1',
            'items.*.item_id'      => 'required|exists:products,id',
            'items.*.variation_id' => 'nullable|exists:product_variations,id',
            'items.*.quantity'     => 'required|numeric|min:0.01',
            'items.*.unit'         => 'required|exists:measurement_units,id',
            'items.*.price'        => 'required|numeric|min:0',
        ]);

        $applyGst   = $request->boolean('apply_gst');
        $locationId = $request->location_id ?? StockService::defaultLocationId();

        // ── Stock check before touching anything ──
        foreach ($request->items as $itemData) {
            $available = StockService::currentStock($itemData['item_id'], $itemData['variation_id'] ?? null);
            $required  = (float) $itemData['quantity'];
            if ($required > $available) {
                $product = Product::find($itemData['item_id']);
                return back()->withInput()->with('error', "Insufficient stock for {$product->name}: required {$required}, available {$available}.");
            }
        }

        DB::beginTransaction();

        try {
            $customer = ChartOfAccounts::findOrFail($request->customer_id);

            $last      = SaleInvoice::withTrashed()->lockForUpdate()->orderByDesc('id')->first();
            $invoiceNo = str_pad($last ? intval($last->invoice_no) + 1 : 1, 6, '0', STR_PAD_LEFT);

            $whtApplicable = (bool) $customer->wht_applicable;
            $whtRate       = $customer->wht_rate;

            $invoice = SaleInvoice::create([
                'invoice_no'       => $invoiceNo,
                'customer_id'      => $request->customer_id,
                'sale_order_id'    => null,
                'dispatch_trip_id' => null,
                'location_id'      => $locationId,
                'invoice_date'     => $request->invoice_date,
                'payment_terms'    => $request->payment_terms,
                'is_tax_invoice'   => $applyGst,
                'gst_type'         => $applyGst ? $request->gst_type : null,
                'gst_rate'         => $applyGst ? $request->gst_rate : null,
                'wht_applicable'   => $whtApplicable,
                'wht_rate'         => $whtRate,
                'remarks'          => $request->remarks,
                'created_by'       => auth()->id(),
                'updated_by'       => auth()->id(),
            ]);

            $netAmount = 0;
            $totalQty  = 0;
            $cogsTotal = 0;

            foreach ($request->items as $itemData) {
                $qty   = (float) $itemData['quantity'];
                $price = (float) $itemData['price'];

                $target    = ($itemData['variation_id'] ?? null)
                    ? ProductVariation::find($itemData['variation_id'])
                    : Product::find($itemData['item_id']);
                $costPrice = $target->cost_price ?? 0;

                $lineNet    = $qty * $price;
                $netAmount += $lineNet;
                $totalQty  += $qty;
                $cogsTotal += $qty * $costPrice;

                $invoice->items()->create([
                    'item_id'      => $itemData['item_id'],
                    'variation_id' => $itemData['variation_id'] ?? null,
                    'quantity'     => $qty,
                    'unit'         => $itemData['unit'],
                    'price'        => $price,
                    'cost_price'   => $costPrice,
                ]);

                StockService::move(
                    $itemData['item_id'], $itemData['variation_id'] ?? null, $qty,
                    'out', 'sale_invoice', $invoice->id, "Sale Invoice #{$invoiceNo} (manual)", $locationId
                );
            }

            // ── GST calc — identical to Dispatch Trip's logic ──
            $gstAmount = 0;
            if ($applyGst) {
                $rate = (float) $request->gst_rate;
                if ($request->gst_type === 'inclusive') {
                    $base = $netAmount / (1 + $rate / 100);
                    $gstAmount = round($netAmount - $base, 2);
                    $netAmount = round($base, 2);
                } else {
                    $gstAmount = round($netAmount * $rate / 100, 2);
                }
            }

            $totalAmount = $netAmount + $gstAmount;
            $whtAmount   = $whtApplicable ? round($totalAmount * ($whtRate / 100), 2) : 0;

            $invoice->update([
                'total_quantity' => $totalQty,
                'net_amount'     => $netAmount,
                'gst_amount'     => $gstAmount,
                'total_amount'   => $totalAmount,
                'wht_amount'     => $whtAmount,
                'cogs_amount'    => $cogsTotal,
            ]);

            // ── Accounting entries — one combined voucher ──
            $lines = [
                ['account_id' => $request->customer_id, 'debit' => $totalAmount, 'credit' => 0, 'narration' => 'Sale invoice total'],
                ['account_id' => $this->salesRevenueAccount()->id, 'debit' => 0, 'credit' => $netAmount, 'narration' => 'Sales revenue'],
            ];

            if ($applyGst && $gstAmount > 0) {
                $lines[] = ['account_id' => $this->gstPayableAccount()->id, 'debit' => 0, 'credit' => $gstAmount, 'narration' => 'GST output tax'];
            }

            if ($cogsTotal > 0) {
                $lines[] = ['account_id' => $this->cogsAccount()->id, 'debit' => $cogsTotal, 'credit' => 0, 'narration' => 'Cost of goods sold'];
                $lines[] = ['account_id' => $this->inventoryAccount()->id, 'debit' => 0, 'credit' => $cogsTotal, 'narration' => 'Stock issued'];
            }

            VoucherService::postEntries(
                [
                    'voucher_type'   => 'sale',
                    'voucher_date'   => $request->invoice_date,
                    'reference_type' => SaleInvoice::class,
                    'reference_id'   => $invoice->id,
                    'remarks'        => "Sale Invoice #{$invoiceNo} (manual)",
                ],
                $lines
            );

            DB::commit();
            Log::info('[SaleInvoice] Manual invoice created', ['id' => $invoice->id, 'by' => auth()->id()]);

            return redirect()->route('sale_invoices.index')->with('success', 'Sale Invoice created successfully.');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('[SaleInvoice] Store error', ['message' => $e->getMessage(), 'line' => $e->getLine()]);
            return back()->withInput()->with('error', 'Something went wrong: ' . $e->getMessage());
        }
    }

    public function show($id)
    {
        $invoice = SaleInvoice::with(['items.product', 'items.variation', 'customer', 'dispatchTrip', 'location'])->findOrFail($id);
        return view('sale_invoices.show', compact('invoice'));
    }

    public function edit($id)
    {
        $invoice = SaleInvoice::with(['items.product.variations', 'items.variation'])->findOrFail($id);

        if ($invoice->dispatch_trip_id) {
            return back()->with('error', 'This invoice was generated from a Dispatch Trip and cannot be edited here. Manage returns via Settlement or Sale Return instead.');
        }

        $products  = Product::with('variations')->orderBy('name')->get();
        $customers = ChartOfAccounts::where('account_type', 'customer')->where('is_active', true)->orderBy('name')->get();
        $units     = MeasurementUnit::all();
        $locations = Location::where('is_active', true)->orderBy('name')->get();

        return view('sale_invoices.edit', compact('invoice', 'products', 'customers', 'units', 'locations'));
    }

    public function update(Request $request, $id)
    {
        $invoice = SaleInvoice::with('items')->findOrFail($id);

        if ($invoice->dispatch_trip_id) {
            return back()->with('error', 'This invoice was generated from a Dispatch Trip and cannot be edited here.');
        }

        $request->validate([
            'customer_id'          => 'required|exists:chart_of_accounts,id',
            'invoice_date'         => 'required|date',
            'location_id'          => 'nullable|exists:locations,id',
            'payment_terms'        => 'required|in:cash,credit',
            'apply_gst'            => 'nullable|boolean',
            'gst_type'             => 'nullable|required_if:apply_gst,1|in:inclusive,exclusive',
            'gst_rate'             => 'nullable|required_if:apply_gst,1|numeric|min:0|max:100',
            'remarks'              => 'nullable|string',
            'items'                => 'required|array|min:1',
            'items.*.item_id'      => 'required|exists:products,id',
            'items.*.variation_id' => 'nullable|exists:product_variations,id',
            'items.*.quantity'     => 'required|numeric|min:0.01',
            'items.*.unit'         => 'required|exists:measurement_units,id',
            'items.*.price'        => 'required|numeric|min:0',
        ]);

        DB::beginTransaction();

        try {
            $oldLocationId = $invoice->location_id ?? StockService::defaultLocationId();

            // Reverse old stock
            foreach ($invoice->items as $oldItem) {
                StockService::move(
                    $oldItem->item_id, $oldItem->variation_id, $oldItem->quantity,
                    'in', 'sale_invoice', $invoice->id, "Reversal — editing Sale Invoice #{$invoice->invoice_no}", $oldLocationId
                );
            }

            $newLocationId = $request->location_id ?? $oldLocationId;
            $applyGst      = $request->boolean('apply_gst');
            $customer      = ChartOfAccounts::findOrFail($request->customer_id);
            $whtApplicable = (bool) $customer->wht_applicable;
            $whtRate       = $customer->wht_rate;

            $invoice->update([
                'customer_id'    => $request->customer_id,
                'location_id'    => $newLocationId,
                'invoice_date'   => $request->invoice_date,
                'payment_terms'  => $request->payment_terms,
                'is_tax_invoice' => $applyGst,
                'gst_type'       => $applyGst ? $request->gst_type : null,
                'gst_rate'       => $applyGst ? $request->gst_rate : null,
                'wht_applicable' => $whtApplicable,
                'wht_rate'       => $whtRate,
                'remarks'        => $request->remarks,
                'updated_by'     => auth()->id(),
            ]);

            $invoice->items()->delete();
            $netAmount = 0;
            $totalQty  = 0;
            $cogsTotal = 0;

            foreach ($request->items as $itemData) {
                $qty   = (float) $itemData['quantity'];
                $price = (float) $itemData['price'];

                $target    = ($itemData['variation_id'] ?? null)
                    ? ProductVariation::find($itemData['variation_id'])
                    : Product::find($itemData['item_id']);
                $costPrice = $target->cost_price ?? 0;

                $lineNet    = $qty * $price;
                $netAmount += $lineNet;
                $totalQty  += $qty;
                $cogsTotal += $qty * $costPrice;

                $invoice->items()->create([
                    'item_id'      => $itemData['item_id'],
                    'variation_id' => $itemData['variation_id'] ?? null,
                    'quantity'     => $qty,
                    'unit'         => $itemData['unit'],
                    'price'        => $price,
                    'cost_price'   => $costPrice,
                ]);

                StockService::move(
                    $itemData['item_id'], $itemData['variation_id'] ?? null, $qty,
                    'out', 'sale_invoice', $invoice->id, "Updated Sale Invoice #{$invoice->invoice_no}", $newLocationId
                );
            }

            $gstAmount = 0;
            if ($applyGst) {
                $rate = (float) $request->gst_rate;
                if ($request->gst_type === 'inclusive') {
                    $base = $netAmount / (1 + $rate / 100);
                    $gstAmount = round($netAmount - $base, 2);
                    $netAmount = round($base, 2);
                } else {
                    $gstAmount = round($netAmount * $rate / 100, 2);
                }
            }

            $totalAmount = $netAmount + $gstAmount;
            $whtAmount   = $whtApplicable ? round($totalAmount * ($whtRate / 100), 2) : 0;

            $invoice->update([
                'total_quantity' => $totalQty,
                'net_amount'     => $netAmount,
                'gst_amount'     => $gstAmount,
                'total_amount'   => $totalAmount,
                'wht_amount'     => $whtAmount,
                'cogs_amount'    => $cogsTotal,
            ]);

            $lines = [
                ['account_id' => $request->customer_id, 'debit' => $totalAmount, 'credit' => 0, 'narration' => 'Sale invoice total'],
                ['account_id' => $this->salesRevenueAccount()->id, 'debit' => 0, 'credit' => $netAmount, 'narration' => 'Sales revenue'],
            ];

            if ($applyGst && $gstAmount > 0) {
                $lines[] = ['account_id' => $this->gstPayableAccount()->id, 'debit' => 0, 'credit' => $gstAmount, 'narration' => 'GST output tax'];
            }

            if ($cogsTotal > 0) {
                $lines[] = ['account_id' => $this->cogsAccount()->id, 'debit' => $cogsTotal, 'credit' => 0, 'narration' => 'Cost of goods sold'];
                $lines[] = ['account_id' => $this->inventoryAccount()->id, 'debit' => 0, 'credit' => $cogsTotal, 'narration' => 'Stock issued'];
            }

            VoucherService::postOrUpdateEntries(
                SaleInvoice::class,
                $invoice->id,
                'sale',
                ['voucher_date' => $request->invoice_date, 'remarks' => "Updated Sale Invoice #{$invoice->invoice_no}"],
                $lines
            );

            DB::commit();
            return redirect()->route('sale_invoices.index')->with('success', 'Sale Invoice updated successfully.');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('[SaleInvoice] Update error', ['message' => $e->getMessage(), 'line' => $e->getLine()]);
            return back()->withErrors(['error' => 'Failed to update: ' . $e->getMessage()]);
        }
    }

    public function destroy($id)
    {
        $invoice = SaleInvoice::with('items')->findOrFail($id);

        if ($invoice->dispatch_trip_id) {
            return back()->with('error', 'This invoice was generated from a Dispatch Trip and cannot be deleted here.');
        }

        if ($invoice->paid_amount > 0) {
            return back()->with('error', 'This invoice already has payments/returns recorded against it and cannot be deleted. Use Sale Return instead.');
        }

        DB::beginTransaction();

        try {
            $locationId = $invoice->location_id ?? StockService::defaultLocationId();

            foreach ($invoice->items as $item) {
                StockService::move(
                    $item->item_id, $item->variation_id, $item->quantity,
                    'in', 'sale_invoice', $invoice->id, "Deleted Sale Invoice #{$invoice->invoice_no}", $locationId
                );
            }

            Voucher::where('reference_type', SaleInvoice::class)->where('reference_id', $invoice->id)->delete();

            $invoice->items()->delete();
            $invoice->delete();

            DB::commit();
            return redirect()->route('sale_invoices.index')->with('success', 'Invoice deleted and stock restored.');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('[SaleInvoice] Destroy error', ['message' => $e->getMessage()]);
            return back()->with('error', 'Failed to delete invoice.');
        }
    }

    public function print($id)
    {
        $invoice = SaleInvoice::with(['items.product', 'items.variation', 'customer'])->findOrFail($id);

        $pdf = new \TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        $pdf->SetCreator('BillTrix');
        $pdf->SetTitle('SI-' . $invoice->invoice_no);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(15, 15, 15);
        $pdf->SetAutoPageBreak(true, 20);
        $pdf->AddPage();

        $pdf->SetFont('helvetica', 'B', 16);
        $pdf->Cell(0, 10, 'SALE INVOICE' . ($invoice->is_tax_invoice ? ' (TAX INVOICE)' : ''), 0, 1, 'R');
        $pdf->SetFont('helvetica', '', 10);
        $pdf->Cell(0, 5, 'Invoice #: ' . $invoice->invoice_no, 0, 1, 'R');
        $pdf->Cell(0, 5, 'Date: ' . Carbon::parse($invoice->invoice_date)->format('d-M-Y'), 0, 1, 'R');
        $pdf->Ln(5);

        $html = '<table width="50%" border="1" cellpadding="3" style="font-size:10px;">
            <tr><td width="40%"><b>Customer:</b></td><td>' . e($invoice->customer->name ?? 'N/A') . '</td></tr>
            <tr><td><b>Payment Terms:</b></td><td>' . ucfirst($invoice->payment_terms) . '</td></tr>
        </table>';
        $pdf->writeHTML($html, true, false, false, false, '');
        $pdf->Ln(5);

        $html = '<table border="1" cellpadding="5" style="font-size:10px;">
            <thead><tr style="background-color:#f2f2f2;font-weight:bold;text-align:center;">
                <th width="5%">#</th><th width="35%">Item</th><th width="15%">Variation</th>
                <th width="10%">Qty</th><th width="15%">Price</th><th width="20%">Total</th>
            </tr></thead><tbody>';

        foreach ($invoice->items as $i => $item) {
            $lineTotal = $item->quantity * $item->price;
            $html .= '<tr>
                <td style="text-align:center;">' . ($i + 1) . '</td>
                <td>' . e($item->product->name ?? '-') . '</td>
                <td style="text-align:center;">' . e($item->variation->sku ?? '-') . '</td>
                <td style="text-align:center;">' . number_format($item->quantity, 2) . '</td>
                <td style="text-align:right;">' . number_format($item->price, 2) . '</td>
                <td style="text-align:right;">' . number_format($lineTotal, 2) . '</td>
            </tr>';
        }

        $html .= '<tr><td colspan="5" style="text-align:right;">Net Amount</td><td style="text-align:right;">' . number_format($invoice->net_amount, 2) . '</td></tr>';
        if ($invoice->is_tax_invoice) {
            $html .= '<tr><td colspan="5" style="text-align:right;">GST (' . $invoice->gst_rate . '%)</td><td style="text-align:right;">' . number_format($invoice->gst_amount, 2) . '</td></tr>';
        }
        $html .= '<tr style="font-weight:bold;"><td colspan="5" style="text-align:right;">Total Amount</td><td style="text-align:right;">' . number_format($invoice->total_amount, 2) . '</td></tr>';
        if ($invoice->wht_applicable) {
            $html .= '<tr><td colspan="5" style="text-align:right;">WHT (' . $invoice->wht_rate . '%) — deducted at payment</td><td style="text-align:right;">' . number_format($invoice->wht_amount, 2) . '</td></tr>';
        }
        $html .= '</tbody></table>';

        $pdf->writeHTML($html, true, false, false, false, '');

        return $pdf->Output('SI_' . $invoice->invoice_no . '.pdf', 'I');
    }
}