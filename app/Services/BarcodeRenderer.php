<?php

namespace App\Services;

use TCPDF;
use Illuminate\Support\Facades\File;

class BarcodeRenderer
{
    // Target output: 460x300 px @ 300dpi  ->  38.946666... mm x 25.4 mm
    private const DPI   = 300;
    private const W_MM  = (460 / self::DPI) * 25.4; // 38.946666...
    private const H_MM  = (300 / self::DPI) * 25.4; // 25.4

    // Layout tuning (adjust in 0.1–0.2 mm steps if you need pixel-perfect matching)
    private const PAD_X_MM            = 2.6; // left/right quiet zone (inside page edges)
    private const PAD_TOP_MM          = 1.0; // space above bars
    private const TEXT_H_MM           = 6.3; // reserved height for digits
    private const BASELINE_OFFSET_MM  = 0.4; // small gap between bars and digits
    private const SIDE_BOX_MM         = 4.0; // space for the outer single digit(s)

    // Digit font sizing
    private const NUM_SIZE_PT = 16;         // OCR-B size for human-readable text

    // --- Helpers -------------------------------------------------------------

    /** Build 12-digit UPC-A from 11-digit base */
    public function makeUpc12(string $base11): string
    {
        if (!preg_match('/^\d{11}$/', $base11)) {
            throw new \InvalidArgumentException('UPC base must be exactly 11 digits.');
        }
        $d = array_map('intval', str_split($base11));
        $sumOdd  = $d[0]+$d[2]+$d[4]+$d[6]+$d[8]+$d[10];
        $sumEven = $d[1]+$d[3]+$d[5]+$d[7]+$d[9];
        $check = (10 - ((($sumOdd * 3) + $sumEven) % 10)) % 10;
        return $base11 . $check;
    }

    /** EAN-13 check digit for 12 digits */
    private function ean13CheckDigit(string $data12): int
    {
        if (!preg_match('/^\d{12}$/', $data12)) {
            throw new \InvalidArgumentException('EAN-13 needs 12 digits to compute the check.');
        }
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $digit = (int) $data12[$i];
            $sum += ($i % 2 === 0) ? $digit : 3 * $digit; // weights 1,3,1,3...
        }
        return (10 - ($sum % 10)) % 10;
    }

    /** Convert UPC-A (12 digits) to EAN-13 by prefixing 0 and recomputing check */
    public function makeEan13FromUpc(string $upc12): string
    {
        if (!preg_match('/^\d{12}$/', $upc12)) {
            throw new \InvalidArgumentException('UPC-A must be 12 digits to convert to EAN-13.');
        }
        $ean12 = '0' . substr($upc12, 0, 11);
        return $ean12 . $this->ean13CheckDigit($ean12);
    }

    // --- Rendering -----------------------------------------------------------

    /**
     * Render a single barcode PDF at 460x300 px @ 300dpi (exact page size in mm).
     *
     * @param  string $absPath Absolute output path (e.g., Storage::path('.../UPC-12-XXXXXXXXXXXX.pdf'))
     * @param  string $code    12-digit UPC-A or 13-digit EAN-13 (already including checksum)
     * @param  string $sym     'UPCA' or 'EAN13'
     */
    public function renderPdfBarcode(string $absPath, string $code, string $sym): void
    {
        // Page setup
        $pdf = new TCPDF('P', 'mm', [self::W_MM, self::H_MM], true, 'UTF-8', false);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(0, 0, 0);
        $pdf->SetAutoPageBreak(false, 0);
        $pdf->AddPage();

        // Ensure a writable TCPDF font dir exists and register OCRB if available
        $fontKey = 'helvetica'; // fallback
        if (!defined('K_PATH_FONTS')) {
            define('K_PATH_FONTS', storage_path('tcpdf-fonts') . '/');
        }
        File::ensureDirectoryExists(storage_path('tcpdf-fonts'));

        $ttf = resource_path('fonts/OCRB.ttf');
        if (is_readable($ttf)) {
            // addTTFfont returns the font key (e.g. "ocrb") or false if already present or on error
            $added = \TCPDF_FONTS::addTTFfont($ttf, 'TrueTypeUnicode', '', 32);
            if (is_string($added) && $added !== '') {
                $fontKey = $added;
            } elseif (file_exists(K_PATH_FONTS . 'ocrb.php')) {
                $fontKey = 'ocrb';
            }
        }

        // Compute bar area
        $barW = self::W_MM - 2 * self::PAD_X_MM;
        $barH = self::H_MM - self::PAD_TOP_MM - self::TEXT_H_MM;

        // Draw bars only (no auto text)
        $style = [
            'position'    => '',
            'align'       => 'C',
            'stretch'     => false,
            'fitwidth'    => true,   // scale modules to fill $barW precisely
            'border'      => false,
            'padding'     => 0,
            'fgcolor'     => [0, 0, 0],
            'bgcolor'     => false,
            'text'        => false,  // <- important: we draw digits ourselves
            'stretchtext' => 0,
        ];

        // xres (module width) is ignored when fitwidth=true; pass 0 for clarity
        $pdf->write1DBarcode($code, $sym, self::PAD_X_MM, self::PAD_TOP_MM, $barW, $barH, 0, $style, 'C');

        // Human-readable digits (OCR-B)
        $pdf->SetFont($fontKey, '', self::NUM_SIZE_PT);
        $baselineY = self::PAD_TOP_MM + $barH + self::BASELINE_OFFSET_MM;
        $halfW     = $barW / 2;

        if ($sym === 'UPCA' || (strlen($code) === 12 && $sym !== 'EAN13')) {
            // Layout: 1 23456 78910 4
            $lead  = substr($code, 0, 1);
            $left  = substr($code, 1, 5);
            $right = substr($code, 6, 5);
            $chk   = substr($code, 11, 1);

            // Left single digit (outside bars)
            $pdf->SetXY(self::PAD_X_MM - self::SIDE_BOX_MM, $baselineY);
            $pdf->Cell(self::SIDE_BOX_MM, self::TEXT_H_MM, $lead, 0, 0, 'L');

            // Left group centered under left half of bars
            $pdf->SetXY(self::PAD_X_MM, $baselineY);
            $pdf->Cell($halfW, self::TEXT_H_MM, $left, 0, 0, 'C');

            // Right group centered under right half
            $pdf->SetXY(self::PAD_X_MM + $halfW, $baselineY);
            $pdf->Cell($halfW, self::TEXT_H_MM, $right, 0, 0, 'C');

            // Right checksum digit (outside bars)
            $pdf->SetXY(self::PAD_X_MM + $barW, $baselineY);
            $pdf->Cell(self::SIDE_BOX_MM, self::TEXT_H_MM, $chk, 0, 0, 'R');

        } elseif ($sym === 'EAN13' || strlen($code) === 13) {
            // Layout: 1 234567 890123
            $lead   = substr($code, 0, 1);
            $left6  = substr($code, 1, 6);
            $right6 = substr($code, 7, 6);

            // Left single digit (outside bars)
            $pdf->SetXY(self::PAD_X_MM - self::SIDE_BOX_MM, $baselineY);
            $pdf->Cell(self::SIDE_BOX_MM, self::TEXT_H_MM, $lead, 0, 0, 'L');

            // Left 6 centered under left half
            $pdf->SetXY(self::PAD_X_MM, $baselineY);
            $pdf->Cell($halfW, self::TEXT_H_MM, $left6, 0, 0, 'C');

            // Right 6 centered under right half
            $pdf->SetXY(self::PAD_X_MM + $halfW, $baselineY);
            $pdf->Cell($halfW, self::TEXT_H_MM, $right6, 0, 0, 'C');
        }

        $pdf->Output($absPath, 'F');
    }
}
