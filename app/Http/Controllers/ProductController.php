<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Product;
use App\Models\Attribute;
use App\Models\ProductCategory;
use App\Models\ProductSubcategory;
use App\Models\MeasurementUnit;
use App\Models\ProductVariation;
use App\Services\StockService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class ProductController extends Controller
{
    public function index()
    {
        $products = Product::with('category', 'subcategory', 'variations')->get();
        return view('products.index', compact('products'));
    }

    public function create()
    {
        $categories = ProductCategory::all();
        $attributes = Attribute::with('values')->get();
        $units = MeasurementUnit::all();
        return view('products.create', compact('categories', 'attributes', 'units'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255|unique:products,name',
            'category_id' => 'required|exists:product_categories,id',
            'subcategory_id' => 'nullable|exists:product_subcategories,id',
            'sku' => 'required|string|unique:products,sku',
            'description' => 'nullable|string',
            'measurement_unit' => 'required|exists:measurement_units,id',
            'selling_price' => 'nullable|numeric|min:0',
            'opening_stock' => 'nullable|numeric|min:0',
            'is_active' => 'boolean',
        ]);

        DB::beginTransaction();

        try {
            // opening_stock deliberately excluded — seeds the ledger below, not stored on the row
            $productData = $request->only([
                'name', 'category_id', 'subcategory_id', 'sku', 'description',
                'measurement_unit', 'selling_price', 'is_active'
            ]);

            $product = Product::create($productData);
            Log::info('[Product Store] Product created', ['product_id' => $product->id, 'data' => $productData]);

            $hasVariations = $request->has('variations') && count($request->variations) > 0;

            if ($hasVariations) {
                foreach ($request->variations as $variationData) {
                    $initialStock = (float) ($variationData['stock_quantity'] ?? 0);

                    $variation = $product->variations()->create([
                        'sku' => $variationData['sku'] ?? null,
                        'stock_quantity' => 0, // seeded via ledger below
                        'selling_price' => $variationData['selling_price'] ?? 0,
                    ]);
                    Log::info('[Product Store] Variation created', ['variation_id' => $variation->id, 'product_id' => $product->id, 'data' => $variationData]);

                    if ($initialStock > 0) {
                        StockService::move(
                            $product->id, $variation->id, $initialStock, 'in',
                            'opening_stock', $variation->id, 'Opening stock at product creation'
                        );
                    }

                    if (!empty($variationData['attributes'])) {
                        $variation->attributeValues()->sync($variationData['attributes']);
                        Log::info('[Product Store] Variation attributes synced', [
                            'variation_id' => $variation->id,
                            'attributes' => $variationData['attributes']
                        ]);
                    }
                }
            } elseif ($request->filled('opening_stock') && (float) $request->opening_stock > 0) {
                StockService::move(
                    $product->id, null, (float) $request->opening_stock, 'in',
                    'opening_stock', $product->id, 'Opening stock at product creation'
                );
            }

            DB::commit();
            return redirect()->route('products.index')->with('success', 'Product created successfully.');
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('[Product Store] Failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'input' => $request->all()
            ]);
            return back()->withInput()->with('error', 'Product creation failed. Check logs for details.');
        }
    }

    public function show(Product $product)
    {
        return redirect()->route('products.index');
    }

    public function details(Request $request)
    {
        $product = Product::findOrFail($request->id);

        return response()->json([
            'id' => $product->id,
            'name' => $product->name,
            'unit' => $product->measurement_unit,
            'price' => $product->selling_price,
        ]);
    }

    public function edit($id)
    {
        $product = Product::with([
            'images',
            'variations.attributeValues',
        ])->findOrFail($id);

        $categories = ProductCategory::all();
        $subcategories = ProductSubcategory::all();
        $attributes = Attribute::with('values')->get();
        $units = MeasurementUnit::all();

        $attributeValues = collect();
        foreach ($attributes as $attribute) {
            foreach ($attribute->values as $val) {
                $val->attribute = $attribute;
                $attributeValues->push($val);
            }
        }

        return view('products.edit', compact(
            'product', 'categories', 'subcategories', 'attributes', 'attributeValues', 'units'
        ));
    }

    public function update(Request $request, $id)
    {
        DB::beginTransaction();

        try {
            $product = Product::findOrFail($id);

            // opening_stock excluded — one-time seed at creation only, not editable here
            $product->update($request->only([
                'name', 'category_id', 'subcategory_id', 'sku', 'measurement_unit',
                'description', 'selling_price', 'is_active'
            ]));

            $handledVariationIds = [];

            if (is_array($request->variations)) {
                foreach ($request->variations as $variationData) {
                    $variation = ProductVariation::findOrFail($variationData['id']);
                    $variation->update([
                        'sku' => $variationData['sku'],
                        'selling_price' => $variationData['selling_price'] ?? 0,
                        // stock_quantity intentionally excluded — changes go through
                        // Purchase/Sale/Adjustment/Transfer for ledger integrity
                    ]);

                    if (!empty($variationData['attributes'])) {
                        $variation->attributeValues()->sync($variationData['attributes']);
                    }

                    $handledVariationIds[] = $variation->id;
                }
            }

            if (is_array($request->new_variations)) {
                foreach ($request->new_variations as $newVar) {
                    $initialStock = (float) ($newVar['stock_quantity'] ?? 0);

                    $variation = $product->variations()->create([
                        'sku' => $newVar['sku'],
                        'stock_quantity' => 0,
                        'selling_price' => $newVar['selling_price'] ?? 0,
                    ]);

                    if ($initialStock > 0) {
                        StockService::move(
                            $product->id, $variation->id, $initialStock, 'in',
                            'opening_stock', $variation->id, 'Opening stock — new variation added'
                        );
                    }

                    if (!empty($newVar['attributes'])) {
                        $variation->attributeValues()->sync($newVar['attributes']);
                    }

                    $handledVariationIds[] = $variation->id;
                }
            }

            if ($request->filled('removed_variations')) {
                ProductVariation::whereIn('id', $request->removed_variations)->delete();
            }

            DB::commit();
            return redirect()->route('products.index')->with('success', 'Product updated successfully.');
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('[Product Update] Failed', ['error' => $e->getMessage()]);
            return back()->withInput()->with('error', 'Product update failed. Try again.');
        }
    }

    public function destroy($id)
    {
        $product = Product::findOrFail($id);
        $product->delete();

        return redirect()->route('products.index')->with('success', 'Product deleted successfully.');
    }

    public function getVariations($productId)
    {
        $product = Product::with(['variations', 'measurementUnit'])->find($productId);

        if (!$product) {
            return response()->json(['success' => false, 'variation' => []]);
        }

        $unitId = optional($product->measurementUnit)->id;

        $variations = $product->variations->map(function ($v) use ($unitId) {
            return [
                'id'            => $v->id,
                'sku'           => $v->sku,
                'unit'          => $unitId,
                'selling_price' => (float) $v->selling_price,
                'cost_price'    => (float) $v->cost_price,
            ];
        });

        return response()->json([
            'success'   => true,
            'variation' => $variations,
            'product'   => [
                'id'            => $product->id,
                'name'          => $product->name,
                'unit'          => $unitId,
                'selling_price' => (float) ($product->selling_price ?? 0),
                'cost_price'    => (float) $product->cost_price,
            ],
        ]);
    }
}