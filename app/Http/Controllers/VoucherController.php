<?php

namespace App\Http\Controllers;

use App\Models\Voucher;
use App\Models\ChartOfAccounts;
use App\Services\VoucherService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class VoucherController extends Controller
{
    public function index($type)
    {
        $vouchers = Voucher::with(['debitAccount', 'creditAccount', 'entries.account'])
            ->where('voucher_type', $type)
            ->orderByDesc('voucher_date')
            ->orderByDesc('id')
            ->get();

        $accounts = ChartOfAccounts::where('is_active', true)
        ->where('is_system_account', false)
        ->orderBy('account_code')
        ->get();

        return view('vouchers.index', [
            'vouchers' => $vouchers,
            'accounts' => $accounts,
            'type'     => $type,
        ]);
    }

    public function create($type)
    {
        $accounts = ChartOfAccounts::where('is_active', true)
        ->where('is_system_account', false)
        ->orderBy('account_code')
        ->get();
        return view('vouchers.create', compact('accounts', 'type'));
    }

    public function show($type, $id)
    {
        $voucher = Voucher::with(['debitAccount', 'creditAccount', 'entries.account'])->findOrFail($id);

        if ($voucher->is_auto) {
            return response()->json([
                'voucher_no' => $voucher->voucher_no,
                'date'       => optional($voucher->voucher_date)->format('Y-m-d'),
                'amount'     => $voucher->display_total,
                'remarks'    => $voucher->remarks,
                'is_simple'  => false,
                'entries'    => $voucher->entries->map(fn ($e) => [
                    'account_name' => $e->account->name ?? 'N/A',
                    'debit'        => (float) $e->debit,
                    'credit'       => (float) $e->credit,
                    'narration'    => $e->narration,
                ]),
            ]);
        }

        return response()->json([
            'voucher_no' => $voucher->voucher_no,
            'date'       => optional($voucher->voucher_date)->format('Y-m-d'),
            'ac_dr_sid'  => $voucher->ac_dr_sid,
            'ac_cr_sid'  => $voucher->ac_cr_sid,
            'amount'     => (float) $voucher->amount,
            'remarks'    => $voucher->remarks,
            'is_simple'  => true,
        ]);
    }

    public function edit($type, $id)
    {
        $voucher  = Voucher::findOrFail($id);
        $accounts = ChartOfAccounts::all();
        return view('vouchers.edit', compact('voucher', 'accounts', 'type'));
    }

    public function store(Request $request, $type)
    {
        try {
            $data = $request->validate([
                'voucher_date' => 'required|date',
                'ac_dr_sid'    => 'required|numeric|exists:chart_of_accounts,id',
                'ac_cr_sid'    => 'required|numeric|different:ac_dr_sid|exists:chart_of_accounts,id',
                'amount'       => 'required|numeric|min:1',
                'remarks'      => 'nullable|string',
                'att.*'        => 'nullable|file|max:2048',
            ]);

            $attachments = [];
            if ($request->hasFile('att')) {
                foreach ($request->file('att') as $file) {
                    $attachments[] = $file->store("attachments/{$type}", 'public');
                }
            }

            $voucher = VoucherService::post([
                'voucher_type' => $type,
                'voucher_date' => $data['voucher_date'],
                'ac_dr_sid'    => $data['ac_dr_sid'],
                'ac_cr_sid'    => $data['ac_cr_sid'],
                'amount'       => $data['amount'],
                'remarks'      => $data['remarks'] ?? null,
            ]);

            if ($attachments) {
                $voucher->update(['attachments' => $attachments]);
            }

            return back()->with('success', ucfirst($type) . ' voucher added successfully!');

        } catch (\Throwable $e) {
            Log::error("Error storing {$type} voucher: " . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
            return back()->with('error', 'Something went wrong while adding the voucher. Check logs.');
        }
    }

    public function update(Request $request, $type, $id)
    {
        try {
            $voucher = Voucher::findOrFail($id);

            if ($voucher->is_auto) {
                return back()->with('error', 'Auto-generated vouchers cannot be edited directly. Edit the source document instead.');
            }

            $data = $request->validate([
                'voucher_date' => 'required|date',
                'ac_dr_sid'    => 'required|numeric|exists:chart_of_accounts,id',
                'ac_cr_sid'    => 'required|numeric|different:ac_dr_sid|exists:chart_of_accounts,id',
                'amount'       => 'required|numeric|min:1',
                'remarks'      => 'nullable|string',
                'att.*'        => 'nullable|file|max:2048',
            ]);

            $attachments = $voucher->attachments ?? [];
            if ($request->hasFile('att')) {
                foreach ($request->file('att') as $file) {
                    $attachments[] = $file->store("attachments/{$type}", 'public');
                }
            }

            $voucher->update([
                'voucher_date' => $data['voucher_date'],
                'ac_dr_sid'    => $data['ac_dr_sid'],
                'ac_cr_sid'    => $data['ac_cr_sid'],
                'amount'       => $data['amount'],
                'remarks'      => $data['remarks'] ?? null,
                'attachments'  => $attachments,
            ]);

            return back()->with('success', ucfirst($type) . ' voucher updated successfully!');

        } catch (\Throwable $e) {
            Log::error("Error updating {$type} voucher ID {$id}: " . $e->getMessage());
            return back()->with('error', 'Something went wrong while updating the voucher. Check logs.');
        }
    }

    public function destroy($type, $id)
    {
        try {
            $voucher = Voucher::findOrFail($id);

            if ($voucher->is_auto) {
                return back()->with('error', 'Auto-generated vouchers cannot be deleted directly. Delete or reverse the source document instead.');
            }

            if (!empty($voucher->attachments)) {
                foreach ($voucher->attachments as $file) {
                    if (Storage::disk('public')->exists($file)) {
                        Storage::disk('public')->delete($file);
                    }
                }
            }

            $voucher->delete();

            return back()->with('success', ucfirst($type) . ' voucher deleted successfully.');

        } catch (\Throwable $e) {
            Log::error("Error deleting {$type} voucher ID {$id}: " . $e->getMessage());
            return back()->with('error', 'Something went wrong while deleting the voucher. Check logs.');
        }
    }

    public function print($type, $id)
    {
        $voucher = Voucher::with(['debitAccount', 'creditAccount', 'entries.account'])->findOrFail($id);

        $pdf = new \TCPDF();
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetCreator('BillTrix');
        $pdf->SetAuthor('M.A Distributor');
        $pdf->SetTitle(ucfirst($type) . ' Voucher #' . $voucher->voucher_no);
        $pdf->SetMargins(10, 10, 10);
        $pdf->AddPage();
        $pdf->setCellPadding(1.5);

        $logoPath = public_path('assets/img/logo.png');
        if (file_exists($logoPath)) {
            $pdf->Image($logoPath, 12, 8, 40);
        }

        $pdf->SetFont('helvetica', 'B', 14);
        $pdf->SetXY(120, 12);
        $pdf->Cell(80, 8, ucfirst($type) . ' Voucher', 0, 1, 'R');

        $pdf->Ln(5);
        $pdf->SetFont('helvetica', '', 10);

        $infoHtml = '
        <table cellpadding="3" cellspacing="0" width="60%">
            <tr><td>
                <table border="1" cellpadding="4" cellspacing="0" style="font-size:10px;">
                    <tr><td width="30%"><b>Voucher #</b></td><td width="40%">' . $voucher->voucher_no . '</td></tr>
                    <tr><td width="30%"><b>Date</b></td><td width="40%">' . optional($voucher->voucher_date)->format('d-m-Y') . '</td></tr>
                    ' . ($voucher->reference_label ? '<tr><td width="30%"><b>Source</b></td><td width="40%">' . e($voucher->reference_label) . '</td></tr>' : '') . '
                </table>
            </td></tr>
        </table>';
        $pdf->writeHTML($infoHtml, true, false, false, false, '');

        if ($voucher->is_auto) {
            $rows = '';
            $totalDr = 0;
            $totalCr = 0;

            foreach ($voucher->entries as $e) {
                $totalDr += $e->debit;
                $totalCr += $e->credit;
                $rows .= '<tr>
                    <td>' . e($e->account->name ?? 'N/A') . '</td>
                    <td align="right">' . ($e->debit > 0 ? number_format($e->debit, 2) : '') . '</td>
                    <td align="right">' . ($e->credit > 0 ? number_format($e->credit, 2) : '') . '</td>
                    <td>' . e($e->narration ?? '') . '</td>
                </tr>';
            }

            $html = '<table border="0.3" cellpadding="4" style="text-align:left;font-size:10px;">
                <tr style="background-color:#f5f5f5;font-weight:bold;">
                    <th width="35%">Account</th><th width="20%">Debit</th><th width="20%">Credit</th><th width="25%">Narration</th>
                </tr>' . $rows . '
                <tr style="background-color:#f5f5f5;">
                    <td><b>Total</b></td>
                    <td align="right"><b>' . number_format($totalDr, 2) . '</b></td>
                    <td align="right"><b>' . number_format($totalCr, 2) . '</b></td>
                    <td></td>
                </tr>
            </table>';
        } else {
            $html = '<table border="0.3" cellpadding="4" style="text-align:center;font-size:10px;">
                <tr style="background-color:#f5f5f5;font-weight:bold;">
                    <th width="8%">S.No</th><th width="36%">Debit Account</th><th width="36%">Credit Account</th><th width="20%">Amount</th>
                </tr>
                <tr>
                    <td>1</td>
                    <td>' . ($voucher->debitAccount->name ?? '-') . '</td>
                    <td>' . ($voucher->creditAccount->name ?? '-') . '</td>
                    <td align="right">' . number_format($voucher->amount, 2) . '</td>
                </tr>
                <tr style="background-color:#f5f5f5;">
                    <td colspan="3" align="right"><b>Total</b></td>
                    <td align="right"><b>' . number_format($voucher->amount, 2) . '</b></td>
                </tr>
            </table>';
        }

        $pdf->writeHTML($html, true, false, true, false, '');
        $pdf->Ln(5);

        if (!empty($voucher->remarks)) {
            $pdf->writeHTML('<b>Remarks:</b><br><span style="font-size:12px;">' . nl2br(e($voucher->remarks)) . '</span>', true, false, true, false, '');
        }

        $pdf->Ln(20);
        $yPos = $pdf->GetY();
        $lineWidth = 40;

        $pdf->Line(28, $yPos, 28 + $lineWidth, $yPos);
        $pdf->Line(130, $yPos, 130 + $lineWidth, $yPos);

        $pdf->SetXY(28, $yPos + 2);
        $pdf->Cell($lineWidth, 6, 'Prepared By', 0, 0, 'C');
        $pdf->SetXY(130, $yPos + 2);
        $pdf->Cell($lineWidth, 6, 'Authorized By', 0, 0, 'C');

        return $pdf->Output(strtolower($type) . '_voucher_' . $voucher->voucher_no . '.pdf', 'I');
    }
}