<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\ChartOfAccounts;
use App\Models\SubHeadOfAccounts;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class COAController extends Controller
{
    // ─────────────────────────────────────────────────────────────
    // Canonical list of valid account types.
    // Must match the blade dropdown values exactly.
    // ─────────────────────────────────────────────────────────────
    private const ACCOUNT_TYPES = [
        'customer',
        'vendor',
        'cash',
        'bank',
        'inventory',
        'liability',
        'equity',
        'revenue',
        'cogs',
        'expenses',
        'receivable',
        'payable',
        'delivery_clearing', // system-managed — created via COA::getOrCreateDeliveryClearingAccount()
    ];

    // Types that require the customer/vendor tax profile fields
    private const PARTY_TYPES = ['customer', 'vendor'];

    public function index(Request $request)
    {
        $subHeadOfAccounts = SubHeadOfAccounts::with('headOfAccount')->orderBy('id')->get();

        $query = ChartOfAccounts::with('subHeadOfAccount');

        if ($request->filled('subhead') && $request->subhead !== 'all') {
            $query->where('shoa_id', $request->subhead);
        }

        if ($request->filled('account_type') && $request->account_type !== 'all') {
            $query->where('account_type', $request->account_type);
        }

        // Manually-created accounts only — hide system-managed ones
        // (like delivery_clearing) from the default list by default.
        if (!$request->boolean('show_system')) {
            $query->where('is_system_account', false)
                  ->orWhereNull('is_system_account');
        }

        $chartOfAccounts = $query->latest()->get();

        return view('accounts.coa', compact('chartOfAccounts', 'subHeadOfAccounts'));
    }

    public function store(Request $request)
    {
        try {
            Log::info('[COA] Store called', ['user_id' => auth()->id()]);

            $validated = $this->validateAccount($request);

            // ── Auto-generate account code ────────────────────────
            $subHead  = SubHeadOfAccounts::findOrFail($request->shoa_id);
            $prefix   = $subHead->hoa_id . str_pad($subHead->id, 2, '0', STR_PAD_LEFT);

            $existingCodes = ChartOfAccounts::withTrashed()
                ->where('account_code', 'like', $prefix . '%')
                ->pluck('account_code')
                ->map(fn($code) => intval(substr($code, strlen($prefix))))
                ->sort()
                ->values();

            $nextNumber  = ($existingCodes->isEmpty() ? 0 : $existingCodes->last()) + 1;
            $accountCode = $prefix . str_pad($nextNumber, 3, '0', STR_PAD_LEFT);

            Log::info('[COA] Generated account code', ['code' => $accountCode]);

            $account = ChartOfAccounts::create(array_merge($validated, [
                'account_code' => $accountCode,
                'created_by'   => auth()->id(),
                'updated_by'   => auth()->id(),
            ]));

            Log::info('[COA] Account created', ['id' => $account->id, 'code' => $accountCode]);

            return redirect()->route('coa.index')
                ->with('success', 'Account created successfully.');

        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('[COA] Store error', [
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);

            return redirect()->back()
                ->withInput()
                ->with('error', 'Something went wrong: ' . $e->getMessage());
        }
    }

    // Returns JSON for the edit modal AJAX call
    public function edit($id)
    {
        $account = ChartOfAccounts::with('subHeadOfAccount')->findOrFail($id);
        return response()->json($account);
    }

    public function update(Request $request, $id)
    {
        try {
            $account = ChartOfAccounts::findOrFail($id);

            if ($account->is_system_account) {
                return back()->with('error', 'System-managed accounts (e.g. Delivery Clearing) cannot be edited here.');
            }

            $validated = $this->validateAccount($request, $id);

            $account->update(array_merge($validated, [
                'updated_by' => auth()->id(),
            ]));

            Log::info('[COA] Account updated', ['id' => $id, 'user' => auth()->id()]);

            return redirect()->route('coa.index')
                ->with('success', 'Account updated successfully.');

        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('[COA] Update error', ['message' => $e->getMessage()]);
            return back()->withInput()->withErrors(['error' => $e->getMessage()]);
        }
    }

    public function show($id)
    {
        $account = ChartOfAccounts::with('subHeadOfAccount')->findOrFail($id);
        return response()->json($account);
    }

    public function destroy($id)
    {
        try {
            $account = ChartOfAccounts::findOrFail($id);

            // FIX: guard driven by DB flag, not a hardcoded code array
            if ($account->is_system_account) {
                return redirect()->back()
                    ->with('error', 'System account "' . $account->name . '" cannot be deleted.');
            }

            $account->delete();
            return redirect()->route('coa.index')->with('success', 'Account deleted successfully.');

        } catch (\Exception $e) {
            return redirect()->back()->with('error', 'Error: ' . $e->getMessage());
        }
    }

    /**
     * Toggle active/inactive without deleting — e.g. a retailer stops ordering
     * but their invoice/ledger history must stay intact.
     */
    public function toggleActive($id)
    {
        $account = ChartOfAccounts::findOrFail($id);
        $account->update(['is_active' => !$account->is_active, 'updated_by' => auth()->id()]);

        return back()->with('success', $account->is_active ? 'Account activated.' : 'Account deactivated.');
    }

    // ─────────────────────────────────────────────────────────────
    private function validateAccount(Request $request, $ignoreId = null): array
    {
        $isParty = in_array($request->account_type, self::PARTY_TYPES);

        $validated = $request->validate([
            'shoa_id'      => 'required|exists:sub_head_of_accounts,id',
            'name'         => [
                'required',
                'string',
                'max:255',
                Rule::unique('chart_of_accounts')->ignore($ignoreId)->whereNull('deleted_at'),
            ],
            'account_type' => ['nullable', 'string', Rule::in(self::ACCOUNT_TYPES)],

            // Tax profile — only meaningful for customer/vendor, but validated
            // loosely regardless so a stray value never gets silently saved.
            'customer_type'      => ['nullable', Rule::in(['retailer', 'wholesaler'])],
            'is_gst_registered'  => 'nullable|boolean',
            'gst_number'         => 'nullable|string|max:50|required_if:is_gst_registered,1',
            'filer_status'       => ['nullable', Rule::in(['filer', 'non_filer'])],
            'wht_applicable'     => 'nullable|boolean',
            'wht_rate'           => 'nullable|numeric|min:0|max:100|required_if:wht_applicable,1',

            // FIX: no longer globally required — only meaningful for customer/vendor
            'receivables'  => 'nullable|numeric',
            'payables'     => 'nullable|numeric',
            'credit_limit' => 'nullable|numeric',

            'opening_date' => 'required|date',
            'remarks'      => 'nullable|string|max:800',
            'address'      => 'nullable|string|max:250',
            'contact_no'   => 'nullable|string|max:250',
        ]);

        // Defaults for non-party account types
        $validated['receivables']  = $validated['receivables']  ?? 0;
        $validated['payables']     = $validated['payables']     ?? 0;
        $validated['credit_limit'] = $validated['credit_limit'] ?? 0;
        $validated['is_gst_registered'] = $request->boolean('is_gst_registered');
        $validated['wht_applicable']    = $request->boolean('wht_applicable');

        // Auto-suggest WHT rate if applicable but not manually overridden
        if ($isParty && $validated['wht_applicable'] && empty($validated['wht_rate'])) {
            $validated['wht_rate'] = ChartOfAccounts::suggestWhtRate(
                $validated['customer_type'] ?? null,
                $validated['filer_status'] ?? null
            );
        }

        // Non-party types shouldn't carry party-only fields
        if (!$isParty) {
            $validated['customer_type']     = null;
            $validated['gst_number']        = null;
            $validated['filer_status']      = null;
            $validated['is_gst_registered'] = false;
            $validated['wht_applicable']    = false;
            $validated['wht_rate']          = null;
        }

        return $validated;
    }
}