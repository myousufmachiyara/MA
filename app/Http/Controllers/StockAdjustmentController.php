<?php

namespace App\Http\Controllers;

use App\Models\StockAdjustment;
use App\Models\StockMovementItem;
use App\Models\Product;
use App\Models\Location;
use App\Models\ChartOfAccounts;
use App\Services\StockService;
use App\Services\VoucherService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class StockAdjustmentController extends Controller
{
    private function inventoryAccount(): ChartOfAccounts
    {
        $account = ChartOfAccounts::where('account_code', '104001')->first();
        if (!$account) {
            throw new \Exception('Inventory account (104001) not found.');
        }
        return $account;
    }

    private function writeOffExpenseAccount(): ChartOfAccounts
    {
        $account = ChartOfAccounts::where('account_code', '505001')->first(); // Miscellaneous Expense
        if (!$account) {
            throw new \Exception('Miscellaneous Expense account (505001) not found.');
        }
        return $account;
    }

    private function otherIncomeAccount(): ChartOfAccounts
    {
        $account = ChartOfAccounts::where('account_code', '402001')->first(); // Other Income
        if (!$account) {
            throw new \Exception('Other Income account (402001) not found.');
        }
        return $account;
    }

    public function index(Request $request)
    {
        $query = StockAdjustment::with('items.product', 'location', 'creator');

        if ($request->filled('reason_type') && $request->reason_type !== 'all') {
            $query->where('reason_type', $request->reason_type);
        }
        if ($request->filled('location_id')) {
            $query->where('location_id', $request->location_id);
        }

        $adjustments = $query->latest()->get();
        $locations   = Location::where('is_active', true)->orderBy('name')->get();

        return view('stock_adjustments.index', compact('adjustments', 'locations'));
    }

    public function create()
    {
        $products  = Product::with('variations')->orderBy('name')->get();
        $locations = Location::where('is_active', true)->orderBy('name')->get();

        return view('stock_adjustments.create', compact('products', 'locations'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'adjustment_date'          => 'required|date',
            'location_id'              => 'nullable|exists:locations,id',
            'reason_type'              => 'required|in:damage,loss,theft,stock_take_correction,other',
            'remarks'                  => 'nullable|string',
            'items'                    => 'required|array|min:1',
            'items.*.item_id'          => 'required|exists:products,id',
            'items.*.variation_id'     => 'nullable|exists:product_variations,id',
            'items.*.direction'        => 'required|in:increase,decrease',
            'items.*.quantity'         => 'required|numeric|min:0.01',
            'items.*.unit_cost'        => 'required|numeric|min:0',
            'items.*.remarks'          => 'nullable|string',
        ]);

        DB::beginTransaction();

        try {
            $last         = StockAdjustment::withTrashed()->lockForUpdate()->orderByDesc('id')->first();
            $adjustmentNo = str_pad($last ? intval($last->adjustment_no) + 1 : 1, 6, '0', STR_PAD_LEFT);

            $locationId = $request->location_id ?? StockService::defaultLocationId();

            $adjustment = StockAdjustment::create([
                'adjustment_no'   => $adjustmentNo,
                'adjustment_date' => $request->adjustment_date,
                'location_id'     => $locationId,
                'reason_type'     => $request->reason_type,
                'remarks'         => $request->remarks,
                'created_by'      => auth()->id(),
                'updated_by'      => auth()->id(),
            ]);

            $increaseValue = 0;
            $decreaseValue = 0;
            $voucherLines  = [];

            foreach ($request->items as $itemData) {
                $qty       = (float) $itemData['quantity'];
                $unitCost  = (float) $itemData['unit_cost'];
                $direction = $itemData['direction'];
                $lineValue = $qty * $unitCost;

                $stockBefore = StockService::currentStock($itemData['item_id'], $itemData['variation_id'] ?? null);

                StockService::move(
                    $itemData['item_id'],
                    $itemData['variation_id'] ?? null,
                    $qty,
                    $direction === 'increase' ? 'in' : 'out',
                    'stock_adjustment',
                    $adjustment->id,
                    "Adjustment #{$adjustmentNo} ({$request->reason_type})" . (!empty($itemData['remarks']) ? ' — ' . $itemData['remarks'] : ''),
                    $locationId
                );

                $stockAfter = StockService::currentStock($itemData['item_id'], $itemData['variation_id'] ?? null);

                StockMovementItem::create([
                    'stock_adjustment_id' => $adjustment->id,
                    'item_id'             => $itemData['item_id'],
                    'variation_id'        => $itemData['variation_id'] ?? null,
                    'direction'           => $direction,
                    'quantity'            => $qty,
                    'unit_cost'           => $unitCost,
                    'stock_before'        => $stockBefore,
                    'stock_after'         => $stockAfter,
                    'remarks'             => $itemData['remarks'] ?? null,
                ]);

                if ($direction === 'increase') {
                    $increaseValue += $lineValue;
                } else {
                    $decreaseValue += $lineValue;
                }
            }

            $adjustment->update([
                'total_increase_value' => $increaseValue,
                'total_decrease_value' => $decreaseValue,
            ]);

            // ── Single combined voucher — both legs if both directions occurred ──
            $inventoryAccount = $this->inventoryAccount();

            if ($increaseValue > 0) {
                $voucherLines[] = ['account_id' => $inventoryAccount->id, 'debit' => $increaseValue, 'credit' => 0, 'narration' => 'Stock increase'];
                $voucherLines[] = ['account_id' => $this->otherIncomeAccount()->id, 'debit' => 0, 'credit' => $increaseValue, 'narration' => 'Unexplained gain'];
            }

            if ($decreaseValue > 0) {
                $voucherLines[] = ['account_id' => $this->writeOffExpenseAccount()->id, 'debit' => $decreaseValue, 'credit' => 0, 'narration' => 'Stock write-off'];
                $voucherLines[] = ['account_id' => $inventoryAccount->id, 'debit' => 0, 'credit' => $decreaseValue, 'narration' => 'Stock decrease'];
            }

            if (!empty($voucherLines)) {
                VoucherService::postEntries(
                    [
                        'voucher_type'   => 'journal',
                        'voucher_date'   => $request->adjustment_date,
                        'reference_type' => StockAdjustment::class,
                        'reference_id'   => $adjustment->id,
                        'remarks'        => "Stock Adjustment #{$adjustmentNo} ({$request->reason_type})",
                    ],
                    $voucherLines
                );
            }

            DB::commit();
            Log::info('[StockAdjustment] Created', ['id' => $adjustment->id, 'by' => auth()->id()]);

            return redirect()->route('stock_adjustments.index')->with('success', 'Stock adjustment recorded successfully.');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('[StockAdjustment] Store error', ['message' => $e->getMessage(), 'line' => $e->getLine()]);
            return back()->withInput()->with('error', 'Something went wrong: ' . $e->getMessage());
        }
    }

    public function show($id)
    {
        $adjustment = StockAdjustment::with(['items.product', 'items.variation', 'location', 'creator'])->findOrFail($id);
        return view('stock_adjustments.show', compact('adjustment'));
    }

    /**
     * Adjustments are financial + physical stock records — no edit, only
     * reversal via a fresh counter-adjustment, to keep an accurate audit trail.
     */
    public function destroy($id)
    {
        return back()->with('error', 'Stock adjustments cannot be deleted. Create a correcting adjustment instead to keep an accurate history.');
    }
}