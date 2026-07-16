<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductVariation;

class CostingService
{
    /**
     * Recompute weighted average cost after stock comes IN (purchase or a
     * stock-adjustment increase). Works for both simple products (variationId
     * null) and variable products (variationId set) — same as StockService's
     * own branching, so cost and quantity always stay tracked at the same level.
     *
     * $qtyBefore = quantity on hand BEFORE this batch — capture via
     * StockService::currentStock() before calling StockService::move().
     */
    public static function applyIncoming(int $itemId, ?int $variationId, float $qtyBefore, float $qtyIn, float $priceIn): void
    {
        if ($qtyIn <= 0) return;

        $target = $variationId ? ProductVariation::find($variationId) : Product::find($itemId);
        if (!$target) return;

        $avgBefore = (float) ($target->cost_price ?? 0);
        $totalQty  = $qtyBefore + $qtyIn;

        $newAvg = $totalQty > 0
            ? (($qtyBefore * $avgBefore) + ($qtyIn * $priceIn)) / $totalQty
            : $priceIn;

        $target->update(['cost_price' => round($newAvg, 4)]);
    }
}