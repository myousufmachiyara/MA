<?php

namespace App\Http\Controllers;

use App\Models\StockTransfer;
use App\Models\Location;
use App\Models\Product;
use App\Services\StockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class StockTransferController extends Controller
{
    public function index(Request $request)
    {
        $query = StockTransfer::with(['fromLocation', 'toLocation', 'creator']);

        if ($request->filled('location_id')) {
            $query->where(fn ($q) => $q->where('from_location_id', $request->location_id)
                ->orWhere('to_location_id', $request->location_id));
        }

        $transfers = $query->latest()->get();
        $locations = Location::where('is_active', true)->orderBy('name')->get();

        return view('stock_transfers.index', compact('transfers', 'locations'));
    }

    public function create()
    {
        $locations = Location::where('is_active', true)->orderBy('name')->get();
        $products  = Product::with('variations')->orderBy('name')->get();

        return view('stock_transfers.create', compact('locations', 'products'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'transfer_date'         => 'required|date',
            'from_location_id'      => 'required|exists:locations,id',
            'to_location_id'        => 'required|different:from_location_id|exists:locations,id',
            'remarks'               => 'nullable|string',
            'items'                 => 'required|array|min:1',
            'items.*.item_id'       => 'required|exists:products,id',
            'items.*.variation_id'  => 'nullable|exists:product_variations,id',
            'items.*.quantity'      => 'required|numeric|min:0.01',
        ]);

        DB::beginTransaction();

        try {
            foreach ($request->items as $itemData) {
                $available = StockService::stockAtLocation($itemData['item_id'], $itemData['variation_id'] ?? null, $request->from_location_id);
                if ((float) $itemData['quantity'] > $available) {
                    $product = Product::find($itemData['item_id']);
                    throw new \Exception("Insufficient stock for {$product->name} at source location: available {$available}, requested {$itemData['quantity']}.");
                }
            }

            $last       = StockTransfer::withTrashed()->lockForUpdate()->orderByDesc('id')->first();
            $transferNo = str_pad($last ? intval($last->transfer_no) + 1 : 1, 6, '0', STR_PAD_LEFT);

            $transfer = StockTransfer::create([
                'transfer_no'      => $transferNo,
                'transfer_date'    => $request->transfer_date,
                'from_location_id' => $request->from_location_id,
                'to_location_id'   => $request->to_location_id,
                'remarks'          => $request->remarks,
                'created_by'       => auth()->id(),
            ]);

            $toLocationName   = Location::find($request->to_location_id)->name ?? '';
            $fromLocationName = Location::find($request->from_location_id)->name ?? '';

            foreach ($request->items as $itemData) {
                $qty = (float) $itemData['quantity'];

                $transfer->items()->create([
                    'item_id'      => $itemData['item_id'],
                    'variation_id' => $itemData['variation_id'] ?? null,
                    'quantity'     => $qty,
                ]);

                StockService::move(
                    $itemData['item_id'], $itemData['variation_id'] ?? null, $qty,
                    'out', 'stock_transfer', $transfer->id,
                    "Transfer #{$transferNo} to {$toLocationName}",
                    $request->from_location_id
                );

                StockService::move(
                    $itemData['item_id'], $itemData['variation_id'] ?? null, $qty,
                    'in', 'stock_transfer', $transfer->id,
                    "Transfer #{$transferNo} from {$fromLocationName}",
                    $request->to_location_id
                );
            }

            DB::commit();
            Log::info('[StockTransfer] Created', ['id' => $transfer->id]);

            return redirect()->route('stock_transfer.index')->with('success', 'Stock transfer completed successfully.');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('[StockTransfer] Store error', ['message' => $e->getMessage()]);
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    public function show($id)
    {
        $transfer = StockTransfer::with(['items.product', 'items.variation', 'fromLocation', 'toLocation', 'creator'])->findOrFail($id);
        return view('stock_transfers.show', compact('transfer'));
    }

    /**
     * Transfers move physical stock immediately — no edit, only a fresh
     * reverse transfer, to keep an accurate audit trail.
     */
    public function edit($id)
    {
        return back()->with('error', 'Stock transfers cannot be edited. Create a reverse transfer instead.');
    }

    public function destroy($id)
    {
        return back()->with('error', 'Stock transfers cannot be deleted. Create a reverse transfer to correct it.');
    }
}