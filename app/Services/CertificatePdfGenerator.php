<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use TCPDF;

class CertificatePdfGenerator
{
    /**
     * Generate a certificate of authenticity PDF.
     *
     * @param string $pdfAbs Absolute path to save the PDF
     * @param string $name Name to appear on certificate
     * @param string $orderNo Order number
     * @param array $barcodes Array of barcode codes
     */
    public function generate(string $pdfAbs, string $name, string $orderNo, array $barcodes = []): void
    {
        @mkdir(dirname($pdfAbs), 0775, true);

        $pdf = new TCPDF('P', 'mm', 'LETTER', true, 'UTF-8', false);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(20, 20, 20);
        $pdf->SetAutoPageBreak(true, 20);
        $pdf->AddPage();

        // Title
        $pdf->SetFont('helvetica', 'B', 24);
        $pdf->Cell(0, 15, 'CERTIFICATE OF AUTHENTICITY', 0, 1, 'C');
        $pdf->Ln(10);

        // Body text
        $pdf->SetFont('helvetica', '', 12);
        $pdf->MultiCell(0, 6, 'This is to certify that the following barcode numbers are authentic and have been registered in the GS1 US database:', 0, 'L');
        $pdf->Ln(5);

        // Barcode numbers
        if (!empty($barcodes)) {
            $pdf->SetFont('helvetica', 'B', 11);
            foreach ($barcodes as $barcode) {
                $code = is_array($barcode) ? ($barcode['code'] ?? '') : (string)$barcode;
                if ($code) {
                    $pdf->Cell(0, 6, $code, 0, 1, 'L');
                }
            }
        } else {
            $pdf->SetFont('helvetica', 'I', 10);
            $pdf->Cell(0, 6, 'Barcode numbers for order: ' . $orderNo, 0, 1, 'L');
        }
        $pdf->Ln(10);

        // Certification statement
        $pdf->SetFont('helvetica', '', 12);
        $pdf->MultiCell(0, 6, 'These barcodes are guaranteed to be unique and have been issued exclusively to:', 0, 'L');
        $pdf->Ln(5);
        $pdf->SetFont('helvetica', 'B', 14);
        $pdf->Cell(0, 8, $name, 0, 1, 'C');
        $pdf->Ln(10);

        // Company info
        $pdf->SetFont('helvetica', '', 11);
        $pdf->Cell(0, 6, 'Issued by:', 0, 1, 'L');
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(0, 8, 'SPEEDY BARCODES', 0, 1, 'L');
        $pdf->SetFont('helvetica', '', 10);
        $pdf->Cell(0, 5, '1712 Pioneer Ave, Suite 1665', 0, 1, 'L');
        $pdf->Cell(0, 5, 'Cheyenne, WY 82001', 0, 1, 'L');
        $pdf->Cell(0, 5, 'Telephone: (888) 511-0266', 0, 1, 'L');
        $pdf->Cell(0, 5, 'Email: Sales@SpeedyBarcodes.com', 0, 1, 'L');
        $pdf->Ln(10);

        // Date
        $pdf->SetFont('helvetica', '', 10);
        $pdf->Cell(0, 6, 'Date: ' . date('F j, Y'), 0, 1, 'L');
        $pdf->Ln(15);

        // Signature line
        $pdf->SetFont('helvetica', '', 10);
        $pdf->Cell(80, 6, '_________________________', 0, 0, 'L');
        $pdf->Cell(0, 6, 'Order Number: ' . $orderNo, 0, 1, 'R');
        $pdf->Cell(80, 6, 'Authorized Signature', 0, 1, 'L');

        // Ensure directory exists and is writable
        $dir = dirname($pdfAbs);
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0775, true)) {
                throw new \RuntimeException("Failed to create directory: {$dir}");
            }
        }
        if (!is_writable($dir)) {
            throw new \RuntimeException("Directory is not writable: {$dir}");
        }

        // Write PDF with error handling
        try {
            Log::info('CertificatePdfGenerator: about to call Output()', ['path' => $pdfAbs]);
            ob_start();
            $pdf->Output($pdfAbs, 'F');
            $output = ob_get_clean();
            if ($output) {
                Log::warning('CertificatePdfGenerator: TCPDF generated output', ['output' => $output]);
            }
            Log::info('CertificatePdfGenerator: Output() completed', ['path' => $pdfAbs, 'size' => file_exists($pdfAbs) ? filesize($pdfAbs) : 0]);
        } catch (\Throwable $e) {
            if (file_exists($pdfAbs)) {
                @unlink($pdfAbs);
            }
            throw new \RuntimeException("Failed to write certificate PDF: " . $e->getMessage(), 0, $e);
        }
        
        // Verify file was created
        if (!file_exists($pdfAbs) || filesize($pdfAbs) === 0) {
            if (file_exists($pdfAbs)) {
                @unlink($pdfAbs);
            }
            throw new \RuntimeException("Certificate PDF was not created or is empty: {$pdfAbs}");
        }
    }
}

