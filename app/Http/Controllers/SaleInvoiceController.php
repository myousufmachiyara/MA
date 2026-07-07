<?php

namespace App\Http\Controllers;

use App\Models\SaleInvoice;
use App\Models\SaleInvoiceItem;
use App\Models\PurchaseInvoiceItem;
use App\Models\ChartOfAccounts;
use App\Models\Product;
use App\Models\MeasurementUnit;
use App\Models\ProductVariation;
use App\Models\Voucher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class SaleInvoiceController extends Controller
{
    // ─────────────────────────────────────────────────────────────
    // Shared account resolvers — always use account_code (stable)
    // ─────────────────────────────────────────────────────────────
    private function salesRevenueAccount(): ChartOfAccounts
    {
        $account = ChartOfAccounts::where('account_code', '401001')->first();
        if (!$account) {
            throw new \Exception('Sales Revenue account (401001) not found.');
        }
        return $account;
    }

    private function inventoryAccount(): ChartOfAccounts
    {
        $account = ChartOfAccounts::where('account_code', '104001')->first();
        if (!$account) {
            throw new \Exception('Inventory account (104001) not found.');
        }
        return $account;
    }

    private function cogsAccount(): ?ChartOfAccounts
    {
        return ChartOfAccounts::where('account_code', '501001')->first();
    }

    // ─────────────────────────────────────────────────────────────
    // STOCK CALCULATION
    //
    // Computes real-time stock per product and per variation.
    //
    // Root cause of the "showing 0" bug:
    //   - The old code queried purchase_invoice_items by variation_id
    //     alone. When a purchase was made WITHOUT selecting a variation
    //     (variation_id = null), that quantity was never counted.
    //   - The edit() method used withSum on relationships
    //     (purchaseInvoices / saleInvoices) that don't exist on Product,
    //     so it always returned 0.
    //
    // Fix: query by item_id (product) first, then additionally filter
    // by variation_id for per-variation breakdown.
    //
    // Formula:
    //   stock = (purchased + sale_returned) - (sold + purchase_returned)
    //
    // Column name map:
    //   purchase_invoice_items  → item_id      (product foreign key)
    //   sale_invoice_items      → product_id   (product foreign key)
    //   purchase_return_items   → item_id
    //   sale_return_items       → product_id, qty (not quantity)
    // ─────────────────────────────────────────────────────────────
    private function buildProductsWithStock(): \Illuminate\Support\Collection
    {
        return Product::with('variations')->orderBy('name')->get()
            ->map(function ($product) {

                // Product-level totals (all variations combined)
                $purchased = (float) DB::table('purchase_invoice_items')
                    ->join('purchase_invoices', 'purchase_invoice_items.purchase_invoice_id', '=', 'purchase_invoices.id')
                    ->where('purchase_invoice_items.item_id', $product->id)
                    ->whereNull('purchase_invoices.deleted_at')
                    ->sum('purchase_invoice_items.quantity');

                $sold = (float) DB::table('sale_invoice_items')
                    ->join('sale_invoices', 'sale_invoice_items.sale_invoice_id', '=', 'sale_invoices.id')
                    ->where('sale_invoice_items.product_id', $product->id)
                    ->whereNull('sale_invoices.deleted_at')
                    ->sum('sale_invoice_items.quantity');

                $purchaseReturned = (float) DB::table('purchase_return_items')
                    ->where('item_id', $product->id)
                    ->sum('quantity');

                $saleReturned = (float) DB::table('sale_return_items')
                    ->where('product_id', $product->id)
                    ->sum('qty');

                $product->real_time_stock = ($purchased + $saleReturned) - ($sold + $purchaseReturned);

                // Per-variation breakdown
                foreach ($product->variations as $v) {
                    $vPurchased = (float) DB::table('purchase_invoice_items')
                        ->join('purchase_invoices', 'purchase_invoice_items.purchase_invoice_id', '=', 'purchase_invoices.id')
                        ->where('purchase_invoice_items.item_id', $product->id)
                        ->where('purchase_invoice_items.variation_id', $v->id)
                        ->whereNull('purchase_invoices.deleted_at')
                        ->sum('purchase_invoice_items.quantity');

                    $vSold = (float) DB::table('sale_invoice_items')
                        ->join('sale_invoices', 'sale_invoice_items.sale_invoice_id', '=', 'sale_invoices.id')
                        ->where('sale_invoice_items.product_id', $product->id)
                        ->where('sale_invoice_items.variation_id', $v->id)
                        ->whereNull('sale_invoices.deleted_at')
                        ->sum('sale_invoice_items.quantity');

                    $vPurchaseReturned = (float) DB::table('purchase_return_items')
                        ->where('item_id', $product->id)
                        ->where('variation_id', $v->id)
                        ->sum('quantity');

                    $vSaleReturned = (float) DB::table('sale_return_items')
                        ->where('product_id', $product->id)
                        ->where('variation_id', $v->id)
                        ->sum('qty');

                    $v->current_stock = ($vPurchased + $vSaleReturned) - ($vSold + $vPurchaseReturned);
                }

                return $product;
            });
    }

    // ─────────────────────────────────────────────────────────────
    // COGS calculation — uses actual purchase price (not sale price)
    // ─────────────────────────────────────────────────────────────
    private function calculateCogs(array $items): float
    {
        $totalCost = 0.0;

        foreach ($items as $item) {
            $query = PurchaseInvoiceItem::where('item_id', $item['product_id']);

            // Only filter by variation if one was actually selected
            if (!empty($item['variation_id'])) {
                $query->where('variation_id', $item['variation_id']);
            }

            $latestPurchase = $query->latest('id')->first();

            if ($latestPurchase) {
                $totalCost += (float) $latestPurchase->price * (float) $item['quantity'];
            }
        }

        return $totalCost;
    }

    // ─────────────────────────────────────────────────────────────
    // INDEX
    // ─────────────────────────────────────────────────────────────
    public function index()
    {
        $invoices = SaleInvoice::with('items.product', 'account')->latest()->get();
        return view('sales.index', compact('invoices'));
    }

    // ─────────────────────────────────────────────────────────────
    // CREATE FORM
    // ─────────────────────────────────────────────────────────────
    public function create()
    {
        $products        = $this->buildProductsWithStock();
        $customers       = ChartOfAccounts::where('account_type', 'customer')->orderBy('name')->get();
        $paymentAccounts = ChartOfAccounts::whereIn('account_type', ['cash', 'bank'])->orderBy('name')->get();
        $units           = MeasurementUnit::all();

        return view('sales.create', compact('products', 'customers', 'paymentAccounts', 'units'));
    }

    // ─────────────────────────────────────────────────────────────
    // STORE
    // ─────────────────────────────────────────────────────────────
    public function store(Request $request)
    {
        $validated = $request->validate([
            'date'                   => 'required|date',
            'account_id'             => 'required|exists:chart_of_accounts,id',
            'type'                   => 'required|in:cash,credit',
            'discount'               => 'nullable|numeric|min:0',
            'remarks'                => 'nullable|string',
            'items'                  => 'required|array|min:1',
            'items.*.product_id'     => 'required|exists:products,id',
            'items.*.variation_id'   => 'nullable|exists:product_variations,id',
            'items.*.sale_price'     => 'required|numeric|min:0',
            'items.*.quantity'       => 'required|numeric|min:1',
            'payment_account_id'     => 'nullable|exists:chart_of_accounts,id',
            'amount_received'        => 'nullable|numeric|min:0',
        ]);

        DB::beginTransaction();

        try {
            $last      = SaleInvoice::withTrashed()->orderByDesc('id')->first();
            $invoiceNo = str_pad($last ? intval($last->invoice_no) + 1 : 1, 6, '0', STR_PAD_LEFT);

            $invoice = SaleInvoice::create([
                'invoice_no' => $invoiceNo,
                'date'       => $validated['date'],
                'account_id' => $validated['account_id'],
                'type'       => $validated['type'],
                'discount'   => $validated['discount'] ?? 0,
                'remarks'    => $validated['remarks'] ?? null,
                'created_by' => Auth::id(),
            ]);

            $subtotal = 0;
            foreach ($validated['items'] as $item) {
                SaleInvoiceItem::create([
                    'sale_invoice_id' => $invoice->id,
                    'product_id'      => $item['product_id'],
                    'variation_id'    => $item['variation_id'] ?? null,
                    'sale_price'      => $item['sale_price'],
                    'quantity'        => $item['quantity'],
                    'discount'        => 0,
                ]);
                $subtotal += $item['sale_price'] * $item['quantity'];
            }

            $netTotal = $subtotal - ($validated['discount'] ?? 0);

            // Entry A: DR Customer / CR Sales Revenue
            $salesAccount = $this->salesRevenueAccount();
            Voucher::create([
                'voucher_type' => 'journal',
                'date'         => $validated['date'],
                'ac_dr_sid'    => $validated['account_id'],
                'ac_cr_sid'    => $salesAccount->id,
                'amount'       => $netTotal,
                'reference'    => 'SI-' . $invoice->id,
                'remarks'      => "Sale Invoice #{$invoiceNo} — revenue",
            ]);

            // Entry B: DR COGS / CR Inventory
            $inventoryAccount = $this->inventoryAccount();
            $cogsAccount      = $this->cogsAccount();

            if ($cogsAccount) {
                $totalCost = $this->calculateCogs($validated['items']);
                if ($totalCost > 0) {
                    Voucher::create([
                        'voucher_type' => 'journal',
                        'date'         => $validated['date'],
                        'ac_dr_sid'    => $cogsAccount->id,
                        'ac_cr_sid'    => $inventoryAccount->id,
                        'amount'       => $totalCost,
                        'reference'    => 'SI-' . $invoice->id,
                        'remarks'      => "Sale Invoice #{$invoiceNo} — COGS",
                    ]);
                }
            } else {
                Log::warning('[SI] COGS account (501001) not found — COGS entry skipped.');
            }

            // Entry C: DR Cash/Bank / CR Customer (optional)
            if ($request->filled('payment_account_id') && (float) $request->amount_received > 0) {
                Voucher::create([
                    'voucher_type' => 'receipt',
                    'date'         => $validated['date'],
                    'ac_dr_sid'    => $validated['payment_account_id'],
                    'ac_cr_sid'    => $validated['account_id'],
                    'amount'       => $validated['amount_received'],
                    'reference'    => 'SI-' . $invoice->id,
                    'remarks'      => "Payment received — Invoice #{$invoiceNo}",
                ]);
            }

            DB::commit();
            Log::info('[SI] Stored successfully', ['invoice_id' => $invoice->id]);

            return redirect()->route('sale_invoices.index')
                ->with('success', 'Sale Invoice created successfully.');

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('[SI] Store failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return back()->withInput()->with('error', 'Error saving invoice: ' . $e->getMessage());
        }
    }

    // ─────────────────────────────────────────────────────────────
    // EDIT FORM
    // ─────────────────────────────────────────────────────────────
    public function edit($id)
    {
        $invoice        = SaleInvoice::with(['items', 'account'])->findOrFail($id);
        $products       = $this->buildProductsWithStock();
        $amountReceived = Voucher::where('reference', 'SI-' . $id)
                            ->where('voucher_type', 'receipt')
                            ->sum('amount');

        return view('sales.edit', [
            'invoice'         => $invoice,
            'products'        => $products,
            'customers'       => ChartOfAccounts::where('account_type', 'customer')->orderBy('name')->get(),
            'paymentAccounts' => ChartOfAccounts::whereIn('account_type', ['cash', 'bank'])->orderBy('name')->get(),
            'amountReceived'  => $amountReceived,
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    // UPDATE
    // ─────────────────────────────────────────────────────────────
    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'date'                   => 'required|date',
            'account_id'             => 'required|exists:chart_of_accounts,id',
            'type'                   => 'required|in:cash,credit',
            'discount'               => 'nullable|numeric|min:0',
            'remarks'                => 'nullable|string',
            'items'                  => 'required|array|min:1',
            'items.*.product_id'     => 'required|exists:products,id',
            'items.*.variation_id'   => 'nullable|exists:product_variations,id',
            'items.*.sale_price'     => 'required|numeric|min:0',
            'items.*.quantity'       => 'required|numeric|min:0.01',
            'payment_account_id'     => 'nullable|exists:chart_of_accounts,id',
            'amount_received'        => 'nullable|numeric|min:0',
        ]);

        DB::beginTransaction();

        try {
            $invoice   = SaleInvoice::with('items')->findOrFail($id);
            $invoiceNo = $invoice->invoice_no;
            $reference = 'SI-' . $invoice->id;

            $invoice->update([
                'date'       => $validated['date'],
                'account_id' => $validated['account_id'],
                'type'       => $validated['type'],
                'discount'   => $validated['discount'] ?? 0,
                'remarks'    => $validated['remarks'] ?? null,
            ]);

            $invoice->items()->delete();
            $subtotal = 0;

            foreach ($validated['items'] as $item) {
                SaleInvoiceItem::create([
                    'sale_invoice_id' => $invoice->id,
                    'product_id'      => $item['product_id'],
                    'variation_id'    => $item['variation_id'] ?? null,
                    'sale_price'      => $item['sale_price'],
                    'quantity'        => $item['quantity'],
                    'discount'        => 0,
                ]);
                $subtotal += $item['sale_price'] * $item['quantity'];
            }

            $netTotal  = $subtotal - ($validated['discount'] ?? 0);
            $totalCost = $this->calculateCogs($validated['items']);
            $invoice->update(['net_amount' => $netTotal]);

            Voucher::where('reference', $reference)->where('voucher_type', 'journal')->delete();

            $salesAccount     = $this->salesRevenueAccount();
            $inventoryAccount = $this->inventoryAccount();
            $cogsAccount      = $this->cogsAccount();

            Voucher::create([
                'voucher_type' => 'journal',
                'date'         => $validated['date'],
                'ac_dr_sid'    => $validated['account_id'],
                'ac_cr_sid'    => $salesAccount->id,
                'amount'       => $netTotal,
                'reference'    => $reference,
                'remarks'      => "Updated: Sale Invoice #{$invoiceNo} — revenue",
            ]);

            if ($cogsAccount && $totalCost > 0) {
                Voucher::create([
                    'voucher_type' => 'journal',
                    'date'         => $validated['date'],
                    'ac_dr_sid'    => $cogsAccount->id,
                    'ac_cr_sid'    => $inventoryAccount->id,
                    'amount'       => $totalCost,
                    'reference'    => $reference,
                    'remarks'      => "Updated: Sale Invoice #{$invoiceNo} — COGS",
                ]);
            }

            $paymentVoucher = Voucher::where('reference', $reference)
                ->where('voucher_type', 'receipt')
                ->first();

            $hasPayment = $request->filled('payment_account_id')
                && (float) $request->amount_received > 0;

            if ($hasPayment) {
                $receiptData = [
                    'date'      => $validated['date'],
                    'ac_dr_sid' => $validated['payment_account_id'],
                    'ac_cr_sid' => $validated['account_id'],
                    'amount'    => $validated['amount_received'],
                    'remarks'   => "Payment — Invoice #{$invoiceNo}",
                    'reference' => $reference,
                ];
                $paymentVoucher
                    ? $paymentVoucher->update($receiptData)
                    : Voucher::create(array_merge(['voucher_type' => 'receipt'], $receiptData));
            } elseif ($paymentVoucher) {
                $paymentVoucher->delete();
            }

            DB::commit();
            Log::info('[SI] Updated successfully', ['invoice_id' => $invoice->id]);

            return redirect()->route('sale_invoices.index')
                ->with('success', 'Invoice and payment updated successfully.');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('[SI] Update error', ['error' => $e->getMessage()]);
            return back()->with('error', 'Update failed: ' . $e->getMessage())->withInput();
        }
    }

    // ─────────────────────────────────────────────────────────────
    // PRINT  (PDF)
    // ─────────────────────────────────────────────────────────────
    public function print($id)
    {
        $invoice        = SaleInvoice::with(['account', 'items.product', 'items.variation'])->findOrFail($id);
        $amountReceived = Voucher::where('reference', 'SI-' . $id)
                            ->where('voucher_type', 'receipt')
                            ->sum('amount');

        $pdf = new \TCPDF();
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(10, 10, 10);
        $pdf->SetCreator(PDF_CREATOR);
        $pdf->SetAuthor('Lucky Corporation');
        $pdf->SetTitle('SALE-' . $invoice->invoice_no);
        $pdf->AddPage();
        $pdf->SetFont('helvetica', '', 10);

        $logoPath = public_path('assets/img/logo.png');
        if (file_exists($logoPath)) {
            $pdf->Image($logoPath, 15, 12, 35);
        }

        $pdf->SetFont('helvetica', 'B', 16);
        $pdf->SetXY(110, 12);
        $pdf->Cell(85, 10, 'SALE INVOICE', 0, 1, 'R');

        $pdf->SetFont('helvetica', '', 10);
        $pdf->SetXY(110, 20);
        $pdf->Cell(85, 5, 'Invoice #: ' . $invoice->invoice_no, 0, 1, 'R');
        $pdf->SetX(110);
        $pdf->Cell(85, 5, 'Date: ' . Carbon::parse($invoice->date)->format('d-M-Y'), 0, 1, 'R');
        $pdf->SetX(110);
        $pdf->Cell(85, 5, 'Customer: ' . ($invoice->account->name ?? '-'), 0, 1, 'R');
        $pdf->Ln(10);

        $html = '
        <table border="1" cellpadding="4" style="text-align:center;font-size:10px;">
            <tr style="font-weight:bold;background-color:#f5f5f5;">
                <th width="5%">#</th>
                <th width="35%">Item Name</th>
                <th width="20%">Variation</th>
                <th width="10%">Qty</th>
                <th width="15%">Price</th>
                <th width="15%">Total</th>
            </tr>';

        $count = $totalQty = $subTotal = 0;

        foreach ($invoice->items as $item) {
            $count++;
            $lineTotal = $item->sale_price * $item->quantity;
            $unitCode  = $item->product->measurementUnit->shortcode ?? '';

            $html .= '
            <tr>
                <td>' . $count . '</td>
                <td style="text-align:left">' . e($item->product->name ?? '-') . '</td>
                <td style="text-align:left">' . e($item->variation->sku ?? '-') . '</td>
                <td>' . number_format($item->quantity, 2) . ' ' . e($unitCode) . '</td>
                <td>' . number_format($item->sale_price, 2) . '</td>
                <td>' . number_format($lineTotal, 2) . '</td>
            </tr>';

            $totalQty += $item->quantity;
            $subTotal += $lineTotal;
        }

        $invoiceDiscount = $invoice->discount ?? 0;
        $netTotal        = $subTotal - $invoiceDiscount;
        $balanceDue      = $netTotal - $amountReceived;

        $html .= '
        <tr>
            <td colspan="3" align="right" bgcolor="#f5f5f5"><b>Total Qty</b></td>
            <td><b>' . number_format($totalQty, 2) . '</b></td>
            <td align="right" bgcolor="#f5f5f5"><b>Sub Total</b></td>
            <td align="right"><b>' . number_format($subTotal, 2) . '</b></td>
        </tr>';

        if ($invoiceDiscount > 0) {
            $html .= '
            <tr>
                <td colspan="5" align="right">Less: Discount</td>
                <td align="right">' . number_format($invoiceDiscount, 2) . '</td>
            </tr>';
        }

        $html .= '
        <tr style="background-color:#f5f5f5;">
            <td colspan="5" align="right"><b>Net Payable</b></td>
            <td align="right"><b>' . number_format($netTotal, 2) . '</b></td>
        </tr>
        <tr>
            <td colspan="5" align="right">Amount Received</td>
            <td align="right" style="color:green;">' . number_format($amountReceived, 2) . '</td>
        </tr>
        <tr style="background-color:#eeeeee;">
            <td colspan="5" align="right"><b>Remaining Balance</b></td>
            <td align="right" style="color:red;"><b>' . number_format($balanceDue, 2) . '</b></td>
        </tr>
        </table>';

        $pdf->writeHTML($html, true, false, false, false, '');

        if (!empty($invoice->remarks)) {
            $pdf->Ln(2);
            $pdf->writeHTML('<p style="font-size:9px;"><b>Remarks:</b> ' . nl2br(e($invoice->remarks)) . '</p>', true, false, false, false, '');
        }

        if ($pdf->GetY() > 240) { $pdf->AddPage(); }

        $pdf->Ln(30);
        $y = $pdf->GetY();
        $w = 60;

        $pdf->Line(20, $y, 20 + $w, $y);
        $pdf->Line(130, $y, 130 + $w, $y);
        $pdf->SetY($y + 2);
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->SetX(20);  $pdf->Cell($w, 5, 'Customer Signature',  0, 0, 'C');
        $pdf->SetX(130); $pdf->Cell($w, 5, 'Authorized Signature', 0, 0, 'C');

        return $pdf->Output('Invoice_' . $invoice->invoice_no . '.pdf', 'I');
    }
}