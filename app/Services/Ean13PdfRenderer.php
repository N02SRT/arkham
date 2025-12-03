<?php

namespace App\Services;

class Ean13PdfRenderer
{
    /**
     * Create a PDF that contains the given JPG as a single page.
     * Uses Ghostscript if present; otherwise a compact embedded-JPEG PDF.
     *
     * @param string $jpgAbs absolute path to source JPG
     * @param string $pdfAbs absolute path to output PDF
     * @param int    $dpi    assumed pixels-per-inch for page sizing (default 300)
     */
    public function jpgToPdf(string $jpgAbs, string $pdfAbs, int $dpi = 300): void
    {
        if (!is_readable($jpgAbs)) {
            throw new \RuntimeException("JPG not readable: {$jpgAbs}");
        }
        @mkdir(dirname($pdfAbs), 0775, true);

        // Prefer Ghostscript if available (fast & robust)
        $gs = trim(shell_exec('which gs') ?? '');
        if ($gs !== '') {
            $cmd = escapeshellcmd($gs)
                . ' -dBATCH -dNOPAUSE -dSAFER -sDEVICE=pdfwrite '
                . ' -o ' . escapeshellarg($pdfAbs) . ' ' . escapeshellarg($jpgAbs) . ' 2>&1';
            exec($cmd, $out, $code);
            if ($code === 0 && file_exists($pdfAbs) && filesize($pdfAbs) > 0) {
                return;
            }
            // fall through to pure-PHP if gs failed
        }

        // Pure-PHP minimal PDF (embeds JPEG via DCTDecode)
        [$w, $h] = @getimagesize($jpgAbs) ?: [0, 0];
        if (!$w || !$h) {
            throw new \RuntimeException("Could not read image size for: {$jpgAbs}");
        }
        $img = file_get_contents($jpgAbs);
        $len = strlen($img);

        // page size in points (1/72 inch)
        $ptsW = $w * 72 / max(1, $dpi);
        $ptsH = $h * 72 / max(1, $dpi);

        $objs = [];
        // 1: Catalog
        $objs[] = "1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n";
        // 2: Pages
        $objs[] = "2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj\n";
        // 3: Page
        $objs[] = "3 0 obj << /Type /Page /Parent 2 0 R /MediaBox [0 0 {$ptsW} {$ptsH}] /Resources << /XObject << /Im1 4 0 R >> /ProcSet [/PDF /ImageC] >> /Contents 5 0 R >> endobj\n";
        // 4: Image XObject
        $objs[] = "4 0 obj << /Type /XObject /Subtype /Image /Width {$w} /Height {$h} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length {$len} >> stream\n{$img}\nendstream endobj\n";
        // 5: Contents
        $stream = "q {$ptsW} 0 0 {$ptsH} 0 0 cm /Im1 Do Q";
        $objs[] = "5 0 obj << /Length " . strlen($stream) . " >> stream\n{$stream}\nendstream endobj\n";

        // Assemble with correct xref
        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objs as $o) {
            $offsets[] = strlen($pdf);
            $pdf .= $o;
        }
        $xrefPos = strlen($pdf);
        $count = count($offsets);
        $pdf .= "xref\n0 {$count}\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i < $count; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $pdf .= "trailer << /Root 1 0 R /Size {$count} >>\nstartxref\n{$xrefPos}\n%%EOF";

        file_put_contents($pdfAbs, $pdf);
        if (!file_exists($pdfAbs) || filesize($pdfAbs) === 0) {
            throw new \RuntimeException("Failed to write PDF: {$pdfAbs}");
        }
    }
}
