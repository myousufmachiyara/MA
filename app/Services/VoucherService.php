<?php

namespace App\Services;

use App\Models\Voucher;
use App\Models\AccountingEntry;
use Illuminate\Support\Facades\DB;

class VoucherService
{
    /**
     * Manual vouchers ONLY (Journal/Payment/Receipt screens) — flat single
     * dr/cr pair, reference_type always null. Reports read these via the
     * "simple" branch.
     */
    public static function post(array $data): Voucher
    {
        $voucherNo = self::nextVoucherNo();

        return Voucher::create([
            'voucher_no'   => $voucherNo,
            'voucher_type' => $data['voucher_type'],
            'voucher_date' => $data['voucher_date'],
            'ac_dr_sid'    => $data['ac_dr_sid'],
            'ac_cr_sid'    => $data['ac_cr_sid'],
            'amount'       => $data['amount'],
            'remarks'      => $data['remarks'] ?? null,
            'created_by'   => auth()->id(),
        ]);
    }

    /**
     * System-generated vouchers tied to a source document — ALWAYS use this,
     * even for a simple 2-leg entry. Creates one voucher header + N
     * accounting_entries lines. Reports read these via the "complex" branch.
     *
     * $header: voucher_type, voucher_date, reference_type, reference_id, remarks
     * $lines: [['account_id' => .., 'debit' => 0, 'credit' => 0, 'narration' => '..'], ...]
     */
    public static function postEntries(array $header, array $lines): Voucher
    {
        $lines = array_values(array_filter($lines, fn ($l) => ($l['debit'] ?? 0) > 0 || ($l['credit'] ?? 0) > 0));

        $totalDr = round(array_sum(array_column($lines, 'debit')), 2);
        $totalCr = round(array_sum(array_column($lines, 'credit')), 2);

        if (abs($totalDr - $totalCr) > 0.01) {
            throw new \Exception("Unbalanced voucher: debit {$totalDr} != credit {$totalCr} for {$header['voucher_type']} reference {$header['reference_id']}.");
        }

        return DB::transaction(function () use ($header, $lines) {
            $voucher = Voucher::create([
                'voucher_no'     => self::nextVoucherNo(),
                'voucher_type'   => $header['voucher_type'],
                'voucher_date'   => $header['voucher_date'],
                'reference_type' => $header['reference_type'],
                'reference_id'   => $header['reference_id'],
                'remarks'        => $header['remarks'] ?? null,
                'created_by'     => auth()->id(),
            ]);

            foreach ($lines as $line) {
                AccountingEntry::create([
                    'voucher_id' => $voucher->id,
                    'account_id' => $line['account_id'],
                    'debit'      => $line['debit'] ?? 0,
                    'credit'     => $line['credit'] ?? 0,
                    'narration'  => $line['narration'] ?? null,
                ]);
            }

            return $voucher;
        });
    }

    /**
     * Used by every module's update() — replaces the entries on the existing
     * voucher for that source document instead of creating a duplicate.
     */
    public static function postOrUpdateEntries(string $referenceType, int $referenceId, string $voucherType, array $header, array $lines): Voucher
    {
        $existing = Voucher::where('reference_type', $referenceType)
            ->where('reference_id', $referenceId)
            ->where('voucher_type', $voucherType)
            ->first();

        if (!$existing) {
            return self::postEntries(array_merge($header, [
                'voucher_type' => $voucherType, 'reference_type' => $referenceType, 'reference_id' => $referenceId,
            ]), $lines);
        }

        $lines = array_values(array_filter($lines, fn ($l) => ($l['debit'] ?? 0) > 0 || ($l['credit'] ?? 0) > 0));
        $totalDr = round(array_sum(array_column($lines, 'debit')), 2);
        $totalCr = round(array_sum(array_column($lines, 'credit')), 2);

        if (abs($totalDr - $totalCr) > 0.01) {
            throw new \Exception("Unbalanced voucher update: debit {$totalDr} != credit {$totalCr}.");
        }

        return DB::transaction(function () use ($existing, $header, $lines) {
            $existing->update(['voucher_date' => $header['voucher_date'], 'remarks' => $header['remarks'] ?? null]);
            $existing->entries()->delete();

            foreach ($lines as $line) {
                AccountingEntry::create([
                    'voucher_id' => $existing->id,
                    'account_id' => $line['account_id'],
                    'debit'      => $line['debit'] ?? 0,
                    'credit'     => $line['credit'] ?? 0,
                    'narration'  => $line['narration'] ?? null,
                ]);
            }

            return $existing;
        });
    }

    private static function nextVoucherNo(): string
    {
        $last    = Voucher::withTrashed()->lockForUpdate()->orderByDesc('id')->first();
        $nextNum = $last ? ((int) preg_replace('/\D/', '', $last->voucher_no)) + 1 : 1;
        return 'V-' . str_pad($nextNum, 6, '0', STR_PAD_LEFT);
    }
}