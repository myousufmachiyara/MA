<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SaleOrder;
use App\Models\ChartOfAccounts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SaleOrderController extends Controller
{
    /**
     * Booker submits one or more locally-created orders when back online.
     * Each order carries its own local_uuid — duplicates from retried
     * syncs are silently skipped, never rejected as an error.
     */
    public function sync(Request $request)
    {
        $request->validate([
            'orders'                        => 'required|array|min:1',
            'orders.*.local_uuid'           => 'required|uuid',
            'orders.*.customer_id'          => 'required|exists:chart_of_accounts,id',
            'orders.*.order_date'           => 'required|date',
            'orders.*.booked_at'            => 'required|date',
            'orders.*.payment_terms'        => 'nullable|in:cash,credit',
            'orders.*.remarks'              => 'nullable|string',
            'orders.*.items'                => 'required|array|min:1',
            'orders.*.items.*.item_id'      => 'required|exists:products,id',
            'orders.*.items.*.variation_id' => 'nullable|exists:product_variations,id',
            'orders.*.items.*.quantity'     => 'required|numeric|min:0.01',
            'orders.*.items.*.unit'         => 'required|exists:measurement_units,id',
            'orders.*.items.*.price'        => 'required|numeric|min:0',
        ]);

        $booker  = $request->user();
        $results = [];

        foreach ($request->orders as $orderData) {
            $existing = SaleOrder::where('local_uuid', $orderData['local_uuid'])->first();
            if ($existing) {
                $results[] = ['local_uuid' => $orderData['local_uuid'], 'status' => 'already_synced', 'order_id' => $existing->id];
                continue;
            }

            DB::beginTransaction();
            try {
                $last    = SaleOrder::withTrashed()->lockForUpdate()->orderByDesc('id')->first();
                $orderNo = str_pad($last ? intval($last->order_no ?? 0) + 1 : 1, 6, '0', STR_PAD_LEFT);

                $order = SaleOrder::create([
                    'order_no'      => $orderNo,
                    'customer_id'   => $orderData['customer_id'],
                    'booker_id'     => $booker->id,
                    'order_date'    => $orderData['order_date'],
                    'status'        => 'confirmed',
                    'payment_terms' => $orderData['payment_terms'] ?? 'cash',
                    'remarks'       => $orderData['remarks'] ?? null,
                    'local_uuid'    => $orderData['local_uuid'],
                    'sync_status'   => 'synced',
                    'booked_at'     => $orderData['booked_at'],
                    'synced_at'     => now(),
                ]);

                $totalAmount = 0;
                $totalQty    = 0;

                foreach ($orderData['items'] as $itemData) {
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

                $booker->recordActivity('order_synced', "Order #{$orderNo} synced", $request, [
                    'order_id' => $order->id, 'total_amount' => $totalAmount,
                ]);

                DB::commit();
                $results[] = ['local_uuid' => $orderData['local_uuid'], 'status' => 'synced', 'order_id' => $order->id, 'order_no' => $orderNo];

            } catch (\Exception $e) {
                DB::rollBack();
                Log::error('[SaleOrder Sync] Error', ['message' => $e->getMessage(), 'local_uuid' => $orderData['local_uuid']]);
                $results[] = ['local_uuid' => $orderData['local_uuid'], 'status' => 'failed', 'error' => $e->getMessage()];
            }
        }

        return response()->json(['success' => true, 'results' => $results]);
    }

    public function myOrders(Request $request)
    {
        $orders = SaleOrder::with('items.product', 'customer')
            ->where('booker_id', $request->user()->id)
            ->latest()
            ->paginate(30);

        return response()->json(['success' => true, 'data' => $orders]);
    }

    public function customers(Request $request)
    {
        $customers = ChartOfAccounts::where('account_type', 'customer')
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'address', 'contact_no', 'customer_type']);

        return response()->json(['success' => true, 'data' => $customers]);
    }

    public function storeCustomer(Request $request)
    {
        $request->validate([
            'name'    => 'required|string|max:255',
            'phone'   => 'nullable|string|max:50',
            'address' => 'nullable|string|max:250',
        ]);

        $customer = DB::transaction(function () use ($request) {
            $subHeadId = ChartOfAccounts::defaultCustomerSubHeadId();
            $subHead   = \App\Models\SubHeadOfAccounts::findOrFail($subHeadId);

            $prefix    = $subHead->hoa_id . str_pad($subHead->id, 2, '0', STR_PAD_LEFT);
            $lastCode  = ChartOfAccounts::withTrashed()->where('account_code', 'like', $prefix . '%')->max('account_code');
            $nextNum   = $lastCode ? (intval(substr($lastCode, strlen($prefix))) + 1) : 1;

            return ChartOfAccounts::create([
                'shoa_id'      => $subHeadId,
                'account_code' => $prefix . str_pad($nextNum, 3, '0', STR_PAD_LEFT),
                'name'         => $request->name,
                'account_type' => 'customer',
                'contact_no'   => $request->phone,
                'address'      => $request->address,
                'receivables'  => 0,
                'payables'     => 0,
                'credit_limit' => 0,
                'opening_date' => now(),
                'is_active'    => true,
                'is_reviewed'  => false, // ← office notification flag
                'created_by'   => auth()->id(),
                'updated_by'   => auth()->id(),
            ]);
        });

        $request->user()->recordActivity('customer_added', "Added new customer: {$customer->name}", $request, [
            'customer_id' => $customer->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Customer added successfully.',
            'data' => [
                'id'            => $customer->id,
                'name'          => $customer->name,
                'address'       => $customer->address,
                'contact_no'    => $customer->contact_no,
                'customer_type' => $customer->customer_type,
            ],
        ]);
    }

    public function update(Request $request, $id)
    {
        $order = SaleOrder::with('items')
            ->where('booker_id', $request->user()->id)
            ->findOrFail($id);

        if (!in_array($order->status, ['draft', 'confirmed'])) {
            return response()->json([
                'success' => false,
                'message' => 'This order can no longer be edited — it has already been merged into a dispatch trip.',
            ], 422);
        }

        $request->validate([
            'customer_id'           => 'required|exists:chart_of_accounts,id',
            'payment_terms'         => 'required|in:cash,credit',
            'remarks'               => 'nullable|string',
            'items'                 => 'required|array|min:1',
            'items.*.item_id'       => 'required|exists:products,id',
            'items.*.variation_id'  => 'nullable|exists:product_variations,id',
            'items.*.quantity'      => 'required|numeric|min:0.01',
            'items.*.unit'          => 'required|exists:measurement_units,id',
            'items.*.price'         => 'required|numeric|min:0',
        ]);

        DB::transaction(function () use ($order, $request) {
            $order->update([
                'customer_id'   => $request->customer_id,
                'payment_terms' => $request->payment_terms,
                'remarks'       => $request->remarks,
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
        });

        $request->user()->recordActivity('order_edited', "Edited order #{$order->order_no}", $request);

        return response()->json(['success' => true, 'message' => 'Order updated successfully.']);
    }
}