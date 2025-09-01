<?php

namespace App\Services;

/**
 * Minimal JPEG → single-page PDF (page sized to image at $dpi).
 * Embeds the JPEG stream with /DCTDecode as an Image XObject.
 */
class UpcPdfRenderer
{
    public function jpgToPdf(string $jpgAbs, string $pdfAbs, int $dpi = 300): void
    {
        if (!is_readable($jpgAbs)) {
            throw new \RuntimeException("JPG not readable: {$jpgAbs}");
        }

        $info = @getimagesize($jpgAbs);
        if (!$info || empty($info[0]) || empty($info[1]) || ($info[2] ?? null) !== IMAGETYPE_JPEG) {
            throw new \RuntimeException("Invalid JPEG: {$jpgAbs}");
        }
        [$wPx, $hPx] = [$info[0], $info[1]];
        $wPt = ($wPx / $dpi) * 72.0;
        $hPt = ($hPx / $dpi) * 72.0;

        $jpeg = file_get_contents($jpgAbs);
        if ($jpeg === false) {
            throw new \RuntimeException("Failed to read JPEG: {$jpgAbs}");
        }
        $imgLen = strlen($jpeg);

        // Build a tiny PDF
        $pdf  = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $ofs = [];

        // 1 Catalog
        $ofs[1] = strlen($pdf);
        $pdf .= "1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n";

        // 2 Pages
        $ofs[2] = strlen($pdf);
        $pdf .= "2 0 obj << /Type /Pages /Count 1 /Kids [3 0 R] >> endobj\n";

        // 3 Page
        $ofs[3] = strlen($pdf);
        $pdf .= sprintf(
            "3 0 obj << /Type /Page /Parent 2 0 R /MediaBox [0 0 %.3F %.3F] ".
            "/Resources << /XObject << /Im0 4 0 R >> >> /Contents 5 0 R >> endobj\n",
            $wPt, $hPt
        );

        // 4 Image XObject
        $ofs[4] = strlen($pdf);
        $pdf .= "4 0 obj << /Type /XObject /Subtype /Image ".
            "/Width {$wPx} /Height {$hPx} /ColorSpace /DeviceRGB ".
            "/BitsPerComponent 8 /Filter /DCTDecode /Length {$imgLen} >>\nstream\n";
        $pdf .= $jpeg . "\nendstream\nendobj\n";

        // 5 Content stream (paint image full-page)
        $content = sprintf("q %.3F 0 0 %.3F 0 0 cm /Im0 Do Q\n", $wPt, $hPt);
        $ofs[5] = strlen($pdf);
        $pdf .= "5 0 obj << /Length " . strlen($content) . " >>\nstream\n";
        $pdf .= $content . "endstream\nendobj\n";

        // xref
        $xrefPos = strlen($pdf);
        $pdf .= "xref\n0 6\n0000000000 65535 f \n";
        for ($i = 1; $i <= 5; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $ofs[$i]);
        }

        // trailer
        $pdf .= "trailer << /Size 6 /Root 1 0 R >>\nstartxref\n{$xrefPos}\n%%EOF\n";

        if (file_put_contents($pdfAbs, $pdf) === false) {
            throw new \RuntimeException("Failed to write PDF: {$pdfAbs}");
        }
    }
}
