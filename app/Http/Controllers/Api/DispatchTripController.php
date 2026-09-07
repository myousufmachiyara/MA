<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DispatchTrip;
use App\Models\SaleInvoiceItem;
use App\Models\TripAdhocSale;
use App\Models\TripAdhocSaleItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
     * Delivery manager records actual delivered qty per item while out on
     * the route. No accounting or stock impact happens here — this is
     * purely informational, saved for the office to use when they run
     * the real Settlement on web (which computes returns as
     * invoiced_qty − delivered_quantity).
     */
    public function updateDelivered(Request $request, $id)
    {
        $trip = DispatchTrip::with('invoices.items')->findOrFail($id);
        $this->ensureIsDeliveryManager($request, $trip);

        if ($trip->status !== 'dispatched') {
            return response()->json(['success' => false, 'message' => 'This trip is no longer active for delivery updates.'], 422);
        }

        $request->validate([
            'delivered'   => 'required|array',
            'delivered.*' => 'nullable|numeric|min:0',
        ]);

        $validItemIds = $trip->invoices->flatMap(fn ($inv) => $inv->items->pluck('id'))->toArray();

        DB::beginTransaction();
        try {
            foreach ($request->delivered as $itemId => $qty) {
                if (!in_array((int) $itemId, $validItemIds)) continue; // ignore anything not on this trip

                $item = SaleInvoiceItem::find($itemId);
                if (!$item) continue;

                $delivered = min((float) $qty, (float) $item->quantity); // can't exceed what was invoiced
                $item->update(['delivered_quantity' => $delivered]);
            }

            DB::commit();
            return response()->json(['success' => true, 'message' => 'Delivered quantities saved.']);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'Error: ' . $e->getMessage()], 500);
        }
    }


    /**
     * Customers already on this trip (from its invoices) — for the "add to
     * existing customer" path in the on-trip sale flow.
     */
    public function tripCustomers(Request $request, $id)
    {
        $trip = DispatchTrip::with('invoices.customer')->findOrFail($id);
        $this->ensureIsDeliveryManager($request, $trip);

        $customers = $trip->invoices->map(fn ($inv) => [
            'customer_id'      => $inv->customer_id,
            'name'             => $inv->customer->name ?? 'N/A',
            'sale_invoice_id'  => $inv->id,
            'invoice_no'       => $inv->invoice_no,
        ])->unique('customer_id')->values();

        return response()->json(['success' => true, 'data' => $customers]);
    }

    /**
     * Record an on-trip sale — either extra items for a customer already on
     * this trip, or a brand-new customer entirely. NOT a SaleOrder — goods are
     * already delivered, so this deliberately skips the normal
     * order→dispatch pipeline. Office converts this into (or adds it onto)
     * a real invoice at settlement time.
     */
    public function storeAdhocSale(Request $request, $id)
    {
        $trip = DispatchTrip::findOrFail($id);
        $this->ensureIsDeliveryManager($request, $trip);

        if ($trip->status !== 'dispatched') {
            return response()->json(['success' => false, 'message' => 'This trip is no longer active for delivery.'], 422);
        }

        $request->validate([
            'customer_id'              => 'required|exists:chart_of_accounts,id',
            'existing_sale_invoice_id' => 'nullable|exists:sale_invoices,id',
            'payment_terms'            => 'required|in:cash,credit',
            'remarks'                  => 'nullable|string',
            'items'                    => 'required|array|min:1',
            'items.*.product_id'       => 'required|exists:products,id',
            'items.*.variation_id'     => 'nullable|exists:product_variations,id',
            'items.*.quantity'         => 'required|numeric|min:0.01',
            'items.*.price'            => 'required|numeric|min:0',
        ]);

        DB::beginTransaction();
        try {
            $sale = TripAdhocSale::create([
                'dispatch_trip_id'         => $trip->id,
                'customer_id'              => $request->customer_id,
                'existing_sale_invoice_id' => $request->existing_sale_invoice_id,
                'payment_terms'            => $request->payment_terms,
                'remarks'                  => $request->remarks,
                'status'                   => 'pending',
                'created_by'               => $request->user()->id,
            ]);

            foreach ($request->items as $item) {
                TripAdhocSaleItem::create([
                    'trip_adhoc_sale_id' => $sale->id,
                    'product_id'         => $item['product_id'],
                    'variation_id'       => $item['variation_id'] ?? null,
                    'quantity'           => $item['quantity'],
                    'price'              => $item['price'],
                ]);
            }

            DB::commit();
            return response()->json(['success' => true, 'message' => 'On-trip sale recorded. Office will finalize at settlement.']);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'Error: ' . $e->getMessage()], 500);
        }
    }
}