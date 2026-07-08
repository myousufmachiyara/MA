<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Http\Request;

class StockMovementController extends Controller
{
    /**
     * Filterable ledger across all items — the general "Stock In/Out" view.
     */
    public function index(Request $request)
    {
        $query = StockMovement::with(['product', 'variation', 'creator'])->orderByDesc('created_at');

        if ($request->filled('item_id')) {
            $query->where('item_id', $request->item_id);
        }
        if ($request->filled('reference_type')) {
            $query->where('reference_type', $request->reference_type);
        }
        if ($request->filled('direction')) {
            $query->where('direction', $request->direction);
        }
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        $movements = $query->paginate(50)->withQueryString();
        $products  = Product::orderBy('name')->get(['id', 'name']);

        return view('stock_movements.index', compact('movements', 'products'));
    }

    /**
     * Per-item drill-down with opening/closing balance — same pattern as
     * the Item Ledger report, kept here too since not everyone who needs
     * to check "why did this item's stock change" wants to go via Reports.
     */
    public function show(Request $request, $itemId)
    {
        $product     = Product::with('variations')->findOrFail($itemId);
        $variationId = $request->get('variation_id');
        $dateFrom    = $request->get('date_from');
        $dateTo      = $request->get('date_to');

        if ($product->variations->count() > 0 && !$variationId) {
            return view('stock_movements.pick_variation', compact('product'));
        }

        $baseQuery = StockMovement::where('item_id', $itemId);
        $variationId ? $baseQuery->where('variation_id', $variationId) : $baseQuery->whereNull('variation_id');

        $opening = 0;
        if ($dateFrom) {
            $priorLast = (clone $baseQuery)
                ->where('created_at', '<', $dateFrom)
                ->orderByDesc('created_at')->orderByDesc('id')->first();
            $opening = $priorLast->balance_after ?? 0;
        }

        $movements = (clone $baseQuery)
            ->with('creator')
            ->when($dateFrom, fn ($q) => $q->whereDate('created_at', '>=', $dateFrom))
            ->when($dateTo, fn ($q) => $q->whereDate('created_at', '<=', $dateTo))
            ->orderBy('created_at')->orderBy('id')
            ->get();

        $closing   = $movements->last()->balance_after ?? $opening;
        $variation = $variationId ? $product->variations->find($variationId) : null;

        return view('stock_movements.show', compact(
            'product', 'variation', 'movements', 'opening', 'closing', 'dateFrom', 'dateTo'
        ));
    }

    // Placeholder for later: transfer() — source location → destination location,
    // two StockService::move() calls sharing one reference_id, once Locations exists.
}