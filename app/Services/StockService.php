<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductVariation;
use App\Models\StockMovement;
use App\Models\Location;
use App\Models\LocationStock;
use Illuminate\Support\Facades\Log;

class StockService
{
    private static ?int $defaultLocationId = null;

    /**
     * Every call site that doesn't pass $locationId lands here automatically.
     * Keeps every existing Purchase/Sale/Settlement/Return/Adjustment call
     * working unchanged while this feature is rolled out.
     */
    public static function defaultLocationId(): int
    {
        if (self::$defaultLocationId) {
            return self::$defaultLocationId;
        }

        $location = Location::where('is_default', true)->first()
            ?? Location::where('is_active', true)->orderBy('id')->first();

        if (!$location) {
            throw new \Exception('No location configured. Please create at least one warehouse/location.');
        }

        return self::$defaultLocationId = $location->id;
    }

    /**
     * @param string $direction 'in' or 'out'
     */
    public static function move(
        int $itemId,
        ?int $variationId,
        float $qty,
        string $direction,
        string $referenceType,
        int $referenceId,
        ?string $remarks = null,
        ?int $locationId = null
    ): void {
        $locationId = $locationId ?? self::defaultLocationId();

        // ── Global balance — unchanged from before ────────────────────────
        if ($variationId) {
            $variation = ProductVariation::find($variationId);
            if (!$variation) {
                Log::warning('[StockService] Variation not found', ['variation_id' => $variationId]);
                return;
            }

            $direction === 'in'
                ? $variation->increment('stock_quantity', $qty)
                : $variation->decrement('stock_quantity', $qty);

            $balanceAfter = $variation->fresh()->stock_quantity;

        } else {
            $product = Product::find($itemId);
            if (!$product) {
                Log::warning('[StockService] Product not found', ['item_id' => $itemId]);
                return;
            }

            $current      = self::currentStock($itemId, null);
            $balanceAfter = $direction === 'in' ? $current + $qty : $current - $qty;
        }

        // ── Per-location balance — new ─────────────────────────────────────
        self::adjustLocationStock($itemId, $variationId, $locationId, $qty, $direction);

        StockMovement::create([
            'item_id'        => $itemId,
            'variation_id'   => $variationId,
            'location_id'    => $locationId,
            'direction'      => $direction,
            'quantity'       => $qty,
            'balance_after'  => $balanceAfter,
            'reference_type' => $referenceType,
            'reference_id'   => $referenceId,
            'remarks'        => $remarks,
            'created_by'     => auth()->id(),
        ]);
    }

    public static function currentStock(int $itemId, ?int $variationId): float
    {
        if ($variationId) {
            return (float) (ProductVariation::find($variationId)->stock_quantity ?? 0);
        }

        $last = StockMovement::where('item_id', $itemId)
            ->whereNull('variation_id')
            ->orderByDesc('id')
            ->first();

        return $last ? (float) $last->balance_after : 0.0;
    }

    /**
     * Stock at a specific warehouse — used by Stock Transfer's availability
     * check and the Stock by Location report.
     */
    public static function stockAtLocation(int $itemId, ?int $variationId, int $locationId): float
    {
        $row = LocationStock::where('item_id', $itemId)
            ->where('location_id', $locationId)
            ->where(fn ($q) => $variationId ? $q->where('variation_id', $variationId) : $q->whereNull('variation_id'))
            ->first();

        return $row ? (float) $row->quantity : 0.0;
    }

    private static function adjustLocationStock(int $itemId, ?int $variationId, int $locationId, float $qty, string $direction): void
    {
        $row = LocationStock::where('item_id', $itemId)
            ->where('location_id', $locationId)
            ->where(fn ($q) => $variationId ? $q->where('variation_id', $variationId) : $q->whereNull('variation_id'))
            ->first();

        if (!$row) {
            $row = LocationStock::create([
                'item_id'      => $itemId,
                'variation_id' => $variationId,
                'location_id'  => $locationId,
                'quantity'     => 0,
            ]);
        }

        $direction === 'in'
            ? $row->increment('quantity', $qty)
            : $row->decrement('quantity', $qty);
    }
}