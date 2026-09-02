<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DispatchTrip;
use App\Models\Settlement;
use App\Services\StockService;
use App\Services\VoucherService;
use App\Services\SystemAccountService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DispatchTripController extends Controller
{
    private function ensureIsDeliveryManager(Request $request, DispatchTrip $trip)
    {
        if ((int) $trip->delivery_manager_id !== (int) $request->user()->id) {
            abort(403, 'This trip is not assigned to you.');
        }
    }

    public function index(Request $request)
    {
        $trips = DispatchTrip::where('delivery_manager_id', $request->user()->id)
            ->whereIn('status', ['dispatched', 'settled'])
            ->orderByDesc('trip_date')
            ->get(['id', 'trip_no', 'trip_date', 'vehicle_no', 'status', 'total_orders', 'total_amount']);

        return response()->json(['success' => true, 'data' => $trips]);
    }

    public function show(Request $request, $id)
    {
        $trip = DispatchTrip::with(['invoices.items.product', 'invoices.items.variation', 'invoices.customer'])
            ->findOrFail($id);

        $this->ensureIsDeliveryManager($request, $trip);

        return response()->json(['success' => true, 'data' => $trip]);
    }

    /**
     * Delivery manager submits actual delivered quantity per item (not rate)
     * plus cash collected per invoice. Returned qty is computed server-side
     * as (invoiced qty − delivered qty). Posts the exact same accounting
     * entries as the web Settlement flow — this is not a lighter-weight
     * duplicate, it's the same business event from a different entry point.
     */
    public function settle(Request $request, $id)
    {
        $trip = DispatchTrip::with('invoices.items')->findOrFail($id);
        $this->ensureIsDeliveryManager($request, $trip);

        if ($trip->status !== 'dispatched') {
            return response()->json(['success' => false, 'message' => 'This trip is not ready for settlement.'], 422);
        }

        $request->validate([
            'settlement_date'     => 'required|date',
            'total_cash_received' => 'required|numeric|min:0',
            'cash'                => 'required|array',
            'cash.*'              => 'nullable|numeric|min:0',
            'delivered'           => 'nullable|array', // item_id => delivered_qty
            'delivered.*'         => 'nullable|numeric|min:0',
            'remarks'             => 'nullable|string',
        ]);

        $cashInputs      = $request->input('cash', []);
        $deliveredInputs = $request->input('delivered', []);

        $sumCash = array_sum(array_map('floatval', $cashInputs));
        if (abs($sumCash - (float) $request->total_cash_received) > 0.01) {
            return response()->json([
                'success' => false,
                'message' => 'Cash allocated per invoice (PKR ' . number_format($sumCash, 2) . ') does not match Total Cash (PKR ' . number_format($request->total_cash_received, 2) . ').',
            ], 422);
        }

        DB::beginTransaction();
        try {
            $inventoryAccount = SystemAccountService::inventory();
            $salesAccount     = SystemAccountService::salesRevenue();
            $cogsAccount      = SystemAccountService::cogs();
            $gstAccount       = SystemAccountService::gstPayable();
            $whtAccount       = SystemAccountService::whtReceivable();
            $clearingAccount  = \App\Models\ChartOfAccounts::getOrCreateDeliveryClearingAccount($request->user());

            $last         = Settlement::lockForUpdate()->orderByDesc('id')->first();
            $settlementNo = str_pad($last ? intval($last->settlement_no) + 1 : 1, 6, '0', STR_PAD_LEFT);

            $settlement = Settlement::create([
                'settlement_no'       => $settlementNo,
                'dispatch_trip_id'    => $trip->id,
                'settlement_date'     => $request->settlement_date,
                'total_cash_received' => $request->total_cash_received,
                'remarks'             => $request->remarks,
                'created_by'          => $request->user()->id,
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
                    $delivered = array_key_exists($item->id, $deliveredInputs)
                        ? (float) $deliveredInputs[$item->id]
                        : $item->quantity; // default: fully delivered if not specified

                    $returnedQty = max(0, $item->quantity - $delivered);
                    if ($returnedQty <= 0) continue;

                    $lineNet  = $returnedQty * $item->price;
                    $lineCost = $returnedQty * $item->cost_price;
                    $returnedValueNet += $lineNet;
                    $returnedCost     += $lineCost;
                    $itemReturns[] = ['item' => $item, 'qty' => $returnedQty, 'lineNet' => $lineNet, 'lineCost' => $lineCost];

                    StockService::move(
                        $item->item_id, $item->variation_id, $returnedQty,
                        'in', 'sale_return', $invoice->id, "Return — Sale Invoice #{$invoice->invoice_no} (mobile settlement)"
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
                        'sale_invoice_item_id' => $r['item']->id,
                        'item_id'              => $r['item']->item_id,
                        'variation_id'         => $r['item']->variation_id,
                        'quantity'             => $r['qty'],
                        'price'                => $r['item']->price,
                        'cost_price'           => $r['item']->cost_price,
                        'line_value'           => $r['lineNet'],
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
                            'remarks'        => "Settlement #{$settlementNo} (mobile) — Invoice #{$invoice->invoice_no}",
                        ],
                        $lines
                    );
                }

                $invoice->update(['paid_amount' => $invoice->paid_amount + $whtAmount + $returnedValueGross + $cashAllocated]);

                $grandReturnedValue += $returnedValueGross;
                $grandWht           += $whtAmount;
            }

            $settlement->update(['total_returned_value' => $grandReturnedValue, 'total_wht_amount' => $grandWht]);
            $trip->update(['status' => 'settled']);

            DB::commit();
            Log::info('[Mobile Settlement] Created', ['id' => $settlement->id, 'trip_id' => $trip->id, 'by' => $request->user()->id]);

            return response()->json(['success' => true, 'message' => 'Trip settled successfully.', 'settlement_id' => $settlement->id]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('[Mobile Settlement] Error', ['message' => $e->getMessage(), 'line' => $e->getLine()]);
            return response()->json(['success' => false, 'message' => 'Settlement failed: ' . $e->getMessage()], 500);
        }
    }
}