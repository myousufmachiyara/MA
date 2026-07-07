<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnItem;
use App\Models\PurchaseInvoice;
use App\Models\ProductVariation;
use App\Models\Product;
use App\Models\ChartOfAccounts;
use App\Models\MeasurementUnit;
use App\Models\Voucher;

class PurchaseReturnController extends Controller
{
    // ─────────────────────────────────────────────────────────────
    // Shared helper — same pattern as PurchaseInvoiceController
    // ─────────────────────────────────────────────────────────────
    private function inventoryAccount(): ChartOfAccounts
    {
        $account = ChartOfAccounts::where('account_code', '104001')->first();
        if (!$account) {
            throw new \Exception('Inventory account (104001 — Stock in Hand) not found.');
        }
        return $account;
    }

    // ─────────────────────────────────────────────────────────────
    // INDEX
    // ─────────────────────────────────────────────────────────────
    public function index()
    {
        // FIX: withSum syntax was using raw DB::raw inside a withSum call incorrectly.
        // Compute total via a proper subquery relationship instead.
        $returns = PurchaseReturn::with('vendor', 'items')
            ->latest()
            ->get()
            ->map(function ($return) {
                $return->total_amount = $return->items->sum(fn($i) => $i->quantity * $i->price);
                return $return;
            });

        return view('purchase-returns.index', compact('returns'));
    }

    // ─────────────────────────────────────────────────────────────
    // CREATE FORM
    // ─────────────────────────────────────────────────────────────
    public function create()
    {
        $invoices = PurchaseInvoice::with('vendor')->latest()->get();
        $products = Product::orderBy('name')->get();
        $vendors  = ChartOfAccounts::where('account_type', 'vendor')->orderBy('name')->get();
        $units    = MeasurementUnit::all();

        return view('purchase-returns.create', compact('invoices', 'products', 'units', 'vendors'));
    }

    // ─────────────────────────────────────────────────────────────
    // STORE
    //
    // Accounting entry:
    //   DR  Vendor / Accounts Payable   (liability decreases)
    //   CR  Inventory / Stock in Hand   (asset decreases)
    //
    // Stock: decrement variation stock_quantity
    // ─────────────────────────────────────────────────────────────
    public function store(Request $request)
    {
        Log::info('[PR] Store started', ['user_id' => auth()->id()]);

        $request->validate([
            'vendor_id'              => 'required|exists:chart_of_accounts,id',
            'return_date'            => 'required|date',
            'remarks'                => 'nullable|string|max:1000',
            'items'                  => 'required|array|min:1',
            'items.*.item_id'        => 'required|exists:products,id',
            'items.*.variation_id'   => 'nullable|exists:product_variations,id',
            'items.*.invoice_id'     => 'required|exists:purchase_invoices,id',
            'items.*.quantity'       => 'required|numeric|min:0.01',
            'items.*.unit'           => 'required|exists:measurement_units,id',
            'items.*.price'          => 'required|numeric|min:0',
        ]);

        DB::beginTransaction();

        try {
            // ── Create return header ──────────────────────────────
            $purchaseReturn = PurchaseReturn::create([
                'vendor_id'   => $request->vendor_id,
                'return_date' => $request->return_date,
                'remarks'     => $request->remarks,
                'created_by'  => auth()->id(),
            ]);

            Log::info('[PR] Header created', ['id' => $purchaseReturn->id]);

            // ── Create items, reverse stock, tally total ──────────
            $totalAmount = 0;

            foreach ($request->items as $item) {
                $qty       = (float) $item['quantity'];
                $price     = (float) $item['price'];
                $totalAmount += $qty * $price;

                PurchaseReturnItem::create([
                    'purchase_return_id'  => $purchaseReturn->id,
                    'item_id'             => $item['item_id'],
                    'variation_id'        => $item['variation_id'] ?? null,
                    'purchase_invoice_id' => $item['invoice_id'],
                    'quantity'            => $qty,
                    'unit_id'             => $item['unit'],
                    'price'               => $price,
                    'amount'              => $qty * $price,
                ]);

                // FIX: decrement stock (goods leaving warehouse back to vendor)
                if (!empty($item['variation_id'])) {
                    $variation = ProductVariation::find($item['variation_id']);
                    if ($variation) {
                        $variation->decrement('stock_quantity', $qty);
                        Log::info('[PR] Stock decremented', ['variation_id' => $variation->id, 'qty' => $qty]);
                    }
                }
            }

            // ── FIX: Create accounting voucher ────────────────────
            // DR: Vendor (liability decreases — we owe vendor less)
            // CR: Inventory (asset decreases — goods sent back)
            $inventoryAccount = $this->inventoryAccount();

            Voucher::create([
                'date'         => $request->return_date,
                'voucher_type' => 'journal',
                'ac_dr_sid'    => $request->vendor_id,      // DR: Vendor
                'ac_cr_sid'    => $inventoryAccount->id,    // CR: Inventory
                'amount'       => $totalAmount,
                'reference'    => 'PR-' . $purchaseReturn->id,
                'remarks'      => "Purchase Return #{$purchaseReturn->id}",
            ]);

            Log::info('[PR] Voucher created', ['amount' => $totalAmount]);

            DB::commit();
            Log::info('[PR] Stored successfully', ['id' => $purchaseReturn->id]);

            return redirect()->route('purchase_return.index')
                ->with('success', 'Purchase Return saved successfully.');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('[PR] Store failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);

            return back()->withInput()->withErrors(['error' => 'Failed to save: ' . $e->getMessage()]);
        }
    }

    // ─────────────────────────────────────────────────────────────
    // EDIT FORM
    // ─────────────────────────────────────────────────────────────
    public function edit($id)
    {
        $purchaseReturn = PurchaseReturn::with([
            'items',
            'items.item.purchaseInvoices',
            'items.variation',
            'items.invoice',
            'items.unit',
        ])->findOrFail($id);

        $products = Product::orderBy('name')->get();
        $vendors  = ChartOfAccounts::where('account_type', 'vendor')->orderBy('name')->get();
        $units    = MeasurementUnit::all();
        $invoices = PurchaseInvoice::with('vendor')->latest()->get();

        return view('purchase-returns.edit', compact('purchaseReturn', 'products', 'vendors', 'units', 'invoices'));
    }

    // ─────────────────────────────────────────────────────────────
    // UPDATE
    //
    // Steps:
    //   1. Re-add old stock (reverse the old return)
    //   2. Replace items (delete + re-insert)
    //   3. Decrement stock for new items
    //   4. updateOrCreate journal voucher
    // ─────────────────────────────────────────────────────────────
    public function update(Request $request, $id)
    {
        Log::info('[PR] Update started', ['id' => $id]);

        $request->validate([
            'vendor_id'              => 'required|exists:chart_of_accounts,id',
            'return_date'            => 'required|date',
            'remarks'                => 'nullable|string|max:1000',
            'items'                  => 'required|array|min:1',
            'items.*.item_id'        => 'required|exists:products,id',
            'items.*.variation_id'   => 'nullable|exists:product_variations,id',
            'items.*.invoice_id'     => 'required|exists:purchase_invoices,id',
            'items.*.quantity'       => 'required|numeric|min:0.01',
            'items.*.unit'           => 'required|exists:measurement_units,id',
            'items.*.price'          => 'required|numeric|min:0',
        ]);

        DB::beginTransaction();

        try {
            $purchaseReturn = PurchaseReturn::with('items')->findOrFail($id);

            // ── Step 1: Re-add stock from old items (reverse old return) ──
            foreach ($purchaseReturn->items as $oldItem) {
                if ($oldItem->variation_id) {
                    $variation = ProductVariation::find($oldItem->variation_id);
                    if ($variation) {
                        $variation->increment('stock_quantity', $oldItem->quantity);
                    }
                }
            }

            // ── Step 2: Update header ─────────────────────────────
            $purchaseReturn->update([
                'vendor_id'   => $request->vendor_id,
                'return_date' => $request->return_date,
                'remarks'     => $request->remarks,
            ]);

            // ── Step 3: Replace items + decrement stock ───────────
            PurchaseReturnItem::where('purchase_return_id', $purchaseReturn->id)->delete();
            $totalAmount = 0;

            foreach ($request->items as $item) {
                $qty       = (float) $item['quantity'];
                $price     = (float) $item['price'];
                $totalAmount += $qty * $price;

                PurchaseReturnItem::create([
                    'purchase_return_id'  => $purchaseReturn->id,
                    'item_id'             => $item['item_id'],
                    'variation_id'        => $item['variation_id'] ?? null,
                    'purchase_invoice_id' => $item['invoice_id'],
                    'quantity'            => $qty,
                    'unit_id'             => $item['unit'],
                    'price'               => $price,
                    'amount'              => $qty * $price,
                    'remarks'             => $item['remarks'] ?? null,
                ]);

                // FIX: decrement new quantities
                if (!empty($item['variation_id'])) {
                    $variation = ProductVariation::find($item['variation_id']);
                    if ($variation) {
                        $variation->decrement('stock_quantity', $qty);
                    }
                }
            }

            // ── Step 4: Update journal voucher ────────────────────
            $inventoryAccount = $this->inventoryAccount();

            Voucher::updateOrCreate(
                [
                    'reference'    => 'PR-' . $purchaseReturn->id,
                    'voucher_type' => 'journal',
                ],
                [
                    'date'      => $request->return_date,
                    'ac_dr_sid' => $request->vendor_id,     // DR: Vendor (liability ↓)
                    'ac_cr_sid' => $inventoryAccount->id,   // CR: Inventory (asset ↓)
                    'amount'    => $totalAmount,
                    'remarks'   => "Updated Purchase Return #{$purchaseReturn->id}",
                ]
            );

            DB::commit();
            Log::info('[PR] Updated successfully', ['id' => $purchaseReturn->id]);

            return redirect()->route('purchase_return.index')
                ->with('success', 'Purchase Return updated successfully.');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('[PR] Update failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);

            return back()->withInput()->withErrors(['error' => 'Failed to update: ' . $e->getMessage()]);
        }
    }

    // ─────────────────────────────────────────────────────────────
    // PRINT  (PDF)
    // ─────────────────────────────────────────────────────────────
    public function print($id)
    {
        $return = PurchaseReturn::with(['vendor', 'items.item', 'items.unit', 'items.invoice'])->findOrFail($id);

        $pdf = new \TCPDF();
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetCreator('BillTrix');
        $pdf->SetAuthor('Lucky Corporation');
        $pdf->SetTitle('Purchase Return #' . $return->id);
        $pdf->SetMargins(10, 10, 10);
        $pdf->AddPage();
        $pdf->setCellPadding(1.5);

        // Logo
        $logoPath = public_path('assets/img/logo.png');
        if (file_exists($logoPath)) {
            $pdf->Image($logoPath, 8, 10, 40);
        }

        // Return info (top right)
        $pdf->SetXY(130, 12);
        $returnInfo = '
        <table cellpadding="2" style="font-size:10px;line-height:14px;">
            <tr><td><b>Return #</b></td><td>' . $return->id . '</td></tr>
            <tr><td><b>Date</b></td><td>' . Carbon::parse($return->return_date)->format('d/m/Y') . '</td></tr>
            <tr><td><b>Vendor</b></td><td>' . e($return->vendor->name ?? '-') . '</td></tr>
        </table>';
        $pdf->writeHTML($returnInfo, false, false, false, false, '');

        $pdf->Line(60, 52.25, 200, 52.25);

        // Title badge
        $pdf->SetXY(10, 48);
        $pdf->SetFillColor(23, 54, 93);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('helvetica', '', 12);
        $pdf->Cell(50, 8, 'Purchase Return', 0, 1, 'C', 1);
        $pdf->SetTextColor(0, 0, 0);

        // Items table
        $pdf->Ln(5);
        $html = '<table border="0.3" cellpadding="4" style="text-align:center;font-size:10px;">
            <tr style="background-color:#f5f5f5;font-weight:bold;">
                <th width="7%">S.No</th>
                <th width="28%">Item Name</th>
                <th width="10%">Inv. #</th>
                <th width="20%">Qty</th>
                <th width="15%">Rate</th>
                <th width="20%">Amount</th>
            </tr>';

        $totalAmount = 0;
        $count       = 0;

        foreach ($return->items as $item) {
            $count++;
            $amount       = $item->price * $item->quantity;
            $totalAmount += $amount;

            $html .= '
            <tr>
                <td align="center">' . $count . '</td>
                <td>' . e($item->item->name ?? '-') . '</td>
                <td align="center">' . ($item->invoice->id ?? '-') . '</td>
                <td align="center">' . number_format($item->quantity, 2) . ' ' . e($item->unit->shortcode ?? '-') . '</td>
                <td align="right">' . number_format($item->price, 2) . '</td>
                <td align="right">' . number_format($amount, 2) . '</td>
            </tr>';
        }

        $html .= '
            <tr>
                <td colspan="5" align="right"><b>Total</b></td>
                <td align="right"><b>' . number_format($totalAmount, 2) . '</b></td>
            </tr>
        </table>';

        $pdf->writeHTML($html, true, false, true, false, '');

        if (!empty($return->remarks)) {
            $pdf->writeHTML('<b>Remarks:</b><br><span style="font-size:12px;">' . nl2br(e($return->remarks)) . '</span>', true, false, true, false, '');
        }

        // Signatures
        $pdf->Ln(20);
        $yPos      = $pdf->GetY();
        $lineWidth = 40;

        $pdf->Line(28, $yPos, 28 + $lineWidth, $yPos);
        $pdf->Line(130, $yPos, 130 + $lineWidth, $yPos);
        $pdf->SetXY(28, $yPos + 2);
        $pdf->Cell($lineWidth, 6, 'Received By', 0, 0, 'C');
        $pdf->SetXY(130, $yPos + 2);
        $pdf->Cell($lineWidth, 6, 'Authorized By', 0, 0, 'C');

        return $pdf->Output('purchase_return_' . $return->id . '.pdf', 'I');
    }
}