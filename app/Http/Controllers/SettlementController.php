<?php

namespace App\Http\Controllers;

use App\Models\Settlement;
use App\Models\DispatchTrip;
use App\Models\ChartOfAccounts;
use App\Models\Voucher;
use App\Services\StockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
        $settlements = Settlement::with('dispatchTrip.deliveryManager')->latest()->get();
        return view('settlements.index', compact('settlements'));
    }

    public function create($tripId)
    {
        $trip = DispatchTrip::with(['invoices.items.product', 'invoices.items.variation', 'invoices.customer', 'deliveryManager'])
            ->findOrFail($tripId);

        if ($trip->status !== 'dispatched') {
            return back()->with('error', 'This trip is not ready for settlement (must be dispatched first, and not already settled).');
        }

        return view('settlements.create', compact('trip'));
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
            'cash.*'                => 'nullable|numeric|min:0',
            'returns'               => 'nullable|array',
            'returns.*'              => 'nullable|numeric|min:0',
            'remarks'               => 'nullable|string',
        ]);

        $cashInputs    = $request->input('cash', []);
        $returnInputs  = $request->input('returns', []);

        // Reconciliation check — cash entered per invoice must equal the declared total
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
                'settlement_no'  => $settlementNo,
                'dispatch_trip_id' => $trip->id,
                'settlement_date'  => $request->settlement_date,
                'total_cash_received' => $request->total_cash_received,
                'remarks'          => $request->remarks,
                'created_by'       => auth()->id(),
            ]);

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

                // ── Accounting entries ──────────────────────────────
                if ($returnedValueNet > 0) {
                    Voucher::create([
                        'date' => $request->settlement_date, 'voucher_type' => 'journal',
                        'ac_dr_sid' => $salesAccount->id, 'ac_cr_sid' => $invoice->customer_id,
                        'amount' => $returnedValueNet, 'reference' => "SR-{$invoice->id}-{$settlement->id}",
                        'remarks' => "Return against Sale Invoice #{$invoice->invoice_no}",
                    ]);
                    if ($returnedCost > 0) {
                        Voucher::create([
                            'date' => $request->settlement_date, 'voucher_type' => 'journal',
                            'ac_dr_sid' => $inventoryAccount->id, 'ac_cr_sid' => $cogsAccount->id,
                            'amount' => $returnedCost, 'reference' => "SR-COGS-{$invoice->id}-{$settlement->id}",
                            'remarks' => "Return COGS reversal — Invoice #{$invoice->invoice_no}",
                        ]);
                    }
                }

                if ($gstReversal > 0) {
                    Voucher::create([
                        'date' => $request->settlement_date, 'voucher_type' => 'journal',
                        'ac_dr_sid' => $gstAccount->id, 'ac_cr_sid' => $invoice->customer_id,
                        'amount' => $gstReversal, 'reference' => "SR-GST-{$invoice->id}-{$settlement->id}",
                        'remarks' => "GST reversal on return — Invoice #{$invoice->invoice_no}",
                    ]);
                }

                if ($whtAmount > 0) {
                    Voucher::create([
                        'date' => $request->settlement_date, 'voucher_type' => 'journal',
                        'ac_dr_sid' => $whtAccount->id, 'ac_cr_sid' => $invoice->customer_id,
                        'amount' => $whtAmount, 'reference' => "WHT-{$invoice->id}-{$settlement->id}",
                        'remarks' => "WHT withheld — Invoice #{$invoice->invoice_no}",
                    ]);
                }

                if ($cashAllocated > 0) {
                    Voucher::create([
                        'date' => $request->settlement_date, 'voucher_type' => 'journal',
                        'ac_dr_sid' => $clearingAccount->id, 'ac_cr_sid' => $invoice->customer_id,
                        'amount' => $cashAllocated, 'reference' => "SET-{$invoice->id}-{$settlement->id}",
                        'remarks' => "Cash collected — Invoice #{$invoice->invoice_no}",
                    ]);
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

    public function show($id)
    {
        $settlement = Settlement::with(['dispatchTrip.deliveryManager', 'allocations.invoice.customer', 'allocations.returnItems.product'])
            ->findOrFail($id);

        return view('settlements.show', compact('settlement'));
    }

    /**
     * Delivery manager physically hands the cash to the office cashier.
     */
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

            Voucher::create([
                'date' => now()->toDateString(), 'voucher_type' => 'journal',
                'ac_dr_sid' => $cashAccount->id, 'ac_cr_sid' => $clearingAccount->id,
                'amount' => $settlement->total_cash_received,
                'reference' => "CLR-{$settlement->id}",
                'remarks' => "Cash cleared to office — Settlement #{$settlement->settlement_no}",
            ]);

            $settlement->update(['cleared_to_office' => true, 'cleared_at' => now(), 'cleared_by' => auth()->id()]);

            DB::commit();
            return back()->with('success', 'Cash cleared to office successfully.');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('[Settlement] Clear error', ['message' => $e->getMessage()]);
            return back()->with('error', 'Error: ' . $e->getMessage());
        }
    }
}