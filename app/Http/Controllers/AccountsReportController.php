<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\ChartOfAccounts;
use App\Models\Voucher;
use App\Models\AccountingEntry;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class AccountsReportController extends Controller
{
    // DEBIT-NATURED (assets & expenses — increase with Dr)
    private const DEBIT_NATURE = ['customer', 'receivable', 'cash', 'bank', 'inventory', 'delivery_clearing', 'expenses', 'cogs'];
    // CREDIT-NATURED (liabilities, equity & revenue — increase with Cr)
    private const CREDIT_NATURE = ['vendor', 'payable', 'liability', 'equity', 'revenue'];

    public function accounts(Request $request)
    {
        $from      = $request->from_date ?? Carbon::now()->startOfMonth()->toDateString();
        $to        = $request->to_date   ?? Carbon::now()->endOfMonth()->toDateString();
        $accountId = $request->account_id ? (int) $request->account_id : null;

        $chartOfAccounts = ChartOfAccounts::orderBy('name')->get();

        $reports = [
            'general_ledger'   => $this->generalLedger($accountId, $from, $to),
            'trial_balance'    => $this->trialBalance($to),
            'profit_loss'      => $this->profitLoss($from, $to),
            'balance_sheet'    => $this->balanceSheet($from, $to),
            'party_ledger'     => $this->partyLedger($accountId, $from, $to),
            'receivables'      => $this->receivables($to),
            'payables'         => $this->payables($to),
            'cash_book'        => $this->cashBook($from, $to),
            'bank_book'        => $this->bankBook($from, $to),
            'journal_book'     => $this->journalBook($from, $to),
            'expense_analysis' => $this->expenseAnalysis($from, $to),
            'cash_flow'        => $this->cashFlow($from, $to),
        ];

        return view('reports.accounts_reports', compact('reports', 'from', 'to', 'chartOfAccounts'));
    }

    // ── HELPERS ──────────────────────────────────────────────────

    private function fmt($v): string
    {
        return number_format((float) $v, 2);
    }

    private function isDebitNature(?string $accountType): bool
    {
        return in_array($accountType ?? '', self::DEBIT_NATURE);
    }

    /**
     * Core balance calculator — reads BOTH:
     *  - simple vouchers (manual entries, reference_type null) via ac_dr_sid/ac_cr_sid
     *  - multi-line vouchers (auto-generated) via accounting_entries
     *
     * Mode A: pass $asOfDate — cumulative balance including COA opening balance.
     * Mode B: pass $from/$to, leave $asOfDate null — period-only movement.
     */
    private function getAccountBalance(int $accountId, ?string $from, ?string $to, ?string $asOfDate = null): array
    {
        $account = ChartOfAccounts::find($accountId);
        if (!$account) return ['debit' => 0.0, 'credit' => 0.0];

        $applyDateFilter = function ($query) use ($from, $to, $asOfDate) {
            return $asOfDate
                ? $query->where('voucher_date', '<=', $asOfDate)
                : $query->whereBetween('voucher_date', [$from, $to]);
        };

        $openingDr = $asOfDate ? (float) $account->receivables : 0.0;
        $openingCr = $asOfDate ? (float) $account->payables : 0.0;

        $simpleDr = (float) $applyDateFilter(
            Voucher::where('ac_dr_sid', $accountId)->whereNull('reference_type')->whereNull('deleted_at')
        )->sum('amount');

        $simpleCr = (float) $applyDateFilter(
            Voucher::where('ac_cr_sid', $accountId)->whereNull('reference_type')->whereNull('deleted_at')
        )->sum('amount');

        $complexDr = (float) AccountingEntry::where('account_id', $accountId)
            ->whereHas('voucher', fn ($q) => $applyDateFilter($q)->whereNull('deleted_at'))
            ->sum('debit');

        $complexCr = (float) AccountingEntry::where('account_id', $accountId)
            ->whereHas('voucher', fn ($q) => $applyDateFilter($q)->whereNull('deleted_at'))
            ->sum('credit');

        return [
            'debit'  => $openingDr + $simpleDr + $complexDr,
            'credit' => $openingCr + $simpleCr + $complexCr,
        ];
    }

    /**
     * Chronological movement lines for one account — combines simple
     * voucher rows and individual accounting_entries lines, each carrying
     * its own reference_label/reference_link (built by the Voucher model).
     */
    private function getLedgerLines(int $accountId, string $from, string $to): Collection
    {
        $lines = collect();

        Voucher::whereNull('reference_type')
            ->whereBetween('voucher_date', [$from, $to])
            ->whereNull('deleted_at')
            ->where(fn ($q) => $q->where('ac_dr_sid', $accountId)->orWhere('ac_cr_sid', $accountId))
            ->get()
            ->each(function ($v) use ($accountId, &$lines) {
                $lines->push([
                    'sort'             => $v->voucher_date->format('Ymd') . str_pad($v->id, 10, '0', STR_PAD_LEFT),
                    'date'             => $v->voucher_date->format('Y-m-d'),
                    'reference_label'  => $v->voucher_no,
                    'reference_link'   => route('vouchers.print', ['type' => $v->voucher_type, 'id' => $v->id]),
                    'narration'        => $v->remarks ?? '',
                    'dr'               => ($v->ac_dr_sid == $accountId) ? (float) $v->amount : 0.0,
                    'cr'               => ($v->ac_cr_sid == $accountId) ? (float) $v->amount : 0.0,
                ]);
            });

        AccountingEntry::where('account_id', $accountId)
            ->whereHas('voucher', fn ($q) => $q->whereBetween('voucher_date', [$from, $to])->whereNull('deleted_at'))
            ->with('voucher')
            ->get()
            ->each(function ($entry) use (&$lines) {
                $v = $entry->voucher;
                $lines->push([
                    'sort'             => optional($v->voucher_date)->format('Ymd') . str_pad($v->id, 10, '0', STR_PAD_LEFT),
                    'date'             => optional($v->voucher_date)->format('Y-m-d') ?? '',
                    'reference_label'  => $v->reference_label ?? $v->voucher_no,
                    'reference_link'   => $v->reference_link ?? route('vouchers.print', ['type' => $v->voucher_type, 'id' => $v->id]),
                    'narration'        => $entry->narration ?? $v->remarks ?? '',
                    'dr'               => (float) $entry->debit,
                    'cr'               => (float) $entry->credit,
                ]);
            });

        return $lines->sortBy('sort')->values();
    }

    // ── 1. GENERAL LEDGER ───────────────────────────────────────

    private function generalLedger(?int $accountId, string $from, string $to): Collection
    {
        if (!$accountId) return collect();

        $account = ChartOfAccounts::find($accountId);
        if (!$account) return collect();

        $isDebitNat = $this->isDebitNature($account->account_type);
        $opData     = $this->getAccountBalance($accountId, null, null, Carbon::parse($from)->subDay()->toDateString());
        $running    = $isDebitNat ? ($opData['debit'] - $opData['credit']) : ($opData['credit'] - $opData['debit']);

        $rows = collect([[
            'date' => $from, 'account' => $account->name, 'reference_label' => 'Opening Balance',
            'reference_link' => null, 'narration' => '', 'dr' => '', 'cr' => '',
            'balance' => $this->fmt($running), 'is_opening' => true,
        ]]);

        foreach ($this->getLedgerLines($accountId, $from, $to) as $line) {
            $running += $isDebitNat ? ($line['dr'] - $line['cr']) : ($line['cr'] - $line['dr']);
            $rows->push([
                'date' => $line['date'], 'account' => $account->name,
                'reference_label' => $line['reference_label'], 'reference_link' => $line['reference_link'],
                'narration' => $line['narration'],
                'dr' => $line['dr'] > 0.001 ? $this->fmt($line['dr']) : '',
                'cr' => $line['cr'] > 0.001 ? $this->fmt($line['cr']) : '',
                'balance' => $this->fmt($running), 'is_opening' => false,
            ]);
        }

        return $rows;
    }

    // ── 2. PARTY LEDGER (customers & vendors only) ─────────────

    private function partyLedger(?int $accountId, string $from, string $to): Collection
    {
        if (!$accountId) return collect();
        $account = ChartOfAccounts::find($accountId);
        if (!$account || !in_array($account->account_type, ['customer', 'vendor'])) return collect();

        return $this->generalLedger($accountId, $from, $to);
    }

    // ── 3. PROFIT & LOSS ────────────────────────────────────────

    private function profitLoss(string $from, string $to)
    {
        $revenue = ChartOfAccounts::where('account_type', 'revenue')->get()
            ->map(fn ($a) => [$a->name, $this->getAccountBalance($a->id, $from, $to)['credit'] - $this->getAccountBalance($a->id, $from, $to)['debit']])
            ->filter(fn ($r) => $r[1] != 0);

        $cogs = ChartOfAccounts::where('account_type', 'cogs')->get()
            ->map(fn ($a) => [$a->name, $this->getAccountBalance($a->id, $from, $to)['debit'] - $this->getAccountBalance($a->id, $from, $to)['credit']])
            ->filter(fn ($r) => $r[1] != 0);

        $expenses = ChartOfAccounts::where('account_type', 'expenses')->get()
            ->map(fn ($a) => [$a->name, $this->getAccountBalance($a->id, $from, $to)['debit'] - $this->getAccountBalance($a->id, $from, $to)['credit']])
            ->filter(fn ($r) => $r[1] != 0);

        $totalRev    = $revenue->sum(fn ($r) => $r[1]);
        $totalCogs   = $cogs->sum(fn ($r) => $r[1]);
        $grossProfit = $totalRev - $totalCogs;
        $totalExp    = $expenses->sum(fn ($r) => $r[1]);
        $netProfit   = $grossProfit - $totalExp;

        $data = collect([['REVENUE', '']])->concat($revenue);
        $data->push(['Total Revenue', $this->fmt($totalRev)]);
        $data->push(['LESS: COST OF GOODS SOLD', '']);
        $data = $data->concat($cogs);
        $data->push(['GROSS PROFIT', $this->fmt($grossProfit)]);
        $data->push(['OPERATING EXPENSES', '']);
        $data = $data->concat($expenses);
        $data->push(['NET PROFIT / LOSS', $this->fmt($netProfit)]);

        return $data;
    }

    // ── 4. BALANCE SHEET ─────────────────────────────────────────

    private function balanceSheet(string $from, string $to)
    {
        $trial       = $this->trialBalance($to);
        $assets      = collect();
        $liabilities = collect();

        foreach ($trial as $r) {
            $type   = $r[1];
            $debit  = (float) str_replace(',', '', $r[2]);
            $credit = (float) str_replace(',', '', $r[3]);

            if (in_array($type, ['customer', 'receivable', 'cash', 'bank', 'inventory', 'delivery_clearing'])) {
                $val = $debit - $credit;
                if ($val != 0) $assets->push([$r[0], $this->fmt($val)]);
            } elseif (in_array($type, ['vendor', 'payable', 'liability', 'equity'])) {
                $val = $credit - $debit;
                if ($val != 0) $liabilities->push([$r[0], $this->fmt($val)]);
            }
        }

        $pl = $this->profitLoss($from, $to);
        $netProfitRow = $pl->last();
        if ($netProfitRow && $netProfitRow[0] === 'NET PROFIT / LOSS') {
            $liabilities->push(['Retained Earnings (Net Profit — Period)', $netProfitRow[1]]);
        }

        $max  = max($assets->count(), $liabilities->count(), 1);
        $rows = [];
        for ($i = 0; $i < $max; $i++) {
            $rows[] = [$assets[$i][0] ?? '', $assets[$i][1] ?? '', $liabilities[$i][0] ?? '', $liabilities[$i][1] ?? ''];
        }

        return $rows;
    }

    // ── 5. RECEIVABLES ──────────────────────────────────────────

    private function receivables(string $to)
    {
        return ChartOfAccounts::whereIn('account_type', ['customer', 'receivable'])->get()
            ->map(function ($a) use ($to) {
                $bal = $this->getAccountBalance($a->id, null, null, $to);
                return [$a->name, $this->fmt($bal['debit'] - $bal['credit'])];
            })
            ->filter(fn ($r) => (float) str_replace(',', '', $r[1]) > 0)
            ->values();
    }

    // ── 6. PAYABLES ──────────────────────────────────────────────

    private function payables(string $to)
    {
        return ChartOfAccounts::whereIn('account_type', ['vendor', 'payable'])->get()
            ->map(function ($a) use ($to) {
                $bal = $this->getAccountBalance($a->id, null, null, $to);
                return [$a->name, $this->fmt($bal['credit'] - $bal['debit'])];
            })
            ->filter(fn ($r) => (float) str_replace(',', '', $r[1]) > 0)
            ->values();
    }

    // ── 7. TRIAL BALANCE ────────────────────────────────────────

    private function trialBalance(string $to)
    {
        return ChartOfAccounts::all()
            ->map(function ($a) use ($to) {
                $bal = $this->getAccountBalance($a->id, null, null, $to);

                if ($this->isDebitNature($a->account_type)) {
                    $diff = $bal['debit'] - $bal['credit'];
                    [$debit, $credit] = [$diff > 0 ? $diff : 0, $diff < 0 ? abs($diff) : 0];
                } else {
                    $diff = $bal['credit'] - $bal['debit'];
                    [$credit, $debit] = [$diff > 0 ? $diff : 0, $diff < 0 ? abs($diff) : 0];
                }

                return [$a->name, $a->account_type, $this->fmt($debit), $this->fmt($credit)];
            })
            ->filter(fn ($r) => (float) str_replace(',', '', $r[2]) != 0 || (float) str_replace(',', '', $r[3]) != 0);
    }

    // ── 8 & 9. CASH BOOK / BANK BOOK ─────────────────────────────

    private function cashBook(string $from, string $to)
    {
        return $this->bookHelper(ChartOfAccounts::where('account_type', 'cash')->pluck('id'), $from, $to);
    }

    private function bankBook(string $from, string $to)
    {
        return $this->bookHelper(ChartOfAccounts::where('account_type', 'bank')->pluck('id'), $from, $to);
    }

    private function bookHelper($ids, string $from, string $to): Collection
    {
        if ($ids->isEmpty()) return collect();
        $idsArr = $ids->toArray();
        $lines  = collect();

        Voucher::whereNull('reference_type')
            ->whereBetween('voucher_date', [$from, $to])
            ->whereNull('deleted_at')
            ->where(fn ($q) => $q->whereIn('ac_dr_sid', $idsArr)->orWhereIn('ac_cr_sid', $idsArr))
            ->with(['debitAccount', 'creditAccount'])
            ->get()
            ->each(function ($v) use ($idsArr, &$lines) {
                $lines->push([
                    'sort' => $v->voucher_date->format('Ymd') . str_pad($v->id, 10, '0', STR_PAD_LEFT),
                    'date' => $v->voucher_date->format('Y-m-d'),
                    'dr_account' => $v->debitAccount->name ?? '—',
                    'cr_account' => $v->creditAccount->name ?? '—',
                    'narration' => $v->remarks ?? '',
                    'reference_label' => $v->voucher_no,
                    'reference_link' => route('vouchers.print', ['type' => $v->voucher_type, 'id' => $v->id]),
                    'dr' => in_array($v->ac_dr_sid, $idsArr) ? (float) $v->amount : 0.0,
                    'cr' => in_array($v->ac_cr_sid, $idsArr) ? (float) $v->amount : 0.0,
                ]);
            });

        AccountingEntry::whereIn('account_id', $idsArr)
            ->whereHas('voucher', fn ($q) => $q->whereBetween('voucher_date', [$from, $to])->whereNull('deleted_at'))
            ->with(['voucher', 'account'])
            ->get()
            ->each(function ($entry) use (&$lines) {
                $v = $entry->voucher;
                $lines->push([
                    'sort' => optional($v->voucher_date)->format('Ymd') . str_pad($v->id, 10, '0', STR_PAD_LEFT),
                    'date' => optional($v->voucher_date)->format('Y-m-d') ?? '',
                    'dr_account' => $entry->debit > 0 ? ($entry->account->name ?? '—') : '—',
                    'cr_account' => $entry->credit > 0 ? ($entry->account->name ?? '—') : '—',
                    'narration' => $entry->narration ?? $v->remarks ?? '',
                    'reference_label' => $v->reference_label ?? $v->voucher_no,
                    'reference_link' => $v->reference_link ?? route('vouchers.print', ['type' => $v->voucher_type, 'id' => $v->id]),
                    'dr' => (float) $entry->debit,
                    'cr' => (float) $entry->credit,
                ]);
            });

        $bal = 0.0;
        return $lines->sortBy('sort')->values()->map(function ($l) use (&$bal) {
            $bal += ($l['dr'] - $l['cr']);
            return [
                'date' => $l['date'], 'dr_account' => $l['dr_account'], 'cr_account' => $l['cr_account'],
                'narration' => $l['narration'], 'reference_label' => $l['reference_label'], 'reference_link' => $l['reference_link'],
                'dr' => $l['dr'] > 0.001 ? $this->fmt($l['dr']) : '',
                'cr' => $l['cr'] > 0.001 ? $this->fmt($l['cr']) : '',
                'balance' => $this->fmt($bal),
            ];
        });
    }

    // ── 10. JOURNAL / DAY BOOK (manual vouchers only) ────────────

    private function journalBook(string $from, string $to)
    {
        return Voucher::with(['debitAccount', 'creditAccount'])
            ->whereBetween('voucher_date', [$from, $to])
            ->whereNull('deleted_at')
            ->whereNull('reference_type') // manual entries only — system-generated vouchers are viewed via their source document
            ->orderBy('voucher_date')
            ->get()
            ->map(fn ($v) => [
                'date' => $v->voucher_date->format('Y-m-d'),
                'voucher_no' => $v->voucher_no,
                'voucher_id' => $v->id,
                'voucher_type' => $v->voucher_type,
                'dr_account' => $v->debitAccount->name ?? 'N/A',
                'cr_account' => $v->creditAccount->name ?? 'N/A',
                'narration' => $v->remarks ?? '',
                'amount' => $this->fmt($v->amount),
            ]);
    }

    // ── 11. EXPENSE ANALYSIS ─────────────────────────────────────

    private function expenseAnalysis(string $from, string $to)
    {
        return ChartOfAccounts::where('account_type', 'expenses')->get()
            ->map(function ($a) use ($from, $to) {
                $bal = $this->getAccountBalance($a->id, $from, $to);
                return [$a->name, $this->fmt($bal['debit'] - $bal['credit'])];
            })
            ->filter(fn ($r) => (float) str_replace(',', '', $r[1]) != 0);
    }

    // ── 12. CASH FLOW ─────────────────────────────────────────────

    private function cashFlow(string $from, string $to)
    {
        $ids = ChartOfAccounts::whereIn('account_type', ['cash', 'bank'])->pluck('id')->toArray();
        if (empty($ids)) {
            return [['Total Cash Inflow (Receipts)', '0.00'], ['Total Cash Outflow (Payments)', '0.00'], ['Net Increase / Decrease in Cash', '0.00']];
        }

        $simpleIn  = (float) Voucher::whereNull('reference_type')->whereIn('ac_dr_sid', $ids)->whereBetween('voucher_date', [$from, $to])->whereNull('deleted_at')->sum('amount');
        $simpleOut = (float) Voucher::whereNull('reference_type')->whereIn('ac_cr_sid', $ids)->whereBetween('voucher_date', [$from, $to])->whereNull('deleted_at')->sum('amount');

        $complexIn  = (float) AccountingEntry::whereIn('account_id', $ids)->whereHas('voucher', fn ($q) => $q->whereBetween('voucher_date', [$from, $to])->whereNull('deleted_at'))->sum('debit');
        $complexOut = (float) AccountingEntry::whereIn('account_id', $ids)->whereHas('voucher', fn ($q) => $q->whereBetween('voucher_date', [$from, $to])->whereNull('deleted_at'))->sum('credit');

        $inflow  = $simpleIn + $complexIn;
        $outflow = $simpleOut + $complexOut;

        return [
            ['Total Cash Inflow (Receipts)', $this->fmt($inflow)],
            ['Total Cash Outflow (Payments)', $this->fmt($outflow)],
            ['Net Increase / Decrease in Cash', $this->fmt($inflow - $outflow)],
        ];
    }
}