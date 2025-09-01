<?php

namespace App\Services;

class UpcEpsRenderer
{
    /**
     * Create EPS from a JPEG by first making a PDF (UpcPdfRenderer) then GS pdf→eps.
     */
    public function jpgToEps(string $jpgAbs, string $epsAbs, int $dpi = 300): void
    {
        if (!is_readable($jpgAbs)) {
            throw new \RuntimeException("JPG not readable: {$jpgAbs}");
        }

        $pdfTmp = tempnam(sys_get_temp_dir(), 'jpg2pdf_') . '.pdf';
        try {
            // 1) JPEG -> PDF in PHP
            app(\App\Services\UpcPdfRenderer::class)->jpgToPdf($jpgAbs, $pdfTmp, $dpi);

            // 2) PDF -> EPS via Ghostscript
            $gs = trim((string) shell_exec('command -v gs'));
            if ($gs === '') {
                throw new \RuntimeException('Ghostscript (gs) not found on PATH.');
            }

            $cmd = sprintf(
                '%s -q -dSAFER -dBATCH -dNOPAUSE -sDEVICE=eps2write -dAutoRotatePages=/None ' .
                '-o %s %s 2>&1',
                escapeshellcmd($gs),
                escapeshellarg($epsAbs),
                escapeshellarg($pdfTmp)
            );
            exec($cmd, $out, $code);
            if ($code !== 0 || !is_file($epsAbs) || filesize($epsAbs) === 0) {
                throw new \RuntimeException("gs pdf->eps failed: " . implode("\n", $out));
            }
        } finally {
            @unlink($pdfTmp);
        }
    }
}
