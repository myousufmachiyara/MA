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