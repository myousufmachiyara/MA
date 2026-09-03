<?php

namespace App\Services;

use App\Models\SaleInvoice;
use Carbon\Carbon;

class ThermalReceiptService
{
    // Safe default character width for 80mm thermal printers at normal font size
    private const LINE_WIDTH = 42;

    public static function buildSaleInvoiceReceipt(SaleInvoice $invoice): string
    {
        $esc = chr(0x1B);
        $gs  = chr(0x1D);

        $out  = $esc . '@'; // initialize printer

        // ── Company header ──
        $out .= $esc . 'a' . chr(1); // center align
        $out .= $esc . 'E' . chr(1); // bold on
        $out .= "M.A DISTRIBUTORS\n";
        $out .= $esc . 'E' . chr(0); // bold off
        $out .= "AK-480, Sector 6-C, Mehran Town,\n";
        $out .= "Korangi Industrial Area, Karachi\n";
        $out .= "Contact: +92 333 3589887\n";
        $out .= str_repeat('-', self::LINE_WIDTH) . "\n";

        // ── Invoice meta ──
        $out .= $esc . 'a' . chr(0); // left align
        $out .= "Invoice #: SI-{$invoice->invoice_no}\n";
        $out .= "Date: " . Carbon::parse($invoice->invoice_date)->format('d-M-Y h:i A') . "\n";
        $out .= "Customer: " . ($invoice->customer->name ?? 'N/A') . "\n";
        $out .= "Terms: " . ucfirst($invoice->payment_terms) . "\n";
        $out .= str_repeat('-', self::LINE_WIDTH) . "\n";

        // ── Items ──
        $out .= self::formatRow('Item', 'Qty', 'Amount');
        $out .= str_repeat('-', self::LINE_WIDTH) . "\n";

        $totalQty = 0;
        foreach ($invoice->items as $item) {
            $name = $item->product->name ?? 'Item';
            if ($item->variation) {
                $name .= ' (' . $item->variation->sku . ')';
            }
            $lineTotal = $item->quantity * $item->price;
            $totalQty += $item->quantity;
            $out .= self::formatItemLine($name, $item->quantity, $item->price, $lineTotal);
        }

        $out .= str_repeat('-', self::LINE_WIDTH) . "\n";
        $out .= self::formatRightLine('Total Qty', number_format($totalQty, 0));

        // ── Totals ──
        $out .= self::formatRightLine('Net Amount', number_format($invoice->net_amount, 2));
        if ($invoice->is_tax_invoice) {
            $out .= self::formatRightLine("GST ({$invoice->gst_rate}%)", number_format($invoice->gst_amount, 2));
        }
        $out .= $esc . 'E' . chr(1);
        $out .= self::formatRightLine('TOTAL', number_format($invoice->total_amount, 2));
        $out .= $esc . 'E' . chr(0);

        if ($invoice->wht_applicable) {
            $out .= self::formatRightLine("WHT ({$invoice->wht_rate}%)", number_format($invoice->wht_amount, 2));
            $out .= "(WHT deducted at payment)\n";
        }

        // ── Footer ──
        $out .= str_repeat('-', self::LINE_WIDTH) . "\n";
        $out .= $esc . 'a' . chr(1);
        $out .= "Thank you for your business!\n";
        $out .= "\n\n\n";

        // Cut paper (partial cut)
        $out .= $gs . 'V' . chr(1);

        return $out;
    }

    private static function formatRow(string $col1, string $col2, string $col3): string
    {
        return str_pad($col1, 22) . str_pad($col2, 8, ' ', STR_PAD_LEFT) . str_pad($col3, 12, ' ', STR_PAD_LEFT) . "\n";
    }

    private static function formatItemLine(string $name, float $qty, float $price, float $lineTotal): string
    {
        // Wrap long product names onto their own line, qty/amount on the line below
        $line = '';
        if (strlen($name) > self::LINE_WIDTH) {
            $line .= wordwrap($name, self::LINE_WIDTH, "\n", true) . "\n";
        } else {
            $line .= $name . "\n";
        }
        $line .= str_pad("  {$qty} x " . number_format($price, 2), 30) . str_pad(number_format($lineTotal, 2), 12, ' ', STR_PAD_LEFT) . "\n";
        return $line;
    }

    private static function formatRightLine(string $label, string $value): string
    {
        return str_pad($label, self::LINE_WIDTH - 12) . str_pad($value, 12, ' ', STR_PAD_LEFT) . "\n";
    }
}