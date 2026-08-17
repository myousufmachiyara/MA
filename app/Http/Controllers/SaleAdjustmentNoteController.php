<?php
// app/Http/Controllers/SaleAdjustmentNoteController.php
namespace App\Http\Controllers;

use App\Models\SaleAdjustmentNote;
use App\Models\SaleInvoice;
use App\Services\VoucherService;
use App\Services\SystemAccountService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SaleAdjustmentNoteController extends Controller
{
    public function index()
    {
        $notes = SaleAdjustmentNote::with('invoice.customer')->latest()->get();
        return view('sale_adjustment_notes.index', compact('notes'));
    }

    public function create(Request $request)
    {
        $invoice = $request->filled('invoice_id') ? SaleInvoice::with('customer')->find($request->invoice_id) : null;
        return view('sale_adjustment_notes.create', compact('invoice'));
    }

    public function searchInvoices(Request $request)
    {
        $term = $request->get('term', '');
        $invoices = SaleInvoice::with('customer')
            ->where('invoice_no', 'like', "%{$term}%")
            ->orWhereHas('customer', fn ($q) => $q->where('name', 'like', "%{$term}%"))
            ->latest()->limit(20)->get();

        return response()->json($invoices->map(fn ($i) => [
            'id' => $i->id,
            'text' => "SI-{$i->invoice_no} — " . ($i->customer->name ?? 'N/A') . " (Due: PKR " . number_format($i->balance_due, 2) . ")",
        ]));
    }

    public function store(Request $request)
    {
        $request->validate([
            'note_type'       => 'required|in:debit,credit',
            'sale_invoice_id' => 'required|exists:sale_invoices,id',
            'note_date'       => 'required|date',
            'amount'          => 'required|numeric|min:0.01',
            'reason'          => 'required|string|max:255',
            'remarks'         => 'nullable|string',
        ]);

        DB::beginTransaction();
        try {
            $invoice = SaleInvoice::findOrFail($request->sale_invoice_id);

            $last = SaleAdjustmentNote::withTrashed()->lockForUpdate()->orderByDesc('id')->first();
            $noteNo = str_pad($last ? intval($last->note_no) + 1 : 1, 6, '0', STR_PAD_LEFT);

            $note = SaleAdjustmentNote::create([
                'note_no'         => $noteNo,
                'note_type'       => $request->note_type,
                'sale_invoice_id' => $invoice->id,
                'note_date'       => $request->note_date,
                'amount'          => $request->amount,
                'reason'          => $request->reason,
                'remarks'         => $request->remarks,
                'created_by'      => auth()->id(),
            ]);

            $salesAccount = SystemAccountService::salesRevenue();

            // Credit Note: reduces what customer owes — Dr Sales / Cr Customer
            // Debit Note: increases what customer owes — Dr Customer / Cr Sales
            $lines = $request->note_type === 'credit'
                ? [
                    ['account_id' => $salesAccount->id, 'debit' => $request->amount, 'credit' => 0, 'narration' => "Credit note — {$request->reason}"],
                    ['account_id' => $invoice->customer_id, 'debit' => 0, 'credit' => $request->amount, 'narration' => 'Credited to customer'],
                ]
                : [
                    ['account_id' => $invoice->customer_id, 'debit' => $request->amount, 'credit' => 0, 'narration' => 'Debited to customer'],
                    ['account_id' => $salesAccount->id, 'debit' => 0, 'credit' => $request->amount, 'narration' => "Debit note — {$request->reason}"],
                ];

            VoucherService::postEntries(
                [
                    'voucher_type'   => 'journal',
                    'voucher_date'   => $request->note_date,
                    'reference_type' => SaleAdjustmentNote::class,
                    'reference_id'   => $note->id,
                    'remarks'        => "Sale " . ucfirst($request->note_type) . " Note #{$noteNo} — Invoice #{$invoice->invoice_no}",
                ],
                $lines
            );

            // net_adjustment: credit note reduces due (negative), debit note increases due (positive)
            $delta = $request->note_type === 'credit' ? -$request->amount : $request->amount;
            $invoice->increment('net_adjustment', $delta);

            DB::commit();
            return redirect()->route('sale_adjustment_notes.index')->with('success', ucfirst($request->note_type) . ' note recorded successfully.');

        } catch (\Exception $e) {
            DB::rollBack();
            return back()->withInput()->with('error', 'Something went wrong: ' . $e->getMessage());
        }
    }

    public function show($id)
    {
        $note = SaleAdjustmentNote::with('invoice.customer')->findOrFail($id);
        return view('sale_adjustment_notes.show', compact('note'));
    }
}