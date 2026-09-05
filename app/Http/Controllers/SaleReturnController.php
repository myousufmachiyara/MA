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
use App\Services\VoucherService;
use App\Services\StockService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use App\Traits\HasPdfCompanyHeader;

class SaleReturnController extends Controller
{
    use HasPdfCompanyHeader;

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

    public function create()
    {
        return view('sale_returns.create', [
            'products'  => Product::orderBy('name')->get(),
            'customers' => ChartOfAccounts::where('account_type', 'customer')->orderBy('name')->get(),
            'invoices'  => SaleInvoice::latest()->get(),
        ]);
    }

    /**
     * STORE
     *
     * Posts ONE combined voucher via VoucherService. Also resolves
     * sale_invoice_id from the entered sale_invoice_no, so returns are
     * properly linked to the real invoice record. Stock is restored via
     * StockService::move() — this is what creates the stock_movements
     * audit trail row so returns show up in Stock Movement / Item Ledger,
     * not just the raw stock_quantity total. Works correctly for both
     * variation and simple (no-variation) products.
     */
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

            $invoice = !empty($validated['sale_invoice_no'])
                ? SaleInvoice::where('invoice_no', $validated['sale_invoice_no'])->first()
                : null;

            $return = SaleReturn::create([
                'account_id'      => $validated['customer_id'],
                'return_date'     => $validated['return_date'],
                'sale_invoice_id' => $invoice->id ?? null,
                'sale_invoice_no' => $validated['sale_invoice_no'] ?? null,
                'remarks'         => $validated['remarks'] ?? null,
                'created_by'      => Auth::id(),
            ]);

            $totalAmount = 0;

            foreach ($validated['items'] as $item) {
                $qty   = (float) $item['qty'];
                $price = (float) $item['price'];
                $totalAmount += $qty * $price;

                SaleReturnItem::create([
                    'sale_return_id' => $return->id,
                    'product_id'     => $item['product_id'],
                    'variation_id'   => $item['variation_id'] ?? null,
                    'qty'            => $qty,
                    'price'          => $price,
                ]);

                // FIX: was $variation->increment('stock_quantity', $qty) —
                // that silently skipped the stock_movements audit trail AND
                // skipped simple (no-variation) products entirely.
                StockService::move(
                    $item['product_id'], $item['variation_id'] ?? null, $qty,
                    'in', 'sale_return', $return->id, "Sale Return #{$return->id}"
                );
            }

            $this->postReturnVoucher($return, $totalAmount, $validated['customer_id'], $validated['return_date']);

            DB::commit();
            Log::info('[SR] Stored successfully', ['return_id' => $return->id]);

            return redirect()->route('sale_return.index')->with('success', 'Sale return created successfully.');

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('[SR] Store failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return back()->withInput()->with('error', 'Error saving sale return: ' . $e->getMessage());
        }
    }

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

    /**
     * UPDATE — reverses old stock via StockService (creates a matching
     * reversal audit trail row), replaces items, restores new stock the
     * same way, reposts the voucher via postOrUpdateEntries().
     */
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
            $return = SaleReturn::with('items')->findOrFail($id);

            // FIX: reverse old stock through StockService, not direct decrement
            foreach ($return->items as $oldItem) {
                StockService::move(
                    $oldItem->product_id, $oldItem->variation_id, $oldItem->qty,
                    'out', 'sale_return', $return->id, "Reversal — editing Sale Return #{$return->id}"
                );
            }

            $invoice = !empty($validated['sale_invoice_no'])
                ? SaleInvoice::where('invoice_no', $validated['sale_invoice_no'])->first()
                : null;

            $return->update([
                'account_id'      => $validated['account_id'],
                'return_date'     => $validated['return_date'],
                'sale_invoice_id' => $invoice->id ?? null,
                'sale_invoice_no' => $validated['sale_invoice_no'] ?? null,
                'remarks'         => $validated['remarks'] ?? null,
            ]);

            $return->items()->delete();
            $totalAmount = 0;

            foreach ($validated['items'] as $item) {
                $qty   = (float) $item['qty'];
                $price = (float) $item['price'];
                $totalAmount += $qty * $price;

                SaleReturnItem::create([
                    'sale_return_id' => $return->id,
                    'product_id'     => $item['product_id'],
                    'variation_id'   => $item['variation_id'] ?? null,
                    'qty'            => $qty,
                    'price'          => $price,
                ]);

                // FIX: restore new stock through StockService, not direct increment
                StockService::move(
                    $item['product_id'], $item['variation_id'] ?? null, $qty,
                    'in', 'sale_return', $return->id, "Updated Sale Return #{$return->id}"
                );
            }

            $this->postReturnVoucher($return, $totalAmount, $validated['account_id'], $validated['return_date'], true);

            DB::commit();
            Log::info('[SR] Updated successfully', ['return_id' => $return->id]);

            return redirect()->route('sale_return.index')->with('success', 'Sale return updated successfully.');

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('[SR] Update failed', ['return_id' => $id, 'error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return back()->withInput()->with('error', 'Error updating sale return: ' . $e->getMessage());
        }
    }

    /**
     * Shared voucher posting for store()/update().
     * One combined voucher: Dr Sales Revenue + Dr Inventory / Cr Customer + Cr COGS.
     */
    private function postReturnVoucher(SaleReturn $return, float $totalAmount, int $customerId, string $returnDate, bool $isUpdate = false): void
    {
        if ($totalAmount <= 0) return;

        $salesAccount     = $this->salesRevenueAccount();
        $inventoryAccount = $this->inventoryAccount();
        $cogsAccount      = $this->cogsAccount();

        $lines = [
            ['account_id' => $salesAccount->id, 'debit' => $totalAmount, 'credit' => 0, 'narration' => 'Sales revenue reversal'],
            ['account_id' => $customerId,       'debit' => 0, 'credit' => $totalAmount, 'narration' => 'Return credited to customer'],
        ];

        if ($cogsAccount) {
            $lines[] = ['account_id' => $inventoryAccount->id, 'debit' => $totalAmount, 'credit' => 0, 'narration' => 'Stock returned to inventory'];
            $lines[] = ['account_id' => $cogsAccount->id,      'debit' => 0, 'credit' => $totalAmount, 'narration' => 'COGS reversal'];
        } else {
            Log::warning('[SR] COGS account (501001) not found — inventory reversal skipped.');
        }

        $remarks = ($isUpdate ? 'Updated: ' : '') . "Sale Return #{$return->id}";

        if ($isUpdate) {
            VoucherService::postOrUpdateEntries(
                SaleReturn::class,
                $return->id,
                'journal',
                ['voucher_date' => $returnDate, 'remarks' => $remarks],
                $lines
            );
        } else {
            VoucherService::postEntries(
                [
                    'voucher_type'   => 'journal',
                    'voucher_date'   => $returnDate,
                    'reference_type' => SaleReturn::class,
                    'reference_id'   => $return->id,
                    'remarks'        => $remarks,
                ],
                $lines
            );
        }
    }

    public function show($id)
    {
        $return = SaleReturn::with('items.product', 'items.variation', 'customer', 'vouchers')
            ->findOrFail($id);
        return view('sale_returns.show', compact('return'));
    }

    /**
     * DESTROY — also reverses stock through StockService, not direct
     * decrement, so the deletion leaves a matching audit trail row too.
     */
    public function destroy($id)
    {
        DB::beginTransaction();

        try {
            $return = SaleReturn::with('items')->findOrFail($id);

            foreach ($return->items as $item) {
                StockService::move(
                    $item->product_id, $item->variation_id, $item->qty,
                    'out', 'sale_return', $return->id, "Deleted Sale Return #{$return->id}"
                );
            }

            Voucher::where('reference_type', SaleReturn::class)->where('reference_id', $return->id)->delete();

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

    public function print($id)
    {
        $return = SaleReturn::with(['customer', 'items.product', 'items.variation'])->findOrFail($id);

        $pdf = new \TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        $pdf->SetCreator('BillTrix');
        $pdf->SetTitle('SR-' . $return->id);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(15, 15, 15);
        $pdf->SetAutoPageBreak(true, 20);
        $pdf->AddPage();

        $this->addCompanyHeader($pdf, 'SALE RETURN');

        $pdf->SetFont('helvetica', '', 10);
        $pdf->Cell(0, 5, 'Return #: SR-' . $return->id, 0, 1, 'L');
        $pdf->Cell(0, 5, 'Date: ' . Carbon::parse($return->return_date)->format('d-M-Y'), 0, 1, 'L');
        $pdf->Cell(0, 5, 'Against Invoice: SI-' . ($return->sale_invoice_no ?? '—'), 0, 1, 'L');
        $pdf->Cell(0, 5, 'Customer: ' . ($return->customer->name ?? 'N/A'), 0, 1, 'L');
        $pdf->Ln(4);

        $html = '<table border="1" cellpadding="5" style="font-size:10px;">
            <thead>
                <tr style="background-color:#f2f2f2;font-weight:bold;text-align:center;">
                    <th width="5%">#</th><th width="35%">Item</th><th width="20%">Variation</th>
                    <th width="15%">Qty</th><th width="12%">Price</th><th width="13%">Value</th>
                </tr>
            </thead><tbody>';

        $total = 0;
        foreach ($return->items as $i => $item) {
            $lineValue = $item->qty * $item->price;
            $total += $lineValue;
            $html .= '<tr>
                <td style="text-align:center;">' . ($i + 1) . '</td>
                <td>' . e($item->product->name ?? '-') . '</td>
                <td style="text-align:center;">' . e($item->variation->sku ?? '-') . '</td>
                <td style="text-align:center;">' . number_format($item->qty, 2) . '</td>
                <td style="text-align:right;">' . number_format($item->price, 2) . '</td>
                <td style="text-align:right;">' . number_format($lineValue, 2) . '</td>
            </tr>';
        }

        $html .= '<tr style="font-weight:bold;background-color:#fafafa;">
                <td colspan="5" style="text-align:right;">Total</td>
                <td style="text-align:right;">' . number_format($total, 2) . '</td>
            </tr></tbody></table>';

        $pdf->writeHTML($html, true, false, false, false, '');

        return $pdf->Output('SR_' . $return->id . '.pdf', 'I');
    }
}