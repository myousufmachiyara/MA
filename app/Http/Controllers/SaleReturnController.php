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
use App\Traits\HasPdfCompanyHeader;

class SaleReturnController extends Controller
{
    use HasPdfCompanyHeader;
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

        $pdf = new \TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        $pdf->SetCreator('BillTrix');
        $pdf->SetTitle('SR-' . $return->return_no);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(15, 15, 15);
        $pdf->SetAutoPageBreak(true, 20);
        $pdf->AddPage();

        $this->addCompanyHeader($pdf, 'SALE RETURN');

        $pdf->SetFont('helvetica', '', 10);
        $pdf->Cell(0, 5, 'Return #: SR-' . $return->return_no, 0, 1, 'L');
        $pdf->Cell(0, 5, 'Date: ' . \Carbon\Carbon::parse($return->return_date)->format('d-M-Y'), 0, 1, 'L');
        $pdf->Cell(0, 5, 'Against Invoice: SI-' . ($return->sale_invoice_no ?? '—'), 0, 1, 'L');
        $pdf->Cell(0, 5, 'Customer: ' . ($return->customer->name ?? 'N/A'), 0, 1, 'L');
        $pdf->Ln(4);

        $html = '<table border="1" cellpadding="5" style="font-size:10px;">
            <thead>
                <tr style="background-color:#f2f2f2;font-weight:bold;text-align:center;">
                    <th width="5%">#</th><th width="25%">Item</th><th width="25%">Variation</th>
                    <th width="15%">Qty</th><th width="15%">Price</th><th width="15%">Value</th>
                </tr>
            </thead><tbody>';

        $total = 0;
        foreach ($return->items as $i => $item) {
            $total += $item->line_value;
            $html .= '<tr>
                <td width="5%" style="text-align:center;">' . ($i + 1) . '</td>
                <td width="25%">' . e($item->product->name ?? '-') . '</td>
                <td width="25%" style="text-align:center;">' . e($item->variation->sku ?? '-') . '</td>
                <td width="15%" style="text-align:center;">' . number_format($item->quantity, 2) . '</td>
                <td width="15%"style="text-align:right;">' . number_format($item->price, 2) . '</td>
                <td width="15%" style="text-align:right;">' . number_format($item->line_value, 2) . '</td>
            </tr>';
        }

        $html .= '<tr style="font-weight:bold;background-color:#fafafa;">
                <td colspan="5" style="text-align:right;">Total</td>
                <td style="text-align:right;">' . number_format($total, 2) . '</td>
            </tr></tbody></table>';

        $pdf->writeHTML($html, true, false, false, false, '');

        return $pdf->Output('SR_' . $return->return_no . '.pdf', 'I');
    }
}