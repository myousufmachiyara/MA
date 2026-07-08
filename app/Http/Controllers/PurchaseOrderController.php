<?php

namespace App\Http\Controllers;

use App\Models\PurchaseOrder;
use App\Models\Product;
use App\Models\MeasurementUnit;
use App\Models\ChartOfAccounts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PurchaseOrderController extends Controller
{
    public function index(Request $request)
    {
        $user  = auth()->user();
        $query = PurchaseOrder::with('vendor');

        if ($request->has('view_deleted')) {
            $query->onlyTrashed();
        }

        if (!$user->hasRole('superadmin')) {
            $query->where('created_by', $user->id);
        }

        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        $orders = $query->latest()->get();

        return view('purchase_orders.index', compact('orders'));
    }

    public function create()
    {
        $products = Product::with('variations')->orderBy('name')->get();
        $vendors  = ChartOfAccounts::where('account_type', 'vendor')->orderBy('name')->get();
        $units    = MeasurementUnit::all();

        return view('purchase_orders.create', compact('products', 'vendors', 'units'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'order_date'            => 'required|date',
            'vendor_id'             => 'required|exists:chart_of_accounts,id',
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
            // Lock to avoid duplicate order numbers under concurrent saves
            $last    = PurchaseOrder::withTrashed()->lockForUpdate()->orderByDesc('id')->first();
            $orderNo = str_pad($last ? intval($last->order_no) + 1 : 1, 6, '0', STR_PAD_LEFT);

            $order = PurchaseOrder::create([
                'order_no'   => $orderNo,
                'vendor_id'  => $request->vendor_id,
                'order_date' => $request->order_date,
                'remarks'    => $request->remarks,
                'created_by' => auth()->id(),
                'updated_by' => auth()->id(),
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
            Log::info('[PO] Created', ['id' => $order->id, 'order_no' => $orderNo]);

            return redirect()->route('purchase_orders.index')->with('success', 'Purchase Order created successfully.');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('[PO] Store error', ['message' => $e->getMessage()]);
            return back()->withInput()->with('error', 'Something went wrong: ' . $e->getMessage());
        }
    }

    public function edit($id)
    {
        $order    = PurchaseOrder::with(['items.product.variations', 'items.variation'])->findOrFail($id);
        $vendors  = ChartOfAccounts::where('account_type', 'vendor')->orderBy('name')->get();
        $products = Product::with('variations')->orderBy('name')->get();
        $units    = MeasurementUnit::all();

        return view('purchase_orders.edit', compact('order', 'vendors', 'products', 'units'));
    }

    public function update(Request $request, $id)
    {
        $order = PurchaseOrder::with('items')->findOrFail($id);

        if ($order->status !== 'pending') {
            return back()->with('error', 'Only pending orders (nothing received yet) can be edited.');
        }

        $request->validate([
            'order_date'            => 'required|date',
            'vendor_id'             => 'required|exists:chart_of_accounts,id',
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
            $order->update([
                'vendor_id'  => $request->vendor_id,
                'order_date' => $request->order_date,
                'remarks'    => $request->remarks,
                'updated_by' => auth()->id(),
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

            DB::commit();
            return redirect()->route('purchase_orders.index')->with('success', 'Purchase Order updated successfully.');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('[PO] Update error', ['message' => $e->getMessage()]);
            return back()->withInput()->with('error', 'Error: ' . $e->getMessage());
        }
    }

    public function destroy($id)
    {
        $order = PurchaseOrder::findOrFail($id);

        if ($order->status !== 'pending') {
            return back()->with('error', 'Cannot delete an order that already has received items. Cancel it instead.');
        }

        $order->delete();
        return back()->with('success', 'Purchase Order deleted successfully.');
    }

    /**
     * Cancel an order that still has unreceived quantity — keeps history intact.
     */
    public function cancel($id)
    {
        $order = PurchaseOrder::findOrFail($id);

        if ($order->status === 'completed') {
            return back()->with('error', 'Cannot cancel a fully completed order.');
        }

        $order->update(['status' => 'cancelled', 'updated_by' => auth()->id()]);
        return back()->with('success', 'Purchase Order cancelled.');
    }

    /**
     * Returns remaining (unreceived) items for this PO — used by the
     * Purchase Invoice "Create from PO" screen via AJAX.
     */
    public function getItems($id)
    {
        $order = PurchaseOrder::with(['items.product', 'items.variation', 'items.product.variations'])->findOrFail($id);

        if (in_array($order->status, ['completed', 'cancelled'])) {
            return response()->json(['success' => false, 'message' => 'This order has no remaining items to invoice.']);
        }

        $items = $order->items->map(function ($item) {
            return [
                'po_item_id'     => $item->id,
                'item_id'        => $item->item_id,
                'item_name'      => $item->product->name ?? 'N/A',
                'variation_id'   => $item->variation_id,
                'variation_sku'  => $item->variation->sku ?? null,
                'unit'           => $item->unit,
                'price'          => $item->price,
                'remaining_qty'  => $item->remaining_qty,
            ];
        })->filter(fn ($i) => $i['remaining_qty'] > 0)->values();

        return response()->json([
            'success'    => true,
            'vendor_id'  => $order->vendor_id,
            'items'      => $items,
        ]);
    }
}