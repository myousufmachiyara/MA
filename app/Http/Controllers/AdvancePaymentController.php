<?php
// app/Http/Controllers/AdvancePaymentController.php
namespace App\Http\Controllers;

use App\Models\AdvancePayment;
use App\Models\AdvancePaymentAdjustment;
use App\Models\ChartOfAccounts;
use App\Models\SaleInvoice;
use App\Models\PurchaseInvoice;
use App\Services\VoucherService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdvancePaymentController extends Controller
{
    public function index(Request $request)
    {
        $query = AdvancePayment::with('party');
        if ($request->filled('party_type')) $query->where('party_type', $request->party_type);
        $advances = $query->latest()->get();
        return view('advance_payments.index', compact('advances'));
    }

    public function create()
    {
        $customers = ChartOfAccounts::where('account_type', 'customer')->orderBy('name')->get();
        $vendors   = ChartOfAccounts::where('account_type', 'vendor')->orderBy('name')->get();
        $cashBankAccounts = ChartOfAccounts::whereIn('account_type', ['cash', 'bank'])->get();
        return view('advance_payments.create', compact('customers', 'vendors', 'cashBankAccounts'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'party_type'            => 'required|in:customer,vendor',
            'party_id'               => 'required|exists:chart_of_accounts,id',
            'payment_date'           => 'required|date',
            'cash_bank_account_id'   => 'required|exists:chart_of_accounts,id',
            'amount'                 => 'required|numeric|min:0.01',
            'remarks'                => 'nullable|string',
        ]);

        DB::beginTransaction();
        try {
            $last = AdvancePayment::withTrashed()->lockForUpdate()->orderByDesc('id')->first();
            $advanceNo = str_pad($last ? intval($last->advance_no) + 1 : 1, 6, '0', STR_PAD_LEFT);

            $advance = AdvancePayment::create([
                'advance_no'           => $advanceNo,
                'party_id'             => $request->party_id,
                'party_type'           => $request->party_type,
                'payment_date'         => $request->payment_date,
                'cash_bank_account_id' => $request->cash_bank_account_id,
                'amount'               => $request->amount,
                'remaining_amount'     => $request->amount,
                'remarks'              => $request->remarks,
                'created_by'           => auth()->id(),
            ]);

            // Customer advance received: Dr Cash/Bank / Cr Customer (credit balance = they've overpaid)
            // Vendor advance paid: Dr Vendor / Cr Cash/Bank (debit balance = they owe us goods/refund)
            $lines = $request->party_type === 'customer'
                ? [
                    ['account_id' => $request->cash_bank_account_id, 'debit' => $request->amount, 'credit' => 0, 'narration' => 'Advance received'],
                    ['account_id' => $request->party_id, 'debit' => 0, 'credit' => $request->amount, 'narration' => 'Advance credited to customer'],
                ]
                : [
                    ['account_id' => $request->party_id, 'debit' => $request->amount, 'credit' => 0, 'narration' => 'Advance paid to vendor'],
                    ['account_id' => $request->cash_bank_account_id, 'debit' => 0, 'credit' => $request->amount, 'narration' => 'Advance paid'],
                ];

            VoucherService::postEntries(
                [
                    'voucher_type'   => 'receipt', // 'payment' would fit vendor advances better — see note below
                    'voucher_date'   => $request->payment_date,
                    'reference_type' => AdvancePayment::class,
                    'reference_id'   => $advance->id,
                    'remarks'        => "Advance Payment #{$advanceNo}",
                ],
                $lines
            );

            DB::commit();
            return redirect()->route('advance_payments.index')->with('success', 'Advance payment recorded successfully.');

        } catch (\Exception $e) {
            DB::rollBack();
            return back()->withInput()->with('error', 'Something went wrong: ' . $e->getMessage());
        }
    }

    /**
     * Apply part or all of an advance against a specific invoice. No new
     * voucher — the advance and the invoice already sit on the same party
     * account, so the GL nets correctly. This just records which invoice
     * consumed how much of the advance, and reduces the remaining balance.
     */
    public function adjust(Request $request, $id)
    {
        $advance = AdvancePayment::findOrFail($id);

        $request->validate([
            'invoice_type' => 'required|in:sale_invoice,purchase_invoice',
            'invoice_id'   => 'required|integer',
            'amount'       => 'required|numeric|min:0.01|max:' . $advance->remaining_amount,
        ]);

        DB::beginTransaction();
        try {
            AdvancePaymentAdjustment::create([
                'advance_payment_id' => $advance->id,
                'invoice_type'       => $request->invoice_type,
                'invoice_id'         => $request->invoice_id,
                'amount_adjusted'    => $request->amount,
                'adjustment_date'    => now(),
                'created_by'         => auth()->id(),
            ]);

            $advance->decrement('remaining_amount', $request->amount);

            // Mark the invoice as more paid, so balance_due reports correctly
            if ($request->invoice_type === 'sale_invoice') {
                SaleInvoice::find($request->invoice_id)?->increment('paid_amount', $request->amount);
            }

            DB::commit();
            return back()->with('success', 'Advance adjusted against invoice.');

        } catch (\Exception $e) {
            DB::rollBack();
            return back()->with('error', 'Error: ' . $e->getMessage());
        }
    }

    public function show($id)
    {
        $advance = AdvancePayment::with('party', 'adjustments')->findOrFail($id);
        return view('advance_payments.show', compact('advance'));
    }

    public function openInvoices($id)
    {
        $advance = AdvancePayment::findOrFail($id);

        if ($advance->party_type === 'customer') {
            $invoices = \App\Models\SaleInvoice::where('customer_id', $advance->party_id)
                ->get()
                ->filter(fn ($i) => $i->balance_due > 0)
                ->map(fn ($i) => ['id' => $i->id, 'label' => "SI-{$i->invoice_no} (Due: PKR " . number_format($i->balance_due, 2) . ")"])
                ->values();
        } else {
            $invoices = \App\Models\PurchaseInvoice::where('vendor_id', $advance->party_id)
                ->get()
                ->map(fn ($i) => ['id' => $i->id, 'label' => "PUR-{$i->invoice_no} (PKR " . number_format($i->total_amount, 2) . ")"])
                ->values();
        }

        return response()->json([
            'success'      => true,
            'invoice_type' => $advance->party_type === 'customer' ? 'sale_invoice' : 'purchase_invoice',
            'invoices'     => $invoices,
        ]);
    }
}