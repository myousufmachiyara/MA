<?php

namespace App\Http\Controllers;

use App\Models\SaleOrder;
use App\Models\Product;
use App\Models\User;
use App\Models\MeasurementUnit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SaleOrderController extends Controller
{
    public function index(Request $request)
    {
        $query = SaleOrder::with(['customer', 'booker']);

        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }
        if ($request->filled('booker_id')) {
            $query->where('booker_id', $request->booker_id);
        }

        $orders  = $query->latest()->get();
        $bookers = User::where('user_type', 'mobile')->get(['id', 'name']);

        return view('sale_orders.index', compact('orders', 'bookers'));
    }

    public function create()
    {
        $customers = ChartOfAccounts::where('account_type', 'customer')->where('is_active', true)->orderBy('name')->get();
        $products  = Product::with('variations')->orderBy('name')->get();
        $units     = MeasurementUnit::all();

        return view('sale_orders.create', compact('customers', 'products', 'units'));
    }

    /**
     * Web-side order booking — for walk-in customers handled directly at the
     * office, not via a mobile order booker. Flows into the exact same
     * Sale Order pool as mobile-booked orders (Dispatch Trip merges both
     * identically) — this is just an alternate entry point, not a shortcut.
     */
    public function store(Request $request)
    {
        $request->validate([
            'customer_id'           => 'required|exists:chart_of_accounts,id',
            'order_date'            => 'required|date',
            'payment_terms'         => 'required|in:cash,credit',
            'remarks'               => 'nullable|string',
            'items'                 => 'required|array|min:1',
            'items.*.item_id'       => 'required|exists:products,id',
            'items.*.variation_id'  => 'nullable|exists:product_variations,id',
            'items.*.quantity'      => 'required|numeric|min:0.01',
            'items.*.unit'          => 'required|exists:measurement_units,id',
            'items.*.price'         => 'required|numeric|min:0',
        ]);

        DB::beginTransaction();

        try {
            $last    = SaleOrder::withTrashed()->lockForUpdate()->orderByDesc('id')->first();
            $orderNo = str_pad($last ? intval($last->order_no ?? 0) + 1 : 1, 6, '0', STR_PAD_LEFT);

            $order = SaleOrder::create([
                'order_no'      => $orderNo,
                'customer_id'   => $request->customer_id,
                'booker_id'     => auth()->id(), // office staff who booked it
                'order_date'    => $request->order_date,
                'status'        => 'confirmed', // office-created — no review step needed
                'payment_terms' => $request->payment_terms,
                'remarks'       => $request->remarks,
                'local_uuid'    => Str::uuid(),
                'sync_status'   => 'synced',
                'booked_at'     => now(),
                'synced_at'     => now(),
            ]);

            $totalAmount = 0;
            $totalQty    = 0;

            foreach ($request->items as $itemData) {
                $qty   = (float) $itemData['quantity'];
                $price = (float) $itemData['price'];
                $totalAmount += $qty * $price;
                $totalQty    += $qty;

                $order->items()->create([
                    'item_id'      => $itemData['item_id'],
                    'variation_id' => $itemData['variation_id'] ?? null,
                    'quantity'     => $qty,
                    'unit'         => $itemData['unit'],
                    'price'        => $price,
                ]);
            }

            $order->update(['total_amount' => $totalAmount, 'total_quantity' => $totalQty]);

            DB::commit();
            Log::info('[SaleOrder] Booked via web (walk-in)', ['id' => $order->id, 'by' => auth()->id()]);

            return redirect()->route('sale_orders.index')->with('success', "Order SO-{$orderNo} booked successfully.");

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('[SaleOrder] Web store error', ['message' => $e->getMessage()]);
            return back()->withInput()->with('error', 'Something went wrong: ' . $e->getMessage());
        }
    }

    public function edit($id)
    {
        $order    = SaleOrder::with(['items.product.variations', 'items.variation'])->findOrFail($id);
        $products = Product::with('variations')->orderBy('name')->get();
        $units    = MeasurementUnit::all();

        return view('sale_orders.edit', compact('order', 'products', 'units'));
    }

    /**
     * Manager can adjust quantities/prices before merging into a dispatch trip
     * (e.g. stock shortage means only partial qty can actually be fulfilled).
     */
    public function update(Request $request, $id)
    {
        $order = SaleOrder::with('items')->findOrFail($id);

        if (in_array($order->status, ['merged', 'invoiced'])) {
            return back()->with('error', 'This order is already merged into a dispatch trip and can no longer be edited here.');
        }

        $request->validate([
            'items'                 => 'required|array|min:1',
            'items.*.item_id'       => 'required|exists:products,id',
            'items.*.variation_id'  => 'nullable|exists:product_variations,id',
            'items.*.quantity'      => 'required|numeric|min:0.01',
            'items.*.unit'          => 'required|exists:measurement_units,id',
            'items.*.price'         => 'required|numeric|min:0',
        ]);

        $order->items()->delete();
        $totalAmount = 0;
        $totalQty    = 0;

        foreach ($request->items as $itemData) {
            $qty   = (float) $itemData['quantity'];
            $price = (float) $itemData['price'];
            $totalAmount += $qty * $price;
            $totalQty    += $qty;

            $order->items()->create([
                'item_id'      => $itemData['item_id'],
                'variation_id' => $itemData['variation_id'] ?? null,
                'quantity'     => $qty,
                'unit'         => $itemData['unit'],
                'price'        => $price,
            ]);
        }

        $order->update(['total_amount' => $totalAmount, 'total_quantity' => $totalQty]);

        Log::info('[SaleOrder] Updated by manager', ['id' => $id, 'by' => auth()->id()]);

        return back()->with('success', 'Order updated successfully.');
    }

    public function cancel($id)
    {
        $order = SaleOrder::findOrFail($id);

        if (in_array($order->status, ['merged', 'invoiced'])) {
            return back()->with('error', 'Cannot cancel — already merged into a dispatch trip.');
        }

        $order->update(['status' => 'cancelled']);
        return back()->with('success', 'Order cancelled.');
    }
}