<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DispatchTrip;
use App\Models\SaleInvoiceItem;
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
}