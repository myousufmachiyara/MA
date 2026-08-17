<?php

namespace App\Http\Controllers;

use App\Models\SystemAccountMapping;
use App\Models\ChartOfAccounts;
use Illuminate\Http\Request;

class SystemAccountMappingController extends Controller
{
    public function index()
    {
        $mappings = SystemAccountMapping::orderBy('label')->get();
        $accounts = ChartOfAccounts::where('is_active', true)->orderBy('account_code')->get();

        return view('settings.account_mappings', compact('mappings', 'accounts'));
    }

    public function update(Request $request)
    {
        $request->validate([
            'mappings'   => 'required|array',
            'mappings.*' => 'nullable|exists:chart_of_accounts,id',
        ]);

        foreach ($request->mappings as $key => $accountId) {
            SystemAccountMapping::where('key', $key)->update([
                'account_id' => $accountId,
                'updated_by' => auth()->id(),
            ]);
        }

        return redirect()->route('settings.accountMappings')->with('success', 'Account mappings updated successfully.');
    }
}