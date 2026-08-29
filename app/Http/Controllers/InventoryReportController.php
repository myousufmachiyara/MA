<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\StockMovement;
use App\Models\Location;
use App\Models\LocationStock;
use App\Services\StockService;
use Illuminate\Http\Request;
use Carbon\Carbon;
use App\Traits\ExportsCsv;

class InventoryReportController extends Controller
{
    use ExportsCsv;
    public function inventoryReports(Request $request)
    {
        $categories = ProductCategory::orderBy('name')->get();
        $products   = Product::orderBy('name')->get(['id', 'name']);
        $locations  = Location::where('is_active', true)->orderBy('name')->get();

        $from = $request->from_date ?? Carbon::now()->startOfMonth()->toDateString();
        $to   = $request->to_date   ?? Carbon::now()->toDateString();

        $reports = [
            'stock_in_hand'    => $this->stockInHand($request),
            'stock_movement'   => $this->stockMovement($request, $from, $to),
            'item_ledger'      => $this->itemLedger($request, $from, $to),
            'stock_by_location'=> $this->stockByLocation($request),
        ];

        return view('reports.inventory_reports', compact('reports', 'categories', 'products', 'locations', 'from', 'to'));
    }

    // ── TAB 1: STOCK IN HAND ─────────────────────────────────────

    private function stockInHand(Request $request)
    {
        $query = Product::with(['variations', 'category']);

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
        }
        if ($request->filled('search')) {
            $query->where('name', 'like', "%{$request->search}%");
        }

        $products = $query->orderBy('name')->get();
        $rows     = collect();

        foreach ($products as $product) {
            if ($product->variations->count() > 0) {
                foreach ($product->variations as $v) {
                    if ($request->filled('stock_status')) {
                        if ($request->stock_status === 'zero' && $v->stock_quantity > 0) continue;
                        if ($request->stock_status === 'low' && ($v->stock_quantity <= 0 || $v->stock_quantity > 10)) continue;
                    }
                    $rows->push([
                        'item'       => $product->name,
                        'category'   => $product->category->name ?? '—',
                        'variation'  => $v->sku,
                        'quantity'   => $v->stock_quantity,
                        'cost_price' => $v->cost_price,
                        'value'      => $v->stock_quantity * $v->cost_price,
                        'link'       => route('stock_movements.show', ['itemId' => $product->id, 'variation_id' => $v->id]),
                    ]);
                }
            } else {
                $stock = StockService::currentStock($product->id, null);
                if ($request->filled('stock_status')) {
                    if ($request->stock_status === 'zero' && $stock > 0) continue;
                    if ($request->stock_status === 'low' && ($stock <= 0 || $stock > 10)) continue;
                }
                $rows->push([
                    'item'       => $product->name,
                    'category'   => $product->category->name ?? '—',
                    'variation'  => '—',
                    'quantity'   => $stock,
                    'cost_price' => $product->cost_price,
                    'value'      => $stock * $product->cost_price,
                    'link'       => route('stock_movements.show', $product->id),
                ]);
            }
        }

        return $rows;
    }

    // ── TAB 2: STOCK MOVEMENT ────────────────────────────────────

    private function stockMovement(Request $request, string $from, string $to)
    {
        $query = StockMovement::with(['product', 'variation', 'location', 'creator'])->orderByDesc('created_at');

        if ($request->filled('item_id')) {
            $query->where('item_id', $request->item_id);
        }
        if ($request->filled('direction')) {
            $query->where('direction', $request->direction);
        }
        if ($request->filled('reference_type')) {
            $query->where('reference_type', $request->reference_type);
        }
        if ($request->filled('location_id')) {
            $query->where('location_id', $request->location_id);
        }
        $query->whereDate('created_at', '>=', $from)->whereDate('created_at', '<=', $to);

        return $query->paginate(50)->withQueryString();
    }

    // ── TAB 3: ITEM LEDGER (opening/closing balance for one item) ──

    private function itemLedger(Request $request, string $from, string $to)
    {
        if (!$request->filled('ledger_item_id')) {
            return null;
        }

        $itemId      = $request->ledger_item_id;
        $variationId = $request->filled('ledger_variation_id') ? $request->ledger_variation_id : null;

        $product = Product::with('variations')->find($itemId);
        if (!$product) return null;

        // Variable product with no variation chosen yet — signal the view to show a picker
        if ($product->variations->count() > 0 && !$variationId) {
            return ['needs_variation' => true, 'product' => $product];
        }

        $baseQuery = StockMovement::where('item_id', $itemId);
        $variationId ? $baseQuery->where('variation_id', $variationId) : $baseQuery->whereNull('variation_id');

        $priorLast = (clone $baseQuery)
            ->where('created_at', '<', $from)
            ->orderByDesc('created_at')->orderByDesc('id')->first();
        $opening = $priorLast->balance_after ?? 0;

        $movements = (clone $baseQuery)
            ->with('creator', 'location')
            ->whereDate('created_at', '>=', $from)
            ->whereDate('created_at', '<=', $to)
            ->orderBy('created_at')->orderBy('id')
            ->get();

        $closing   = $movements->last()->balance_after ?? $opening;
        $variation = $variationId ? $product->variations->find($variationId) : null;

        return [
            'needs_variation' => false,
            'product'         => $product,
            'variation'       => $variation,
            'opening'         => $opening,
            'closing'         => $closing,
            'movements'       => $movements,
        ];
    }

    // ── TAB 4: STOCK BY LOCATION ─────────────────────────────────

    private function stockByLocation(Request $request)
    {
        $query = LocationStock::with(['product', 'variation', 'location'])->where('quantity', '!=', 0);

        if ($request->filled('location_id')) {
            $query->where('location_id', $request->location_id);
        }
        if ($request->filled('search')) {
            $query->whereHas('product', fn ($q) => $q->where('name', 'like', "%{$request->search}%"));
        }

        return $query->orderBy('location_id')->get();
    }
    public function exportExcel(Request $request, string $tab)
    {
        switch ($tab) {
            case 'stock_in_hand':
                $rows = $this->stockInHand($request)
                    ->map(fn ($r) => [$r['item'], $r['category'], $r['variation'], $r['quantity'], $r['cost_price'], $r['value']]);
                return $this->exportCsv(['Item', 'Category', 'Variation', 'Quantity', 'Cost Price', 'Value'], $rows->toArray(), 'stock_in_hand.csv');

            case 'stock_movement':
                $from = $request->from_date ?? \Carbon\Carbon::now()->startOfMonth()->toDateString();
                $to   = $request->to_date   ?? \Carbon\Carbon::now()->toDateString();
                $rows = $this->stockMovement($request, $from, $to)->getCollection()
                    ->map(fn ($m) => [
                        $m->created_at->format('d-M-Y h:i A'),
                        $m->product->name ?? 'N/A',
                        $m->variation->sku ?? '—',
                        $m->location->name ?? '—',
                        $m->direction === 'in' ? 'In' : 'Out',
                        $m->quantity,
                        $m->balance_after,
                        $m->reference_label,
                        $m->creator->name ?? '—',
                    ]);
                return $this->exportCsv(['Date', 'Item', 'Variation', 'Location', 'Direction', 'Qty', 'Balance', 'Source', 'By'], $rows->toArray(), 'stock_movement.csv');

            case 'item_ledger':
                $from = $request->from_date ?? \Carbon\Carbon::now()->startOfMonth()->toDateString();
                $to   = $request->to_date   ?? \Carbon\Carbon::now()->toDateString();
                $ledger = $this->itemLedger($request, $from, $to);

                if (!$ledger || ($ledger['needs_variation'] ?? false)) {
                    return $this->exportCsv(['Message'], [['Select a specific item and variation first.']], 'item_ledger.csv');
                }

                $rows = collect([['', '', 'Opening Balance', $ledger['opening'], '', '']])
                    ->concat($ledger['movements']->map(fn ($m) => [
                        $m->created_at->format('d-M-Y h:i A'),
                        $m->direction === 'in' ? 'In' : 'Out',
                        $m->quantity,
                        $m->balance_after,
                        $m->location->name ?? '—',
                        $m->reference_label,
                    ]))
                    ->push(['', '', 'Closing Balance', $ledger['closing'], '', '']);

                return $this->exportCsv(['Date', 'Direction', 'Qty', 'Balance', 'Location', 'Source'], $rows->toArray(), 'item_ledger.csv');

            case 'stock_by_location':
                $rows = $this->stockByLocation($request)
                    ->map(fn ($s) => [$s->location->name ?? 'N/A', $s->product->name ?? 'N/A', $s->variation->sku ?? '—', $s->quantity]);
                return $this->exportCsv(['Location', 'Item', 'Variation', 'Quantity'], $rows->toArray(), 'stock_by_location.csv');

            default:
                abort(404, 'Unknown report tab.');
        }
    }
}