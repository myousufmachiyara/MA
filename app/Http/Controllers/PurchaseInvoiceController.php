<?php

namespace App\Http\Controllers;

use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoiceItem;
use App\Models\PurchaseInvoiceAttachment;
use App\Models\Product;
use App\Models\ProductVariation;
use App\Models\Voucher;
use App\Models\MeasurementUnit;
use App\Models\ChartOfAccounts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class PurchaseInvoiceController extends Controller
{
    // ─────────────────────────────────────────────────────────────
    // Shared helper: resolve inventory COA by account_code (stable)
    // ─────────────────────────────────────────────────────────────
    private function inventoryAccount(): ChartOfAccounts
    {
        $account = ChartOfAccounts::where('account_code', '104001')->first();
        if (!$account) {
            throw new \Exception('Inventory account (104001 — Stock in Hand) not found. Please check your Chart of Accounts seeder.');
        }
        return $account;
    }

    // ─────────────────────────────────────────────────────────────
    // INDEX
    // ─────────────────────────────────────────────────────────────
    public function index(Request $request)
    {
        $user  = auth()->user();
        $query = PurchaseInvoice::with(['vendor', 'attachments']);

        if ($request->has('view_deleted')) {
            $query->onlyTrashed();
        }

        // Non-superadmin sees only their own records
        if (!$user->hasRole('superadmin')) {
            $query->where('created_by', $user->id);
        }

        $invoices = $query->latest()->get();

        return view('purchases.index', compact('invoices'));
    }

    // ─────────────────────────────────────────────────────────────
    // CREATE FORM
    // ─────────────────────────────────────────────────────────────
    public function create()
    {
        $products = Product::with('variations')->orderBy('name')->get();
        $vendors  = ChartOfAccounts::where('account_type', 'vendor')->orderBy('name')->get();
        $units    = MeasurementUnit::all();

        return view('purchases.create', compact('products', 'vendors', 'units'));
    }

    // ─────────────────────────────────────────────────────────────
    // STORE  (Purchase Invoice)
    //
    // Accounting entry:
    //   DR  Inventory / Stock in Hand  (asset increases)
    //   CR  Vendor / Accounts Payable  (liability increases)
    // ─────────────────────────────────────────────────────────────
    public function store(Request $request)
    {
        Log::info('[PI] Store started', ['user_id' => auth()->id()]);

        $request->validate([
            'invoice_date'           => 'required|date',
            'vendor_id'              => 'required|exists:chart_of_accounts,id',
            'bill_no'                => 'nullable|string|max:100',
            'ref_no'                 => 'nullable|string|max:100',
            'remarks'                => 'nullable|string',
            'attachments.*'          => 'nullable|file|mimes:jpg,jpeg,png,pdf,zip|max:2048',
            'items'                  => 'required|array|min:1',
            'items.*.item_id'        => 'required|exists:products,id',
            'items.*.variation_id'   => 'nullable|exists:product_variations,id',
            'items.*.quantity'       => 'required|numeric|min:0.01',
            'items.*.unit'           => 'required|exists:measurement_units,id',
            'items.*.price'          => 'required|numeric|min:0',
        ]);

        DB::beginTransaction();

        try {
            // ── Auto-generate invoice number ──────────────────────
            $last      = PurchaseInvoice::withTrashed()->orderByDesc('id')->first();
            $invoiceNo = str_pad($last ? intval($last->invoice_no) + 1 : 1, 6, '0', STR_PAD_LEFT);

            // ── Create invoice header ─────────────────────────────
            $invoice = PurchaseInvoice::create([
                'invoice_no'   => $invoiceNo,
                'vendor_id'    => $request->vendor_id,
                'invoice_date' => $request->invoice_date,
                'bill_no'      => $request->bill_no,
                'ref_no'       => $request->ref_no,
                'remarks'      => $request->remarks,
                'created_by'   => auth()->id(),
            ]);

            Log::info('[PI] Header created', ['invoice_id' => $invoice->id, 'invoice_no' => $invoiceNo]);

            // ── Process line items ────────────────────────────────
            $totalAmount = 0;

            foreach ($request->items as $itemData) {
                $qty       = (float) ($itemData['quantity'] ?? 0);
                $price     = (float) ($itemData['price']    ?? 0);
                $lineTotal = $qty * $price;
                $totalAmount += $lineTotal;

                $invoice->items()->create([
                    'item_id'      => $itemData['item_id'],
                    'variation_id' => $itemData['variation_id'] ?? null,
                    'quantity'     => $qty,
                    'unit'         => $itemData['unit'],
                    'price'        => $price,
                ]);

                // ── Increment stock only when a variation is linked ──
                if (!empty($itemData['variation_id'])) {
                    $variation = ProductVariation::find($itemData['variation_id']);
                    if ($variation) {
                        $variation->increment('stock_quantity', $qty);
                        Log::info('[PI] Stock incremented', ['variation_id' => $variation->id, 'qty' => $qty]);
                    } else {
                        Log::warning('[PI] Variation not found', ['variation_id' => $itemData['variation_id']]);
                    }
                }
            }

            // ── FIX: DR Inventory / CR Vendor ────────────────────
            $inventoryAccount = $this->inventoryAccount();

            Voucher::create([
                'date'         => $request->invoice_date,
                'voucher_type' => 'journal',
                'ac_dr_sid'    => $inventoryAccount->id,   // DR: Inventory (asset ↑)
                'ac_cr_sid'    => $request->vendor_id,     // CR: Vendor (liability ↑)
                'amount'       => $totalAmount,
                'reference'    => 'PI-' . $invoice->id,
                'remarks'      => "Purchase Invoice #{$invoiceNo}",
            ]);

            Log::info('[PI] Voucher created', ['amount' => $totalAmount]);

            // ── Attachments ───────────────────────────────────────
            if ($request->hasFile('attachments')) {
                foreach ($request->file('attachments') as $file) {
                    $path = $file->store('purchase_invoices', 'public');
                    $invoice->attachments()->create([
                        'file_path'     => $path,
                        'original_name' => $file->getClientOriginalName(),
                        'file_type'     => $file->getClientMimeType(),
                    ]);
                }
            }

            DB::commit();
            Log::info('[PI] Stored successfully', ['invoice_id' => $invoice->id]);

            return redirect()->route('purchase_invoices.index')
                ->with('success', 'Purchase Invoice created successfully.');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('[PI] Store error', [
                'message' => $e->getMessage(),
                'line'    => $e->getLine(),
                'file'    => $e->getFile(),
            ]);

            return back()->withInput()->withErrors(['error' => $e->getMessage()]);
        }
    }

    // ─────────────────────────────────────────────────────────────
    // EDIT FORM
    // ─────────────────────────────────────────────────────────────
    public function edit($id)
    {
        $invoice  = PurchaseInvoice::with(['items.product.variations', 'items.variation', 'attachments'])
                        ->findOrFail($id);
        $vendors  = ChartOfAccounts::where('account_type', 'vendor')->orderBy('name')->get();
        $products = Product::with('variations')->select('id', 'name', 'measurement_unit')->get();
        $units    = MeasurementUnit::all();

        return view('purchases.edit', compact('invoice', 'vendors', 'products', 'units'));
    }

    // ─────────────────────────────────────────────────────────────
    // UPDATE
    //
    // Steps:
    //   1. Reverse old stock
    //   2. Update invoice header
    //   3. Delete old items, insert new items, apply new stock
    //   4. updateOrCreate the journal voucher
    //   5. Handle new attachments
    // ─────────────────────────────────────────────────────────────
    public function update(Request $request, $id)
    {
        $request->validate([
            'invoice_date'           => 'required|date',
            'vendor_id'              => 'required|exists:chart_of_accounts,id',
            'bill_no'                => 'nullable|string|max:100',
            'ref_no'                 => 'nullable|string|max:100',
            'remarks'                => 'nullable|string',
            'attachments.*'          => 'nullable|file|mimes:jpg,jpeg,png,pdf,zip|max:2048',
            'items'                  => 'required|array|min:1',
            'items.*.item_id'        => 'required|exists:products,id',
            'items.*.variation_id'   => 'nullable|exists:product_variations,id',
            'items.*.quantity'       => 'required|numeric|min:0.01',
            'items.*.unit'           => 'required|exists:measurement_units,id',
            'items.*.price'          => 'required|numeric|min:0',
        ]);

        DB::beginTransaction();

        try {
            $invoice = PurchaseInvoice::with('items')->findOrFail($id);

            // ── Step 1: Reverse old stock ─────────────────────────
            foreach ($invoice->items as $oldItem) {
                if ($oldItem->variation_id) {
                    $oldVariation = ProductVariation::find($oldItem->variation_id);
                    if ($oldVariation) {
                        $oldVariation->decrement('stock_quantity', $oldItem->quantity);
                    }
                }
            }

            // ── Step 2: Update invoice header ─────────────────────
            $invoice->update([
                'vendor_id'    => $request->vendor_id,
                'invoice_date' => $request->invoice_date,
                'bill_no'      => $request->bill_no,
                'ref_no'       => $request->ref_no,
                'remarks'      => $request->remarks,
            ]);

            // ── Step 3: Replace line items & apply new stock ──────
            $invoice->items()->delete();
            $totalAmount = 0;

            foreach ($request->items as $itemData) {
                if (empty($itemData['item_id'])) continue;

                $qty         = (float) $itemData['quantity'];
                $price       = (float) $itemData['price'];
                $variationId = $itemData['variation_id'] ?? null;
                $totalAmount += $qty * $price;

                $invoice->items()->create([
                    'item_id'      => $itemData['item_id'],
                    'variation_id' => $variationId,
                    'quantity'     => $qty,
                    'unit'         => $itemData['unit'] ?? null,
                    'price'        => $price,
                ]);

                if ($variationId) {
                    $variation = ProductVariation::find($variationId);
                    if ($variation) {
                        $variation->increment('stock_quantity', $qty);
                    }
                }
            }

            // ── Step 4: Update journal voucher ────────────────────
            // FIX: DR Inventory / CR Vendor  (same rule as store)
            $inventoryAccount = $this->inventoryAccount();

            Voucher::updateOrCreate(
                [
                    'reference'    => 'PI-' . $invoice->id,
                    'voucher_type' => 'journal',
                ],
                [
                    'date'      => $request->invoice_date,
                    'ac_dr_sid' => $inventoryAccount->id,  // DR: Inventory (asset ↑)
                    'ac_cr_sid' => $request->vendor_id,    // CR: Vendor (liability ↑)
                    'amount'    => $totalAmount,
                    'remarks'   => "Updated Purchase Invoice #{$invoice->invoice_no}",
                ]
            );

            // ── Step 5: New attachments ───────────────────────────
            if ($request->hasFile('attachments')) {
                foreach ($request->file('attachments') as $file) {
                    $path = $file->store('purchase_invoices', 'public');
                    $invoice->attachments()->create([
                        'file_path'     => $path,
                        'original_name' => $file->getClientOriginalName(),
                        'file_type'     => $file->getClientMimeType(),
                    ]);
                }
            }

            DB::commit();
            return redirect()->route('purchase_invoices.index')->with('success', 'Purchase Invoice updated successfully.');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('[PI] Update error', ['message' => $e->getMessage(), 'line' => $e->getLine()]);
            return back()->withErrors(['error' => 'Failed to update: ' . $e->getMessage()]);
        }
    }

    // ─────────────────────────────────────────────────────────────
    // DESTROY  (soft delete)
    //
    // Steps:
    //   1. Reverse stock
    //   2. Delete (soft) voucher
    //   3. Soft-delete items and invoice
    // ─────────────────────────────────────────────────────────────
    public function destroy($id)
    {
        $invoice = PurchaseInvoice::with('items')->findOrFail($id);

        DB::beginTransaction();

        try {
            // Step 1: Reverse stock
            foreach ($invoice->items as $item) {
                if ($item->variation_id) {
                    $variation = ProductVariation::find($item->variation_id);
                    if ($variation) {
                        $variation->decrement('stock_quantity', $item->quantity);
                    }
                }
            }

            // Step 2: Remove accounting voucher
            Voucher::where('reference', 'PI-' . $invoice->id)->delete();

            // Step 3: Soft-delete items and invoice
            $invoice->items()->delete();
            $invoice->delete();

            DB::commit();
            return redirect()->route('purchase_invoices.index')
                ->with('success', 'Invoice deleted and stock adjusted.');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('[PI] Destroy error', ['message' => $e->getMessage()]);
            return back()->with('error', 'Failed to delete invoice.');
        }
    }

    // ─────────────────────────────────────────────────────────────
    // RESTORE  (undo soft delete)
    // ─────────────────────────────────────────────────────────────
    public function restore($id)
    {
        $invoice = PurchaseInvoice::onlyTrashed()->with('items')->findOrFail($id);

        DB::beginTransaction();

        try {
            $invoice->restore();

            foreach ($invoice->items()->onlyTrashed()->get() as $item) {
                $item->restore();
                if ($item->variation_id) {
                    $variation = ProductVariation::find($item->variation_id);
                    if ($variation) {
                        $variation->increment('stock_quantity', $item->quantity);
                    }
                }
            }

            Voucher::onlyTrashed()->where('reference', 'PI-' . $invoice->id)->restore();

            DB::commit();
            return redirect()->back()->with('success', 'Invoice and stock restored successfully.');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('[PI] Restore error', ['message' => $e->getMessage()]);
            return redirect()->back()->with('error', 'Restore failed: ' . $e->getMessage());
        }
    }

    // ─────────────────────────────────────────────────────────────
    // PRINT  (PDF)
    // ─────────────────────────────────────────────────────────────
    public function print($id)
    {
        $invoice = PurchaseInvoice::with(['vendor', 'items.product', 'items.variation'])->findOrFail($id);

        $pdf = new \TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        $pdf->SetCreator('BillTrix');
        $pdf->SetAuthor('Lucky Corporation');
        $pdf->SetTitle('PUR-' . $invoice->invoice_no);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(15, 15, 15);
        $pdf->SetAutoPageBreak(true, 20);
        $pdf->AddPage();

        // Header
        $logoPath = public_path('assets/img/logo.png');
        if (file_exists($logoPath)) {
            $pdf->Image($logoPath, 15, 12, 35);
        }

        $pdf->SetFont('helvetica', 'B', 16);
        $pdf->SetXY(110, 12);
        $pdf->Cell(85, 10, 'PURCHASE INVOICE', 0, 1, 'R');

        $pdf->SetFont('helvetica', '', 10);
        $pdf->SetXY(110, 20);
        $pdf->Cell(85, 5, 'Invoice #: ' . $invoice->invoice_no, 0, 1, 'R');
        $pdf->SetX(110);
        $pdf->Cell(85, 5, 'Date: ' . Carbon::parse($invoice->invoice_date)->format('d-M-Y'), 0, 1, 'R');
        $pdf->Ln(5);

        // Vendor block
        $vendorHtml = '
        <table width="40%" border="1" cellpadding="3" style="font-size:10px;">
            <tr><td width="40%"><b>Vendor:</b></td><td width="60%">' . ($invoice->vendor->name ?? 'N/A') . '</td></tr>
            <tr><td><b>Bill No:</b></td><td>'  . ($invoice->bill_no ?? '-') . '</td></tr>
            <tr><td><b>Ref:</b></td><td>'       . ($invoice->ref_no  ?? '-') . '</td></tr>
        </table>';
        $pdf->writeHTML($vendorHtml, true, false, false, false, '');
        $pdf->Ln(5);

        // Items table
        $html = '
        <table border="1" cellpadding="5" style="font-size:10px;">
            <thead>
                <tr style="background-color:#f2f2f2;font-weight:bold;text-align:center;">
                    <th width="5%">#</th>
                    <th width="35%">Item Description</th>
                    <th width="20%">Variation</th>
                    <th width="10%">Qty</th>
                    <th width="15%">Price</th>
                    <th width="15%">Total</th>
                </tr>
            </thead>
            <tbody>';

        $totalAmount = 0;
        foreach ($invoice->items as $index => $item) {
            $variationName = $item->variation->sku ?? $item->variation->variation_name ?? '-';
            $lineTotal      = $item->quantity * $item->price;
            $totalAmount   += $lineTotal;

            $html .= '
                <tr>
                    <td width="5%"  style="text-align:center;">' . ($index + 1) . '</td>
                    <td width="35%">' . e($item->product->name ?? '-') . '</td>
                    <td width="20%" style="text-align:center;">' . e($variationName) . '</td>
                    <td width="10%" style="text-align:center;">' . number_format($item->quantity, 2) . '</td>
                    <td width="15%" style="text-align:right;">'  . number_format($item->price, 2) . '</td>
                    <td width="15%" style="text-align:right;">'  . number_format($lineTotal, 2) . '</td>
                </tr>';
        }

        $html .= '
                <tr style="font-weight:bold;background-color:#fafafa;">
                    <td colspan="5" style="text-align:right;">Total Amount</td>
                    <td style="text-align:right;">' . number_format($totalAmount, 2) . '</td>
                </tr>
            </tbody>
        </table>';

        $pdf->writeHTML($html, true, false, false, false, '');

        if ($invoice->remarks) {
            $pdf->Ln(2);
            $pdf->SetFont('helvetica', 'I', 9);
            $pdf->MultiCell(0, 5, 'Remarks: ' . $invoice->remarks, 0, 'L');
        }

        // Signatures
        $pdf->SetFont('helvetica', '', 10);
        $ySign = $pdf->GetY() + 25;
        if ($ySign > 250) { $pdf->AddPage(); $ySign = 30; }

        $pdf->Line(15, $ySign, 75, $ySign);
        $pdf->SetXY(15, $ySign + 2);
        $pdf->Cell(60, 5, 'Prepared By', 0, 0, 'C');

        $pdf->Line(135, $ySign, 195, $ySign);
        $pdf->SetXY(135, $ySign + 2);
        $pdf->Cell(60, 5, 'Authorized Signature', 0, 0, 'C');

        return $pdf->Output('PI_' . $invoice->invoice_no . '.pdf', 'I');
    }
}