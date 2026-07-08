<?php

namespace App\Http\Controllers;

use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoiceItem;
use App\Models\PurchaseInvoiceAttachment;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Product;
use App\Models\ProductVariation;
use App\Models\Voucher;
use App\Models\MeasurementUnit;
use App\Models\ChartOfAccounts;
use App\Services\VoucherService;
use App\Services\StockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class PurchaseInvoiceController extends Controller
{
    private function inventoryAccount(): ChartOfAccounts
    {
        $account = ChartOfAccounts::where('account_code', '104001')->first();
        if (!$account) {
            throw new \Exception('Inventory account (104001 — Stock in Hand) not found. Please check your Chart of Accounts seeder.');
        }
        return $account;
    }

    public function index(Request $request)
    {
        $user  = auth()->user();
        $query = PurchaseInvoice::with(['vendor', 'attachments', 'purchaseOrder']);

        if ($request->has('view_deleted')) {
            $query->onlyTrashed();
        }

        if (!$user->hasRole('superadmin')) {
            $query->where('created_by', $user->id);
        }

        $invoices = $query->latest()->get();

        return view('purchases.index', compact('invoices'));
    }

    public function create(Request $request)
    {
        $products = Product::with('variations')->orderBy('name')->get();
        $vendors  = ChartOfAccounts::where('account_type', 'vendor')->orderBy('name')->get();
        $units    = MeasurementUnit::all();
        $locations = Location::where('is_active', true)->orderBy('name')->get();
        $openOrders = PurchaseOrder::whereIn('status', ['pending', 'partial'])
            ->orderByDesc('id')->get(['id', 'order_no', 'vendor_id']);

        return view('purchases.create', compact('products', 'locations' ,'vendors', 'units', 'openOrders'));
    }

    public function edit($id)
    {
        $invoice  = PurchaseInvoice::with(['items.product.variations', 'items.variation', 'attachments'])->findOrFail($id);
        $vendors  = ChartOfAccounts::where('account_type', 'vendor')->orderBy('name')->get();
        $products = Product::with('variations')->select('id', 'name', 'measurement_unit')->get();
        $locations = Location::where('is_active', true)->orderBy('name')->get();
        $units    = MeasurementUnit::all();

        return view('purchases.edit', compact('invoice', 'vendors', 'locations' ,'products', 'units'));
    }

    /**
     * STORE
     *
     * Accounting entry:
     *   Dr Inventory / Stock in Hand   (asset increases)
     *   Cr Vendor / Accounts Payable   (liability increases)
     */
    /**
     * STORE
     *
     * Accounting entry:
     *   Dr Inventory / Stock in Hand   (asset increases)
     *   Cr Vendor / Accounts Payable   (liability increases)
     */
    public function store(Request $request)
    {
        Log::info('[PI] Store started', ['user_id' => auth()->id()]);

        $request->validate([
            'invoice_date'           => 'required|date',
            'vendor_id'              => 'required|exists:chart_of_accounts,id',
            'purchase_order_id'      => 'nullable|exists:purchase_orders,id',
            'location_id'            => 'nullable|exists:locations,id',
            'bill_no'                => 'nullable|string|max:100',
            'ref_no'                 => 'nullable|string|max:100',
            'remarks'                => 'nullable|string',
            'attachments.*'          => 'nullable|file|mimes:jpg,jpeg,png,pdf,zip|max:2048',
            'items'                  => 'required|array|min:1',
            'items.*.item_id'        => 'required|exists:products,id',
            'items.*.variation_id'   => 'nullable|exists:product_variations,id',
            'items.*.po_item_id'     => 'nullable|exists:purchase_order_items,id',
            'items.*.quantity'       => 'required|numeric|min:0.01',
            'items.*.unit'           => 'required|exists:measurement_units,id',
            'items.*.price'          => 'required|numeric|min:0',
        ]);

        DB::beginTransaction();

        try {
            $last      = PurchaseInvoice::withTrashed()->lockForUpdate()->orderByDesc('id')->first();
            $invoiceNo = str_pad($last ? intval($last->invoice_no) + 1 : 1, 6, '0', STR_PAD_LEFT);

            $locationId = $request->location_id ?? StockService::defaultLocationId();

            $invoice = PurchaseInvoice::create([
                'invoice_no'         => $invoiceNo,
                'vendor_id'          => $request->vendor_id,
                'purchase_order_id'  => $request->purchase_order_id,
                'location_id'        => $locationId,
                'invoice_date'       => $request->invoice_date,
                'bill_no'            => $request->bill_no,
                'ref_no'             => $request->ref_no,
                'remarks'            => $request->remarks,
                'created_by'         => auth()->id(),
                'updated_by'         => auth()->id(),
            ]);

            Log::info('[PI] Header created', ['invoice_id' => $invoice->id, 'invoice_no' => $invoiceNo]);

            $totalAmount = 0;
            $totalQty    = 0;

            foreach ($request->items as $itemData) {
                $qty       = (float) ($itemData['quantity'] ?? 0);
                $price     = (float) ($itemData['price']    ?? 0);
                $lineTotal = $qty * $price;
                $totalAmount += $lineTotal;
                $totalQty    += $qty;

                $invoice->items()->create([
                    'item_id'      => $itemData['item_id'],
                    'variation_id' => $itemData['variation_id'] ?? null,
                    'po_item_id'   => $itemData['po_item_id'] ?? null,
                    'quantity'     => $qty,
                    'unit'         => $itemData['unit'],
                    'price'        => $price,
                ]);

                StockService::move(
                    $itemData['item_id'],
                    $itemData['variation_id'] ?? null,
                    $qty,
                    'in',
                    'purchase_invoice',
                    $invoice->id,
                    "Purchase Invoice #{$invoiceNo}",
                    $locationId
                );

                // Cost tracking — last purchase price becomes current cost (used by Sale COGS)
                $target = ($itemData['variation_id'] ?? null)
                    ? ProductVariation::find($itemData['variation_id'])
                    : Product::find($itemData['item_id']);
                $target?->update(['cost_price' => $price]);

                if (!empty($itemData['po_item_id'])) {
                    $poItem = PurchaseOrderItem::find($itemData['po_item_id']);
                    if ($poItem) {
                        $poItem->increment('received_quantity', $qty);
                    }
                }
            }

            if ($request->purchase_order_id) {
                $order = PurchaseOrder::find($request->purchase_order_id);
                $order?->refreshStatus();
            }

            $invoice->update([
                'total_amount'   => $totalAmount,
                'total_quantity' => $totalQty,
                'net_amount'     => $totalAmount,
            ]);

            // Skip posting an empty voucher for a zero-value invoice (e.g. free samples)
            if ($totalAmount > 0) {
                $inventoryAccount = $this->inventoryAccount();

                VoucherService::postEntries(
                    [
                        'voucher_type'   => 'purchase',
                        'voucher_date'   => $request->invoice_date,
                        'reference_type' => PurchaseInvoice::class,
                        'reference_id'   => $invoice->id,
                        'remarks'        => "Purchase Invoice #{$invoiceNo}",
                    ],
                    [
                        ['account_id' => $inventoryAccount->id, 'debit' => $totalAmount, 'credit' => 0, 'narration' => 'Stock received'],
                        ['account_id' => $request->vendor_id,   'debit' => 0, 'credit' => $totalAmount, 'narration' => 'Vendor payable'],
                    ]
                );

                Log::info('[PI] Voucher posted', ['amount' => $totalAmount]);
            }

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

    public function update(Request $request, $id)
    {
        $request->validate([
            'invoice_date'           => 'required|date',
            'vendor_id'              => 'required|exists:chart_of_accounts,id',
            'location_id'            => 'nullable|exists:locations,id',
            'bill_no'                => 'nullable|string|max:100',
            'ref_no'                 => 'nullable|string|max:100',
            'remarks'                => 'nullable|string',
            'attachments.*'          => 'nullable|file|mimes:jpg,jpeg,png,pdf,zip|max:2048',
            'items'                  => 'required|array|min:1',
            'items.*.item_id'        => 'required|exists:products,id',
            'items.*.variation_id'   => 'nullable|exists:product_variations,id',
            'items.*.po_item_id'     => 'nullable|exists:purchase_order_items,id',
            'items.*.quantity'       => 'required|numeric|min:0.01',
            'items.*.unit'           => 'required|exists:measurement_units,id',
            'items.*.price'          => 'required|numeric|min:0',
        ]);

        DB::beginTransaction();

        try {
            $invoice = PurchaseInvoice::with('items')->findOrFail($id);

            // Reverse old stock at the location it was originally received into
            $oldLocationId = $invoice->location_id ?? StockService::defaultLocationId();

            foreach ($invoice->items as $oldItem) {
                StockService::move(
                    $oldItem->item_id, $oldItem->variation_id, $oldItem->quantity,
                    'out', 'purchase_invoice', $invoice->id, "Reversal — editing Purchase Invoice #{$invoice->invoice_no}",
                    $oldLocationId
                );

                if ($oldItem->po_item_id) {
                    $poItem = PurchaseOrderItem::find($oldItem->po_item_id);
                    $poItem?->decrement('received_quantity', $oldItem->quantity);
                }
            }

            $newLocationId = $request->location_id ?? $oldLocationId;

            $invoice->update([
                'vendor_id'    => $request->vendor_id,
                'location_id'  => $newLocationId,
                'invoice_date' => $request->invoice_date,
                'bill_no'      => $request->bill_no,
                'ref_no'       => $request->ref_no,
                'remarks'      => $request->remarks,
                'updated_by'   => auth()->id(),
            ]);

            $invoice->items()->delete();
            $totalAmount = 0;
            $totalQty    = 0;

            foreach ($request->items as $itemData) {
                if (empty($itemData['item_id'])) continue;

                $qty         = (float) $itemData['quantity'];
                $price       = (float) $itemData['price'];
                $variationId = $itemData['variation_id'] ?? null;
                $poItemId    = $itemData['po_item_id'] ?? null;

                $totalAmount += $qty * $price;
                $totalQty    += $qty;

                $invoice->items()->create([
                    'item_id'      => $itemData['item_id'],
                    'variation_id' => $variationId,
                    'po_item_id'   => $poItemId,
                    'quantity'     => $qty,
                    'unit'         => $itemData['unit'] ?? null,
                    'price'        => $price,
                ]);

                StockService::move(
                    $itemData['item_id'], $variationId, $qty,
                    'in', 'purchase_invoice', $invoice->id, "Updated Purchase Invoice #{$invoice->invoice_no}",
                    $newLocationId
                );

                $target = $variationId ? ProductVariation::find($variationId) : Product::find($itemData['item_id']);
                $target?->update(['cost_price' => $price]);

                if ($poItemId) {
                    $poItem = PurchaseOrderItem::find($poItemId);
                    $poItem?->increment('received_quantity', $qty);
                }
            }

            if ($invoice->purchase_order_id) {
                $order = PurchaseOrder::find($invoice->purchase_order_id);
                $order?->refreshStatus();
            }

            $invoice->update([
                'total_amount'   => $totalAmount,
                'total_quantity' => $totalQty,
                'net_amount'     => $totalAmount,
            ]);

            if ($totalAmount > 0) {
                $inventoryAccount = $this->inventoryAccount();

                VoucherService::postOrUpdateEntries(
                    PurchaseInvoice::class,
                    $invoice->id,
                    'purchase',
                    [
                        'voucher_date' => $request->invoice_date,
                        'remarks'      => "Updated Purchase Invoice #{$invoice->invoice_no}",
                    ],
                    [
                        ['account_id' => $inventoryAccount->id, 'debit' => $totalAmount, 'credit' => 0, 'narration' => 'Stock received'],
                        ['account_id' => $request->vendor_id,   'debit' => 0, 'credit' => $totalAmount, 'narration' => 'Vendor payable'],
                    ]
                );
            }

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

    public function destroy($id)
    {
        $invoice = PurchaseInvoice::with('items')->findOrFail($id);

        DB::beginTransaction();

        try {
            $locationId = $invoice->location_id ?? StockService::defaultLocationId();

            foreach ($invoice->items as $item) {
                StockService::move(
                    $item->item_id, $item->variation_id, $item->quantity,
                    'out', 'purchase_invoice', $invoice->id, "Deleted Purchase Invoice #{$invoice->invoice_no}",
                    $locationId
                );

                if ($item->po_item_id) {
                    $poItem = PurchaseOrderItem::find($item->po_item_id);
                    $poItem?->decrement('received_quantity', $item->quantity);
                }
            }

            if ($invoice->purchase_order_id) {
                $order = PurchaseOrder::find($invoice->purchase_order_id);
                $order?->refreshStatus();
            }

            Voucher::where('reference_type', PurchaseInvoice::class)
                ->where('reference_id', $invoice->id)
                ->delete();

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

    public function restore($id)
    {
        $invoice = PurchaseInvoice::onlyTrashed()->with('items')->findOrFail($id);

        DB::beginTransaction();

        try {
            $invoice->restore();

            $locationId = $invoice->location_id ?? StockService::defaultLocationId();

            foreach ($invoice->items()->onlyTrashed()->get() as $item) {
                $item->restore();

                StockService::move(
                    $item->item_id, $item->variation_id, $item->quantity,
                    'in', 'purchase_invoice', $invoice->id, "Restored Purchase Invoice #{$invoice->invoice_no}",
                    $locationId
                );

                if ($item->po_item_id) {
                    $poItem = PurchaseOrderItem::find($item->po_item_id);
                    $poItem?->increment('received_quantity', $item->quantity);
                }
            }

            if ($invoice->purchase_order_id) {
                $order = PurchaseOrder::find($invoice->purchase_order_id);
                $order?->refreshStatus();
            }

            Voucher::onlyTrashed()
                ->where('reference_type', PurchaseInvoice::class)
                ->where('reference_id', $invoice->id)
                ->restore();

            DB::commit();
            return redirect()->back()->with('success', 'Invoice and stock restored successfully.');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('[PI] Restore error', ['message' => $e->getMessage()]);
            return redirect()->back()->with('error', 'Restore failed: ' . $e->getMessage());
        }
    }

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

        $vendorHtml = '
        <table width="40%" border="1" cellpadding="3" style="font-size:10px;">
            <tr><td width="40%"><b>Vendor:</b></td><td width="60%">' . ($invoice->vendor->name ?? 'N/A') . '</td></tr>
            <tr><td><b>Bill No:</b></td><td>'  . ($invoice->bill_no ?? '-') . '</td></tr>
            <tr><td><b>Ref:</b></td><td>'       . ($invoice->ref_no  ?? '-') . '</td></tr>
        </table>';
        $pdf->writeHTML($vendorHtml, true, false, false, false, '');
        $pdf->Ln(5);

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