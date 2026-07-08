<?php

namespace App\Http\Controllers;

use App\Models\SaleInvoice;
use Illuminate\Http\Request;
use Carbon\Carbon;

class SaleInvoiceController extends Controller
{
    public function index(Request $request)
    {
        $query = SaleInvoice::with(['customer', 'dispatchTrip']);

        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }

        $invoices = $query->latest()->get();

        return view('sale_invoices.index', compact('invoices'));
    }

    public function show($id)
    {
        $invoice = SaleInvoice::with(['items.product', 'items.variation', 'customer', 'dispatchTrip'])->findOrFail($id);
        return view('sale_invoices.show', compact('invoice'));
    }

    public function print($id)
    {
        $invoice = SaleInvoice::with(['items.product', 'items.variation', 'customer'])->findOrFail($id);

        $pdf = new \TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        $pdf->SetCreator('BillTrix');
        $pdf->SetTitle('SI-' . $invoice->invoice_no);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(15, 15, 15);
        $pdf->SetAutoPageBreak(true, 20);
        $pdf->AddPage();

        $pdf->SetFont('helvetica', 'B', 16);
        $pdf->Cell(0, 10, 'SALE INVOICE' . ($invoice->is_tax_invoice ? ' (TAX INVOICE)' : ''), 0, 1, 'R');
        $pdf->SetFont('helvetica', '', 10);
        $pdf->Cell(0, 5, 'Invoice #: ' . $invoice->invoice_no, 0, 1, 'R');
        $pdf->Cell(0, 5, 'Date: ' . Carbon::parse($invoice->invoice_date)->format('d-M-Y'), 0, 1, 'R');
        $pdf->Ln(5);

        $html = '<table width="50%" border="1" cellpadding="3" style="font-size:10px;">
            <tr><td width="40%"><b>Customer:</b></td><td>' . e($invoice->customer->name ?? 'N/A') . '</td></tr>
            <tr><td><b>Payment Terms:</b></td><td>' . ucfirst($invoice->payment_terms) . '</td></tr>
        </table>';
        $pdf->writeHTML($html, true, false, false, false, '');
        $pdf->Ln(5);

        $html = '<table border="1" cellpadding="5" style="font-size:10px;">
            <thead><tr style="background-color:#f2f2f2;font-weight:bold;text-align:center;">
                <th width="5%">#</th><th width="35%">Item</th><th width="15%">Variation</th>
                <th width="10%">Qty</th><th width="15%">Price</th><th width="20%">Total</th>
            </tr></thead><tbody>';

        foreach ($invoice->items as $i => $item) {
            $lineTotal = $item->quantity * $item->price;
            $html .= '<tr>
                <td style="text-align:center;">' . ($i + 1) . '</td>
                <td>' . e($item->product->name ?? '-') . '</td>
                <td style="text-align:center;">' . e($item->variation->sku ?? '-') . '</td>
                <td style="text-align:center;">' . number_format($item->quantity, 2) . '</td>
                <td style="text-align:right;">' . number_format($item->price, 2) . '</td>
                <td style="text-align:right;">' . number_format($lineTotal, 2) . '</td>
            </tr>';
        }

        $html .= '<tr><td colspan="5" style="text-align:right;">Net Amount</td><td style="text-align:right;">' . number_format($invoice->net_amount, 2) . '</td></tr>';
        if ($invoice->is_tax_invoice) {
            $html .= '<tr><td colspan="5" style="text-align:right;">GST (' . $invoice->gst_rate . '%)</td><td style="text-align:right;">' . number_format($invoice->gst_amount, 2) . '</td></tr>';
        }
        $html .= '<tr style="font-weight:bold;"><td colspan="5" style="text-align:right;">Total Amount</td><td style="text-align:right;">' . number_format($invoice->total_amount, 2) . '</td></tr>';
        if ($invoice->wht_applicable) {
            $html .= '<tr><td colspan="5" style="text-align:right;">WHT (' . $invoice->wht_rate . '%) — deducted at payment</td><td style="text-align:right;">' . number_format($invoice->wht_amount, 2) . '</td></tr>';
        }
        $html .= '</tbody></table>';

        $pdf->writeHTML($html, true, false, false, false, '');

        return $pdf->Output('SI_' . $invoice->invoice_no . '.pdf', 'I');
    }
}