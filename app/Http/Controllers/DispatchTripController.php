<?php

namespace App\Http\Controllers;

use App\Models\DispatchTrip;
use App\Models\SaleOrder;
use App\Models\SaleInvoice;
use App\Models\ChartOfAccounts;
use App\Models\Product;
use App\Models\ProductVariation;
use App\Models\Voucher;
use App\Models\User;
use App\Services\StockService;
use App\Services\VoucherService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DispatchTripController extends Controller
{
    private function inventoryAccount(): ChartOfAccounts
    {
        return ChartOfAccounts::where('account_code', '104001')->firstOrFail();
    }

    private function salesRevenueAccount(): ChartOfAccounts
    {
        return ChartOfAccounts::where('account_code', '401001')->firstOrFail();
    }

    private function cogsAccount(): ChartOfAccounts
    {
        return ChartOfAccounts::where('account_code', '501001')->firstOrFail();
    }

    private function gstPayableAccount(): ChartOfAccounts
    {
        return ChartOfAccounts::where('account_code', '203001')->firstOrFail();
    }

    public function index(Request $request)
    {
        $query = DispatchTrip::with('deliveryManager');

        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        $trips = $query->latest()->get();

        return view('dispatch_trips.index', compact('trips'));
    }

    public function create()
    {
        $deliveryManagers = User::where('is_active', true)
            ->where(function ($q) {
                $q->where('mobile_role', 'delivery_manager')->orWhere('user_type', 'web');
            })->orderBy('name')->get();

        return view('dispatch_trips.create', compact('deliveryManagers'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'trip_date'            => 'required|date',
            'vehicle_no'           => 'required|string|max:50',
            'delivery_manager_id'  => 'required|exists:users,id',
            'remarks'              => 'nullable|string',
        ]);

        DB::beginTransaction();
        try {
            $last   = DispatchTrip::withTrashed()->lockForUpdate()->orderByDesc('id')->first();
            $tripNo = str_pad($last ? intval($last->trip_no) + 1 : 1, 6, '0', STR_PAD_LEFT);

            $trip = DispatchTrip::create([
                'trip_no'              => $tripNo,
                'trip_date'            => $request->trip_date,
                'vehicle_no'           => $request->vehicle_no,
                'delivery_manager_id'  => $request->delivery_manager_id,
                'remarks'              => $request->remarks,
                'created_by'           => auth()->id(),
                'updated_by'           => auth()->id(),
            ]);

            DB::commit();
            return redirect()->route('dispatch_trips.show', $trip->id)->with('success', 'Trip created. Now add orders to it.');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('[DispatchTrip] Store error', ['message' => $e->getMessage()]);
            return back()->withInput()->with('error', 'Something went wrong: ' . $e->getMessage());
        }
    }

    public function show($id)
    {
        $trip = DispatchTrip::with(['deliveryManager', 'orders.items.product', 'orders.customer', 'invoices.customer'])
            ->findOrFail($id);

        // Orders still available to add: confirmed, not yet in any trip
        $availableOrders = SaleOrder::with('customer', 'items.product')
            ->where('status', 'confirmed')
            ->latest()->get();

        // Stock check: aggregate required qty across all orders currently in this trip
        $requirements = [];
        foreach ($trip->orders as $order) {
            foreach ($order->items as $item) {
                $key = $item->item_id . '-' . ($item->variation_id ?? '0');
                if (!isset($requirements[$key])) {
                    $requirements[$key] = [
                        'item_id'      => $item->item_id,
                        'variation_id' => $item->variation_id,
                        'name'         => $item->product->name . ($item->variation->sku ?? '' ? ' - ' . ($item->variation->sku ?? '') : ''),
                        'required'     => 0,
                        'available'    => StockService::currentStock($item->item_id, $item->variation_id),
                    ];
                }
                $requirements[$key]['required'] += $item->quantity;
            }
        }

        return view('dispatch_trips.show', compact('trip', 'availableOrders', 'requirements'));
    }

    /**
     * Add selected confirmed orders into this trip.
     */
    public function addOrders(Request $request, $id)
    {
        $trip = DispatchTrip::findOrFail($id);

        if ($trip->status !== 'planned') {
            return back()->with('error', 'Cannot modify a trip that has already been dispatched.');
        }

        $request->validate(['order_ids' => 'required|array|min:1', 'order_ids.*' => 'exists:sale_orders,id']);

        DB::beginTransaction();
        try {
            foreach ($request->order_ids as $orderId) {
                $order = SaleOrder::findOrFail($orderId);
                if ($order->status !== 'confirmed') continue; // skip anything already claimed

                $trip->orders()->attach($orderId);
                $order->update(['status' => 'merged']);
            }

            $trip->update(['total_orders' => $trip->orders()->count()]);

            DB::commit();
            return back()->with('success', 'Orders added to trip.');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('[DispatchTrip] addOrders error', ['message' => $e->getMessage()]);
            return back()->with('error', 'Error: ' . $e->getMessage());
        }
    }

    public function removeOrder($id, $orderId)
    {
        $trip = DispatchTrip::findOrFail($id);

        if ($trip->status !== 'planned') {
            return back()->with('error', 'Cannot modify a trip that has already been dispatched.');
        }

        $trip->orders()->detach($orderId);
        SaleOrder::where('id', $orderId)->update(['status' => 'confirmed']);
        $trip->update(['total_orders' => $trip->orders()->count()]);

        return back()->with('success', 'Order removed from trip.');
    }

    /**
     * The core event: generate one Sale Invoice per order, decrement stock,
     * post accounting entries. This is where goods actually leave the warehouse.
     */
    public function dispatch(Request $request, $id)
    {
        $trip = DispatchTrip::with('orders.items.product', 'orders.customer')->findOrFail($id);

        if ($trip->status !== 'planned') {
            return back()->with('error', 'This trip has already been dispatched.');
        }

        if ($trip->orders->isEmpty()) {
            return back()->with('error', 'Add at least one order before dispatching.');
        }

        $request->validate([
            'apply_gst'  => 'nullable|boolean',
            'gst_type'   => 'nullable|required_if:apply_gst,1|in:inclusive,exclusive',
            'gst_rate'   => 'nullable|required_if:apply_gst,1|numeric|min:0|max:100',
        ]);

        $applyGst = $request->boolean('apply_gst');

        // ── Stock check: block if any item is short across the whole trip ──
        $requirements = [];
        foreach ($trip->orders as $order) {
            foreach ($order->items as $item) {
                $key = $item->item_id . '-' . ($item->variation_id ?? '0');
                $requirements[$key]['required'] = ($requirements[$key]['required'] ?? 0) + $item->quantity;
                $requirements[$key]['item_id'] = $item->item_id;
                $requirements[$key]['variation_id'] = $item->variation_id;
                $requirements[$key]['name'] = $item->product->name;
            }
        }
        foreach ($requirements as $req) {
            $available = StockService::currentStock($req['item_id'], $req['variation_id']);
            if ($req['required'] > $available) {
                return back()->with('error', "Insufficient stock for {$req['name']}: required {$req['required']}, available {$available}. Adjust order quantities or remove the order before dispatching.");
            }
        }

        DB::beginTransaction();
        try {
            $inventoryAccount = $this->inventoryAccount();
            $salesAccount     = $this->salesRevenueAccount();
            $cogsAccount      = $this->cogsAccount();
            $gstAccount       = $applyGst ? $this->gstPayableAccount() : null;

            $tripTotal = 0;

            foreach ($trip->orders as $order) {
                $customer = ChartOfAccounts::findOrFail($order->customer_id);

                $last      = SaleInvoice::withTrashed()->lockForUpdate()->orderByDesc('id')->first();
                $invoiceNo = str_pad($last ? intval($last->invoice_no) + 1 : 1, 6, '0', STR_PAD_LEFT);

                $whtApplicable = (bool) $customer->wht_applicable;
                $whtRate       = $customer->wht_rate;

                $invoice = SaleInvoice::create([
                    'invoice_no'        => $invoiceNo,
                    'customer_id'       => $order->customer_id,
                    'sale_order_id'     => $order->id,
                    'dispatch_trip_id'  => $trip->id,
                    'invoice_date'      => $trip->trip_date,
                    'payment_terms'     => $order->payment_terms,
                    'is_tax_invoice'    => $applyGst,
                    'gst_type'          => $applyGst ? $request->gst_type : null,
                    'gst_rate'          => $applyGst ? $request->gst_rate : null,
                    'wht_applicable'    => $whtApplicable,
                    'wht_rate'          => $whtRate,
                    'created_by'        => auth()->id(),
                    'updated_by'        => auth()->id(),
                ]);

                $netAmount = 0;
                $totalQty  = 0;
                $cogsTotal = 0;

                foreach ($order->items as $item) {
                    $target = $item->variation_id
                        ? ProductVariation::find($item->variation_id)
                        : Product::find($item->item_id);
                    $costPrice = $target->cost_price ?? 0;

                    $lineNet = $item->quantity * $item->price;
                    $netAmount += $lineNet;
                    $totalQty  += $item->quantity;
                    $cogsTotal += $item->quantity * $costPrice;

                    $invoice->items()->create([
                        'item_id'      => $item->item_id,
                        'variation_id' => $item->variation_id,
                        'quantity'     => $item->quantity,
                        'unit'         => $item->unit,
                        'price'        => $item->price,
                        'cost_price'   => $costPrice,
                    ]);

                    // Stock leaves the warehouse now — invoice time, not order time
                    StockService::move(
                        $item->item_id, $item->variation_id, $item->quantity,
                        'out', 'sale_invoice', $invoice->id, "Sale Invoice #{$invoiceNo}"
                    );
                }

                // ── GST calc ──────────────────────────────────────────
                $gstAmount = 0;
                if ($applyGst) {
                    $rate = (float) $request->gst_rate;
                    if ($request->gst_type === 'inclusive') {
                        $base = $netAmount / (1 + $rate / 100);
                        $gstAmount = round($netAmount - $base, 2);
                        $netAmount = round($base, 2);
                    } else {
                        $gstAmount = round($netAmount * $rate / 100, 2);
                    }
                }

                $totalAmount = $netAmount + $gstAmount;

                // ── WHT calc (informational only — not posted here) ───
                $whtAmount = $whtApplicable ? round($totalAmount * ($whtRate / 100), 2) : 0;

                $invoice->update([
                    'total_quantity' => $totalQty,
                    'net_amount'     => $netAmount,
                    'gst_amount'     => $gstAmount,
                    'total_amount'   => $totalAmount,
                    'wht_amount'     => $whtAmount,
                    'cogs_amount'    => $cogsTotal,
                ]);
                // ── Accounting entries — single combined voucher per invoice ─────────
                $lines = [
                    ['account_id' => $order->customer_id, 'debit' => $totalAmount, 'credit' => 0, 'narration' => 'Sale invoice total'],
                    ['account_id' => $salesAccount->id,   'debit' => 0, 'credit' => $netAmount, 'narration' => 'Sales revenue'],
                ];

                if ($applyGst && $gstAmount > 0) {
                    $lines[] = ['account_id' => $gstAccount->id, 'debit' => 0, 'credit' => $gstAmount, 'narration' => 'GST output tax'];
                }

                if ($cogsTotal > 0) {
                    $lines[] = ['account_id' => $cogsAccount->id, 'debit' => $cogsTotal, 'credit' => 0, 'narration' => 'Cost of goods sold'];
                    $lines[] = ['account_id' => $inventoryAccount->id, 'debit' => 0, 'credit' => $cogsTotal, 'narration' => 'Stock issued'];
                }

                VoucherService::postEntries(
                    [
                        'voucher_type'   => 'sale',
                        'voucher_date'   => $trip->trip_date,
                        'reference_type' => SaleInvoice::class,
                        'reference_id'   => $invoice->id,
                        'remarks'        => "Sale Invoice #{$invoiceNo}",
                    ],
                    $lines
                );

                $order->update(['status' => 'invoiced']);
                $tripTotal += $totalAmount;
            }

            $trip->update([
                'status'       => 'dispatched',
                'total_amount' => $tripTotal,
                'updated_by'   => auth()->id(),
            ]);

            DB::commit();
            Log::info('[DispatchTrip] Dispatched', ['trip_id' => $trip->id, 'by' => auth()->id()]);

            return redirect()->route('dispatch_trips.show', $trip->id)
                ->with('success', 'Trip dispatched. Invoices generated and stock updated.');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('[DispatchTrip] Dispatch error', ['message' => $e->getMessage(), 'line' => $e->getLine()]);
            return back()->with('error', 'Dispatch failed: ' . $e->getMessage());
        }
    }

    public function cancel($id)
    {
        $trip = DispatchTrip::findOrFail($id);

        if ($trip->status !== 'planned') {
            return back()->with('error', 'Only a planned (not yet dispatched) trip can be cancelled.');
        }

        foreach ($trip->orders as $order) {
            $order->update(['status' => 'confirmed']);
        }
        $trip->orders()->detach();
        $trip->update(['status' => 'cancelled']);

        return back()->with('success', 'Trip cancelled, orders released back to the pool.');
    }
}