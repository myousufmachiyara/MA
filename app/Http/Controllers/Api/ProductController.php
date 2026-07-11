<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\StockService;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $query = Product::with(['category', 'variations'])->where('is_active', true);

        if ($request->filled('search')) {
            $query->where('name', 'like', "%{$request->search}%");
        }
        if ($request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        $products = $query->orderBy('name')->get()->map(function ($product) {
            if ($product->variations->count() > 0) {
                $variations = $product->variations->map(function ($v) use ($product) {
                    $stock = (float) $v->stock_quantity;
                    return [
                        'id'           => $v->id,
                        'sku'          => $v->sku,
                        'price'        => (float) ($product->selling_price ?? 0),
                        'stock_status' => $stock <= 0 ? 'out' : ($stock <= 10 ? 'low' : 'in_stock'),
                    ];
                });

                return [
                    'id'             => $product->id,
                    'name'           => $product->name,
                    'category'       => $product->category->name ?? null,
                    'unit'           => $product->measurement_unit,
                    'has_variations' => true,
                    'variations'     => $variations,
                ];
            }

            $stock = StockService::currentStock($product->id, null);

            return [
                'id'             => $product->id,
                'name'           => $product->name,
                'category'       => $product->category->name ?? null,
                'unit'           => $product->measurement_unit,
                'has_variations' => false,
                'price'          => (float) ($product->selling_price ?? 0),
                'stock_status'   => $stock <= 0 ? 'out' : ($stock <= 10 ? 'low' : 'in_stock'),
            ];
        });

        return response()->json(['success' => true, 'data' => $products]);
    }
}