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
     * properly linked to the real invoice record (used by
     * SaleInvoice::returns()/returned_value for accurate reporting),
     * not just carrying a loose string.
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

            // Resolve the real invoice ID from the entered invoice number,
            // if one was given. If nothing matches, this stays null and the
            // return is still saved — just without a linked invoice.
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

                if (!empty($item['variation_id'])) {
                    $variation = ProductVariation::find($item['variation_id']);
                    $variation?->increment('stock_quantity', $qty);
                }
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
     * UPDATE — reverses old stock, replaces items, restores new stock,
     * re-resolves sale_invoice_id in case the invoice number was changed,
     * reposts the voucher via postOrUpdateEntries().
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

            foreach ($return->items as $oldItem) {
                if ($oldItem->variation_id) {
                    $variation = ProductVariation::find($oldItem->variation_id);
                    $variation?->decrement('stock_quantity', $oldItem->qty);
                }
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

                if (!empty($item['variation_id'])) {
                    $variation = ProductVariation::find($item['variation_id']);
                    $variation?->increment('stock_quantity', $qty);
                }
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

    public function destroy($id)
    {
        DB::beginTransaction();

        try {
            $return = SaleReturn::with('items')->findOrFail($id);

            foreach ($return->items as $item) {
                if ($item->variation_id) {
                    $variation = ProductVariation::find($item->variation_id);
                    $variation?->decrement('stock_quantity', $item->qty);
                }
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