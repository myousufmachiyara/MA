<?php

namespace App\Traits;

trait HasPdfCompanyHeader
{
    /**
     * Draws the logo + full company details at the top-left, and a
     * document title on the right. Call this right after AddPage().
     */
    private function addCompanyHeader($pdf, string $documentTitle)
    {
        $logoPath = public_path('assets/img/logo.png');
        if (file_exists($logoPath)) {
            $pdf->Image($logoPath, 15, 10, 28);
        }

        $pdf->SetFont('helvetica', 'B', 13);
        $pdf->SetXY(48, 10);
        $pdf->Cell(0, 5, 'M.A Distributors', 0, 1, 'L');

        $pdf->SetFont('helvetica', '', 8);
        $pdf->SetXY(48, 16);
        $pdf->MultiCell(95, 4, "AK-480, Sector 6-C, Mehran Town,\nKorangi Industrial Area, Karachi", 0, 'L');

        $pdf->SetXY(48, 24);
        $pdf->Cell(0, 4, 'Contact: +92 333 3589887', 0, 1, 'L');

        $pdf->SetFont('helvetica', 'B', 15);
        $pdf->SetXY(110, 12);
        $pdf->Cell(85, 8, $documentTitle, 0, 1, 'R');

        $pdf->SetY(32);
        $pdf->SetFont('helvetica', '', 10);
    }
}