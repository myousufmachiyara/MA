<?php

namespace App\Http\Controllers;

use App\Models\Settlement;
use App\Models\DispatchTrip;
use App\Models\ChartOfAccounts;
use App\Models\Voucher;
use App\Models\TripAdhocSale;
use App\Services\StockService;
use App\Services\VoucherService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class SettlementController extends Controller
{
    private function inventoryAccount(): ChartOfAccounts { return ChartOfAccounts::where('account_code', '104001')->firstOrFail(); }
    private function salesRevenueAccount(): ChartOfAccounts { return ChartOfAccounts::where('account_code', '401001')->firstOrFail(); }
    private function cogsAccount(): ChartOfAccounts { return ChartOfAccounts::where('account_code', '501001')->firstOrFail(); }
    private function gstPayableAccount(): ChartOfAccounts { return ChartOfAccounts::where('account_code', '203001')->firstOrFail(); }
    private function whtReceivableAccount(): ChartOfAccounts { return ChartOfAccounts::where('account_code', '105001')->firstOrFail(); }
    private function cashAccount(): ChartOfAccounts { return ChartOfAccounts::where('account_code', '101001')->firstOrFail(); }

    public function index()
    {
        $settlements = Settlement::with(['dispatchTrip.deliveryManager', 'allocations.invoice.vouchers'])
            ->latest('settlement_date')
            ->get();
        return view('settlements.index', compact('settlements'));
    }

    public function create($tripId)
    {
        $trip = DispatchTrip::with(['invoices.items.product', 'invoices.items.variation', 'invoices.customer', 'deliveryManager'])
            ->findOrFail($tripId);

        if ($trip->status !== 'dispatched') {
            return back()->with('error', 'This trip is not ready for settlement (must be dispatched first, and not already settled).');
        }

        // NEW: on-trip sales the delivery manager recorded (leftover stock
        // sold to a customer already on the trip, or a brand-new customer).
        // Office reviews these here and folds them into real invoicing.
        $adhocSales = TripAdhocSale::with(['customer', 'items.product', 'items.variation', 'existingInvoice'])
            ->where('dispatch_trip_id', $trip->id)
            ->where('status', 'pending')
            ->get();

        return view('settlements.create', compact('trip', 'adhocSales'));
    }

    public function store(Request $request, $tripId)
    {
        $trip = DispatchTrip::with('invoices.items')->findOrFail($tripId);

        if ($trip->status !== 'dispatched') {
            return back()->with('error', 'This trip is not ready for settlement.');
        }

        $request->validate([
            'settlement_date'      => 'required|date',
            'total_cash_received'  => 'required|numeric|min:0',
            'cash'                 => 'required|array',
            'cash.*'               => 'nullable|numeric|min:0',
            'returns'              => 'nullable|array',
            'returns.*'            => 'nullable|numeric|min:0',
            'remarks'              => 'nullable|string',
            // NEW: office decides how to process each pending adhoc sale.
            // 'existing' → add its items onto that customer's invoice on this trip.
            // 'new' → create a standalone new invoice for that customer.
            // 'skip' → leave it pending, don't process this time.
            'adhoc_action'         => 'nullable|array',
            'adhoc_action.*'       => 'nullable|in:existing,new,skip',
        ]);

        $cashInputs   = $request->input('cash', []);
        $returnInputs = $request->input('returns', []);

        $sumCash = array_sum(array_map('floatval', $cashInputs));
        if (abs($sumCash - (float) $request->total_cash_received) > 0.01) {
            return back()->withInput()->with('error',
                'Cash allocated per invoice (PKR ' . number_format($sumCash, 2) . ') does not match Total Cash Received (PKR ' .
                number_format($request->total_cash_received, 2) . '). Please reconcile before saving.');
        }

        DB::beginTransaction();
        try {
            $inventoryAccount = $this->inventoryAccount();
            $salesAccount     = $this->salesRevenueAccount();
            $cogsAccount      = $this->cogsAccount();
            $gstAccount       = $this->gstPayableAccount();
            $whtAccount       = $this->whtReceivableAccount();
            $clearingAccount  = ChartOfAccounts::getOrCreateDeliveryClearingAccount($trip->deliveryManager);

            $last          = Settlement::lockForUpdate()->orderByDesc('id')->first();
            $settlementNo  = str_pad($last ? intval($last->settlement_no) + 1 : 1, 6, '0', STR_PAD_LEFT);

            $settlement = Settlement::create([
                'settlement_no'       => $settlementNo,
                'dispatch_trip_id'    => $trip->id,
                'settlement_date'     => $request->settlement_date,
                'total_cash_received' => $request->total_cash_received,
                'remarks'             => $request->remarks,
                'created_by'          => auth()->id(),
            ]);

            // ── NEW: process on-trip adhoc sales BEFORE the normal invoice
            // loop, so if any get added onto an existing invoice, the
            // updated item list/total is what the rest of settlement uses.
            $adhocSales = TripAdhocSale::with('items')->where('dispatch_trip_id', $trip->id)->where('status', 'pending')->get();

            foreach ($adhocSales as $adhoc) {
                $action = $request->input('adhoc_action.' . $adhoc->id, 'skip');
                if ($action === 'skip') continue;

                $this->processAdhocSale($adhoc, $action, $request->settlement_date, $settlementNo);
            }

            // Refresh trip's invoices — adhoc processing may have added a new invoice
            $trip->load('invoices.items');

            $grandReturnedValue = 0;
            $grandWht           = 0;

            foreach ($trip->invoices as $invoice) {
                $cashAllocated = (float) ($cashInputs[$invoice->id] ?? 0);
                $whtAmount     = $invoice->wht_applicable ? $invoice->wht_amount : 0;

                $returnedValueNet = 0;
                $returnedCost     = 0;
                $itemReturns      = [];

                foreach ($invoice->items as $item) {
                    $returnedQty = (float) ($returnInputs[$item->id] ?? 0);
                    if ($returnedQty <= 0) continue;

                    if ($returnedQty > $item->quantity) {
                        throw new \Exception("Returned quantity exceeds invoiced quantity on Invoice #{$invoice->invoice_no}.");
                    }

                    $lineNet  = $returnedQty * $item->price;
                    $lineCost = $returnedQty * $item->cost_price;
                    $returnedValueNet += $lineNet;
                    $returnedCost     += $lineCost;
                    $itemReturns[] = ['item' => $item, 'qty' => $returnedQty, 'lineNet' => $lineNet, 'lineCost' => $lineCost];

                    StockService::move(
                        $item->item_id, $item->variation_id, $returnedQty,
                        'in', 'sale_return', $invoice->id, "Return — Sale Invoice #{$invoice->invoice_no}"
                    );
                }

                $gstReversal = ($invoice->is_tax_invoice && $returnedValueNet > 0)
                    ? round($returnedValueNet * $invoice->gst_rate / 100, 2) : 0;
                $returnedValueGross = $returnedValueNet + $gstReversal;

                $allocation = $settlement->allocations()->create([
                    'sale_invoice_id' => $invoice->id,
                    'wht_amount'      => $whtAmount,
                    'returned_value'  => $returnedValueGross,
                    'cash_allocated'  => $cashAllocated,
                    'balance_after'   => round($invoice->total_amount - $invoice->paid_amount - $whtAmount - $returnedValueGross - $cashAllocated, 2),
                ]);

                foreach ($itemReturns as $r) {
                    $allocation->returnItems()->create([
                        'item_id'      => $r['item']->item_id,
                        'variation_id' => $r['item']->variation_id,
                        'quantity'     => $r['qty'],
                        'price'        => $r['item']->price,
                        'cost_price'   => $r['item']->cost_price,
                        'line_value'   => $r['lineNet'],
                    ]);
                }

                $lines = [];

                if ($returnedValueNet > 0) {
                    $lines[] = ['account_id' => $salesAccount->id, 'debit' => $returnedValueNet, 'credit' => 0, 'narration' => 'Return — sales reversal'];
                    $lines[] = ['account_id' => $invoice->customer_id, 'debit' => 0, 'credit' => $returnedValueNet, 'narration' => 'Return credited to customer'];
                }

                if ($returnedCost > 0) {
                    $lines[] = ['account_id' => $inventoryAccount->id, 'debit' => $returnedCost, 'credit' => 0, 'narration' => 'Return — stock back in'];
                    $lines[] = ['account_id' => $cogsAccount->id, 'debit' => 0, 'credit' => $returnedCost, 'narration' => 'COGS reversal'];
                }

                if ($gstReversal > 0) {
                    $lines[] = ['account_id' => $gstAccount->id, 'debit' => $gstReversal, 'credit' => 0, 'narration' => 'GST reversal on return'];
                    $lines[] = ['account_id' => $invoice->customer_id, 'debit' => 0, 'credit' => $gstReversal, 'narration' => 'GST reversal credited'];
                }

                if ($whtAmount > 0) {
                    $lines[] = ['account_id' => $whtAccount->id, 'debit' => $whtAmount, 'credit' => 0, 'narration' => 'WHT withheld'];
                    $lines[] = ['account_id' => $invoice->customer_id, 'debit' => 0, 'credit' => $whtAmount, 'narration' => 'WHT settled against invoice'];
                }

                if ($cashAllocated > 0) {
                    $lines[] = ['account_id' => $clearingAccount->id, 'debit' => $cashAllocated, 'credit' => 0, 'narration' => 'Cash collected by delivery manager'];
                    $lines[] = ['account_id' => $invoice->customer_id, 'debit' => 0, 'credit' => $cashAllocated, 'narration' => 'Cash settled against invoice'];
                }

                if (!empty($lines)) {
                    VoucherService::postEntries(
                        [
                            'voucher_type'   => 'receipt',
                            'voucher_date'   => $request->settlement_date,
                            'reference_type' => \App\Models\SaleInvoice::class,
                            'reference_id'   => $invoice->id,
                            'remarks'        => "Settlement #{$settlementNo} — Invoice #{$invoice->invoice_no}",
                        ],
                        $lines
                    );
                }

                $invoice->update([
                    'paid_amount' => $invoice->paid_amount + $whtAmount + $returnedValueGross + $cashAllocated,
                ]);

                $grandReturnedValue += $returnedValueGross;
                $grandWht           += $whtAmount;
            }

            $settlement->update(['total_returned_value' => $grandReturnedValue, 'total_wht_amount' => $grandWht]);
            $trip->update(['status' => 'settled', 'updated_by' => auth()->id()]);

            DB::commit();
            Log::info('[Settlement] Created', ['id' => $settlement->id, 'trip_id' => $trip->id]);

            return redirect()->route('settlements.show', $settlement->id)->with('success', 'Trip settled successfully.');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('[Settlement] Store error', ['message' => $e->getMessage(), 'line' => $e->getLine()]);
            return back()->withInput()->with('error', 'Settlement failed: ' . $e->getMessage());
        }
    }

    /**
     * Processes one pending on-trip adhoc sale:
     *  - 'existing': appends its items onto the customer's existing invoice
     *    on this trip (quantity/price added as new line items, invoice
     *    totals recalculated). No new stock movement — the stock was
     *    already accounted for as delivered on the original invoice; this
     *    just reassigns "leftover, undelivered" stock to a real sale
     *    instead of it going back as a return.
     *  - 'new': creates a brand-new Sale Invoice for that customer,
     *    dated today, tied to this trip, fully paid via cash at settlement
     *    (handled in the normal per-invoice loop above, since it becomes
     *    part of $trip->invoices once created).
     */
    private function processAdhocSale(TripAdhocSale $adhoc, string $action, string $settlementDate, string $settlementNo): void
    {
        if ($action === 'existing' && $adhoc->existing_sale_invoice_id) {
            $invoice = \App\Models\SaleInvoice::findOrFail($adhoc->existing_sale_invoice_id);
            $addedTotal = 0;

            foreach ($adhoc->items as $item) {
                $lineTotal = $item->quantity * $item->price;
                $addedTotal += $lineTotal;

                $invoice->items()->create([
                    'item_id'      => $item->product_id,
                    'variation_id' => $item->variation_id,
                    'quantity'     => $item->quantity,
                    'price'        => $item->price,
                    'cost_price'   => \App\Models\Product::find($item->product_id)->cost_price ?? 0,
                ]);
            }

            $invoice->increment('net_amount', $addedTotal);
            $invoice->increment('total_amount', $addedTotal);

            $adhoc->update(['status' => 'processed', 'processed_sale_invoice_id' => $invoice->id]);

        } elseif ($action === 'new') {
            $lastInvoice = \App\Models\SaleInvoice::lockForUpdate()->orderByDesc('id')->first();
            $invoiceNo   = str_pad($lastInvoice ? intval($lastInvoice->invoice_no) + 1 : 1, 6, '0', STR_PAD_LEFT);

            $netAmount = $adhoc->items->sum(fn ($i) => $i->quantity * $i->price);

            $invoice = \App\Models\SaleInvoice::create([
                'invoice_no'       => $invoiceNo,
                'customer_id'      => $adhoc->customer_id,
                'dispatch_trip_id' => $adhoc->dispatch_trip_id,
                'invoice_date'     => $settlementDate,
                'payment_terms'    => $adhoc->payment_terms,
                'net_amount'       => $netAmount,
                'total_amount'     => $netAmount,
                'paid_amount'      => 0,
                'is_tax_invoice'   => false,
                'remarks'          => 'On-trip sale' . ($adhoc->remarks ? " — {$adhoc->remarks}" : ''),
                'created_by'       => auth()->id(),
            ]);

            foreach ($adhoc->items as $item) {
                $invoice->items()->create([
                    'item_id'      => $item->product_id,
                    'variation_id' => $item->variation_id,
                    'quantity'     => $item->quantity,
                    'price'        => $item->price,
                    'cost_price'   => \App\Models\Product::find($item->product_id)->cost_price ?? 0,
                ]);
            }

            $adhoc->update(['status' => 'processed', 'processed_sale_invoice_id' => $invoice->id]);
        }
    }

    public function show($id)
    {
        $settlement = Settlement::with(['dispatchTrip.deliveryManager', 'allocations.invoice.customer', 'allocations.returnItems.product'])
            ->findOrFail($id);

        return view('settlements.show', compact('settlement'));
    }

    public function clearToOffice($id)
    {
        $settlement = Settlement::with('dispatchTrip.deliveryManager')->findOrFail($id);

        if ($settlement->cleared_to_office) {
            return back()->with('error', 'This settlement has already been cleared to the office.');
        }

        DB::beginTransaction();
        try {
            $cashAccount     = $this->cashAccount();
            $clearingAccount = ChartOfAccounts::getOrCreateDeliveryClearingAccount($settlement->dispatchTrip->deliveryManager);

            VoucherService::postEntries(
                [
                    'voucher_type'   => 'receipt',
                    'voucher_date'   => now()->toDateString(),
                    'reference_type' => Settlement::class,
                    'reference_id'   => $settlement->id,
                    'remarks'        => "Cash cleared to office — Settlement #{$settlement->settlement_no}",
                ],
                [
                    ['account_id' => $cashAccount->id, 'debit' => $settlement->total_cash_received, 'credit' => 0, 'narration' => 'Cash received from delivery manager'],
                    ['account_id' => $clearingAccount->id, 'debit' => 0, 'credit' => $settlement->total_cash_received, 'narration' => 'Clearing account settled'],
                ]
            );

            $settlement->update(['cleared_to_office' => true, 'cleared_at' => now(), 'cleared_by' => auth()->id()]);

            DB::commit();
            return back()->with('success', 'Cash cleared to office successfully.');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('[Settlement] Clear error', ['message' => $e->getMessage()]);
            return back()->with('error', 'Error: ' . $e->getMessage());
        }
    }

    public function report(Request $request)
    {
        $from = $request->from_date ?? Carbon::now()->startOfMonth()->toDateString();
        $to   = $request->to_date   ?? Carbon::now()->toDateString();

        $settlements = Settlement::with(['dispatchTrip.deliveryManager', 'allocations'])
            ->whereBetween('settlement_date', [$from, $to])
            ->latest('settlement_date')
            ->get();

        return view('settlements.report', compact('settlements', 'from', 'to'));
    }
}