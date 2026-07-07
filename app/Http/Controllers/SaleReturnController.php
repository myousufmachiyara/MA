<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\SaleReturn;
use App\Models\SaleReturnItem;
use App\Models\SaleInvoice;
use App\Models\ChartOfAccounts;
use App\Models\Product;
use App\Models\ProductVariation;
use App\Models\Voucher;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class SaleReturnController extends Controller
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
    // INDEX
    // ─────────────────────────────────────────────────────────────
    public function index()
    {
        $returns = SaleReturn::with(['customer', 'items.product', 'items.variation'])
            ->latest()
            ->get()
            ->map(function ($return) {
                $return->total_amount = $return->items->sum(fn($item) => $item->qty * $item->price);
                return $return;
            });

        return view('sale_returns.index', compact('returns'));
    }

    // ─────────────────────────────────────────────────────────────
    // CREATE FORM
    // ─────────────────────────────────────────────────────────────
    public function create()
    {
        return view('sale_returns.create', [
            'products'  => Product::orderBy('name')->get(),
            'customers' => ChartOfAccounts::where('account_type', 'customer')->orderBy('name')->get(),
            'invoices'  => SaleInvoice::latest()->get(),
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    // STORE
    //
    // Accounting entries:
    //   Entry A — Reverse revenue (journal):
    //     DR  Sales Revenue           (revenue ↓)
    //     CR  Customer / Receivable   (asset ↓)
    //
    //   Entry B — Restore inventory / reverse COGS (journal):
    //     DR  Inventory / Stock       (asset ↑)
    //     CR  Cost of Goods Sold      (expense ↓)
    //
    // Stock: increment variation stock_quantity (goods come back)
    // ─────────────────────────────────────────────────────────────
    public function store(Request $request)
    {
        $validated = $request->validate([
            'customer_id'          => 'required|exists:chart_of_accounts,id',
            'return_date'          => 'required|date',
            'sale_invoice_no'      => 'nullable|string|max:50',
            'remarks'              => 'nullable|string|max:500',
            'items'                => 'required|array|min:1',
            'items.*.product_id'   => 'required|exists:products,id',
            'items.*.variation_id' => 'nullable|exists:product_variations,id',
            'items.*.qty'          => 'required|numeric|min:0.01',
            'items.*.price'        => 'required|numeric|min:0',
        ]);

        DB::beginTransaction();

        try {
            Log::info('[SR] Store started', ['user_id' => Auth::id()]);

            // ── Create return header ──────────────────────────────
            $return = SaleReturn::create([
                'account_id'      => $validated['customer_id'],
                'return_date'     => $validated['return_date'],
                'sale_invoice_no' => $validated['sale_invoice_no'] ?? null,
                'remarks'         => $validated['remarks'] ?? null,
                'created_by'      => Auth::id(),
            ]);

            Log::info('[SR] Header created', ['return_id' => $return->id]);

            // ── Create items, restore stock, tally total ──────────
            $totalAmount = 0;

            foreach ($validated['items'] as $idx => $item) {
                $qty         = (float) $item['qty'];
                $price       = (float) $item['price'];
                $totalAmount += $qty * $price;

                SaleReturnItem::create([
                    'sale_return_id' => $return->id,
                    'product_id'     => $item['product_id'],
                    'variation_id'   => $item['variation_id'] ?? null,
                    'qty'            => $qty,
                    'price'          => $price,
                ]);

                // FIX: restore stock (goods come back from customer)
                if (!empty($item['variation_id'])) {
                    $variation = ProductVariation::find($item['variation_id']);
                    if ($variation) {
                        $variation->increment('stock_quantity', $qty);
                        Log::info('[SR] Stock restored', ['variation_id' => $variation->id, 'qty' => $qty]);
                    }
                }
            }

            Log::info('[SR] Items created', ['return_id' => $return->id, 'total' => $totalAmount]);

            // ── FIX: Entry A — Reverse revenue ───────────────────
            $salesAccount = $this->salesRevenueAccount();

            Voucher::create([
                'voucher_type' => 'journal',
                'date'         => $validated['return_date'],
                'ac_dr_sid'    => $salesAccount->id,             // DR: Sales Revenue (revenue ↓)
                'ac_cr_sid'    => $validated['customer_id'],     // CR: Customer (asset ↓)
                'amount'       => $totalAmount,
                'reference'    => 'SR-' . $return->id,
                'remarks'      => "Sale Return #{$return->id} — revenue reversal",
            ]);

            // ── FIX: Entry B — Restore inventory / reverse COGS ──
            $inventoryAccount = $this->inventoryAccount();
            $cogsAccount      = $this->cogsAccount();

            if ($cogsAccount) {
                Voucher::create([
                    'voucher_type' => 'journal',
                    'date'         => $validated['return_date'],
                    'ac_dr_sid'    => $inventoryAccount->id,  // DR: Inventory (asset ↑)
                    'ac_cr_sid'    => $cogsAccount->id,       // CR: COGS (expense ↓)
                    'amount'       => $totalAmount,            // ideally use original purchase cost
                    'reference'    => 'SR-' . $return->id,
                    'remarks'      => "Sale Return #{$return->id} — inventory restored",
                ]);
            } else {
                Log::warning('[SR] COGS account (501001) not found — inventory reversal skipped.');
            }

            DB::commit();
            Log::info('[SR] Stored successfully', ['return_id' => $return->id]);

            return redirect()->route('sale_return.index')
                ->with('success', 'Sale return created successfully.');

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('[SR] Store failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return back()->withInput()
                ->with('error', 'Error saving sale return: ' . $e->getMessage());
        }
    }

    // ─────────────────────────────────────────────────────────────
    // EDIT FORM
    // ─────────────────────────────────────────────────────────────
    public function edit($id)
    {
        $return = SaleReturn::with(['items.product', 'items.variation'])->findOrFail($id);

        return view('sale_returns.edit', [
            'return'    => $return,
            'products'  => Product::orderBy('name')->get(),
            'customers' => ChartOfAccounts::where('account_type', 'customer')->orderBy('name')->get(),
            'invoices'  => SaleInvoice::latest()->get(),
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    // UPDATE
    //
    // Steps:
    //   1. Re-decrement stock from old items (reverse the old return)
    //   2. Replace items, restore stock for new items
    //   3. Delete and recreate journal vouchers
    // ─────────────────────────────────────────────────────────────
    public function update(Request $request, $id)
    {
        Log::info('[SR] Update started', ['return_id' => $id]);

        $validated = $request->validate([
            'account_id'           => 'required|exists:chart_of_accounts,id',
            'return_date'          => 'required|date',
            'sale_invoice_no'      => 'nullable|string|max:50',
            'remarks'              => 'nullable|string|max:500',
            'items'                => 'required|array|min:1',
            'items.*.product_id'   => 'required|exists:products,id',
            'items.*.variation_id' => 'nullable|exists:product_variations,id',
            'items.*.qty'          => 'required|numeric|min:0.01',
            'items.*.price'        => 'required|numeric|min:0',
        ]);

        DB::beginTransaction();

        try {
            $return    = SaleReturn::with('items')->findOrFail($id);
            $reference = 'SR-' . $return->id;

            // ── Step 1: Re-decrement stock (undo old return) ──────
            foreach ($return->items as $oldItem) {
                if ($oldItem->variation_id) {
                    $variation = ProductVariation::find($oldItem->variation_id);
                    if ($variation) {
                        $variation->decrement('stock_quantity', $oldItem->qty);
                    }
                }
            }

            // ── Step 2: Update header ─────────────────────────────
            $return->update([
                'account_id'      => $validated['account_id'],
                'return_date'     => $validated['return_date'],
                'sale_invoice_no' => $validated['sale_invoice_no'] ?? null,
                'remarks'         => $validated['remarks'] ?? null,
            ]);

            // ── Step 3: Replace items + restore stock ─────────────
            $return->items()->delete();
            $totalAmount = 0;

            foreach ($validated['items'] as $item) {
                $qty         = (float) $item['qty'];
                $price       = (float) $item['price'];
                $totalAmount += $qty * $price;

                SaleReturnItem::create([
                    'sale_return_id' => $return->id,
                    'product_id'     => $item['product_id'],
                    'variation_id'   => $item['variation_id'] ?? null,
                    'qty'            => $qty,
                    'price'          => $price,
                ]);

                // Restore stock for new items
                if (!empty($item['variation_id'])) {
                    $variation = ProductVariation::find($item['variation_id']);
                    if ($variation) {
                        $variation->increment('stock_quantity', $qty);
                    }
                }
            }

            // ── Step 4: Recreate journal vouchers ─────────────────
            Voucher::where('reference', $reference)->where('voucher_type', 'journal')->delete();

            $salesAccount     = $this->salesRevenueAccount();
            $inventoryAccount = $this->inventoryAccount();
            $cogsAccount      = $this->cogsAccount();

            // Entry A: Reverse revenue
            Voucher::create([
                'voucher_type' => 'journal',
                'date'         => $validated['return_date'],
                'ac_dr_sid'    => $salesAccount->id,         // DR: Sales Revenue (↓)
                'ac_cr_sid'    => $validated['account_id'],  // CR: Customer (↓)
                'amount'       => $totalAmount,
                'reference'    => $reference,
                'remarks'      => "Updated: Sale Return #{$return->id} — revenue reversal",
            ]);

            // Entry B: Restore inventory
            if ($cogsAccount) {
                Voucher::create([
                    'voucher_type' => 'journal',
                    'date'         => $validated['return_date'],
                    'ac_dr_sid'    => $inventoryAccount->id,  // DR: Inventory (↑)
                    'ac_cr_sid'    => $cogsAccount->id,       // CR: COGS (↓)
                    'amount'       => $totalAmount,
                    'reference'    => $reference,
                    'remarks'      => "Updated: Sale Return #{$return->id} — inventory restored",
                ]);
            }

            DB::commit();
            Log::info('[SR] Updated successfully', ['return_id' => $return->id]);

            return redirect()->route('sale_return.index')
                ->with('success', 'Sale return updated successfully.');

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('[SR] Update failed', [
                'return_id' => $id,
                'error'     => $e->getMessage(),
                'trace'     => $e->getTraceAsString(),
            ]);

            return back()->withInput()->with('error', 'Error updating sale return.');
        }
    }

    // ─────────────────────────────────────────────────────────────
    // SHOW  (JSON — used by modals/AJAX)
    // ─────────────────────────────────────────────────────────────
    public function show($id)
    {
        $return = SaleReturn::with('items.product', 'items.variation', 'account', 'saleInvoice')
            ->findOrFail($id);
        return response()->json($return);
    }

    // ─────────────────────────────────────────────────────────────
    // DESTROY
    //
    // FIX: also reverse stock and delete vouchers on deletion
    // ─────────────────────────────────────────────────────────────
    public function destroy($id)
    {
        DB::beginTransaction();

        try {
            $return = SaleReturn::with('items')->findOrFail($id);

            // Re-decrement stock (undo the return's stock restoration)
            foreach ($return->items as $item) {
                if ($item->variation_id) {
                    $variation = ProductVariation::find($item->variation_id);
                    if ($variation) {
                        $variation->decrement('stock_quantity', $item->qty);
                    }
                }
            }

            // Remove accounting vouchers
            Voucher::where('reference', 'SR-' . $return->id)->delete();

            $return->items()->delete();
            $return->delete();

            DB::commit();
            return redirect()->route('sale_return.index')->with('success', 'Sale return deleted.');

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('[SR] Delete failed', ['error' => $e->getMessage()]);
            return back()->with('error', 'Error deleting sale return.');
        }
    }

    // ─────────────────────────────────────────────────────────────
    // PRINT  (PDF)
    // ─────────────────────────────────────────────────────────────
    public function print($id)
    {
        $return = SaleReturn::with(['customer', 'items.product', 'items.variation'])->findOrFail($id);

        $pdf = new \TCPDF();
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetCreator('BillTrix');
        $pdf->SetAuthor('Lucky Corporation');
        $pdf->SetTitle('Sale Return #' . $return->id);
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
            <tr><td><b>Return #</b></td><td>'  . $return->id . '</td></tr>
            <tr><td><b>Date</b></td><td>'       . Carbon::parse($return->return_date)->format('d/m/Y') . '</td></tr>
            <tr><td><b>Customer</b></td><td>'   . e($return->customer->name ?? '-') . '</td></tr>
            <tr><td><b>Sale Invoice</b></td><td>' . e($return->sale_invoice_no ?? '-') . '</td></tr>
        </table>';
        $pdf->writeHTML($returnInfo, false, false, false, false, '');

        $pdf->Line(60, 52.25, 200, 52.25);

        // Title badge
        $pdf->SetXY(10, 48);
        $pdf->SetFillColor(23, 54, 93);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('helvetica', '', 12);
        $pdf->Cell(50, 8, 'Sale Return', 0, 1, 'C', 1);
        $pdf->SetTextColor(0, 0, 0);

        // Items table
        $pdf->Ln(5);
        $html = '<table border="0.3" cellpadding="4" style="text-align:center;font-size:10px;">
            <tr style="background-color:#f5f5f5;font-weight:bold;">
                <th width="8%">S.No</th>
                <th width="25%">Product</th>
                <th width="30%">Variation</th>
                <th width="10%">Qty</th>
                <th width="12%">Price</th>
                <th width="15%">Total</th>
            </tr>';

        $count       = 0;
        $totalAmount = 0;

        foreach ($return->items as $item) {
            $count++;
            $lineTotal    = $item->qty * $item->price;
            $totalAmount += $lineTotal;

            $html .= '
            <tr>
                <td align="center">' . $count . '</td>
                <td>' . e($item->product->name ?? '-') . '</td>
                <td>' . e($item->variation->sku ?? '-') . '</td>
                <td align="center">' . number_format($item->qty, 2) . '</td>
                <td align="right">'  . number_format($item->price, 2) . '</td>
                <td align="right">'  . number_format($lineTotal, 2) . '</td>
            </tr>';
        }

        $html .= '
            <tr>
                <td colspan="5" align="right"><b>Total</b></td>
                <td align="right"><b>' . number_format($totalAmount, 2) . '</b></td>
            </tr>';

        if (!empty($return->discount)) {
            $html .= '
            <tr>
                <td colspan="5" align="right">Return Discount</td>
                <td align="right">' . number_format($return->discount, 2) . '</td>
            </tr>';
            $totalAmount -= $return->discount;
        }

        $html .= '
            <tr style="background-color:#f5f5f5;">
                <td colspan="5" align="right"><b>Net Total</b></td>
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

        return $pdf->Output('sale_return_' . $return->id . '.pdf', 'I');
    }
}