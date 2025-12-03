<?php

namespace App\Services;

class Ean13EpsRenderer
{
    /**
     * Create an EPS file that embeds the given JPG.
     * Uses Ghostscript eps2write when available; otherwise a PS Level 2 fallback
     * using /DCTDecode (JPEG) with a proper BoundingBox.
     *
     * @param string $jpgAbs absolute path to source JPG
     * @param string $epsAbs absolute path to output EPS
     * @param int    $dpi    assumed pixels-per-inch for BoundingBox (default 300)
     */
    public function jpgToEps(string $jpgAbs, string $epsAbs, int $dpi = 300): void
    {
        if (!is_readable($jpgAbs)) {
            throw new \RuntimeException("JPG not readable: {$jpgAbs}");
        }
        @mkdir(dirname($epsAbs), 0775, true);

        $gs = trim(shell_exec('which gs') ?? '');
        if ($gs !== '') {
            $cmd = escapeshellcmd($gs)
                . ' -dSAFER -dBATCH -dNOPAUSE -r' . (int)$dpi
                . ' -sDEVICE=eps2write '
                . ' -sOutputFile=' . escapeshellarg($epsAbs) . ' '
                . escapeshellarg($jpgAbs) . ' 2>&1';
            exec($cmd, $out, $code);
            if ($code === 0 && file_exists($epsAbs) && filesize($epsAbs) > 0) {
                return;
            }
            // fall through to pure PostScript fallback
        }

        [$w, $h] = @getimagesize($jpgAbs) ?: [0, 0];
        if (!$w || !$h) {
            throw new \RuntimeException("Could not read image size for: {$jpgAbs}");
        }
        $img = file_get_contents($jpgAbs);
        $len = strlen($img);

        $bbW = (int)round($w * 72 / max(1, $dpi));
        $bbH = (int)round($h * 72 / max(1, $dpi));

        // Minimal EPS with JPEG via DCTDecode (binary data section)
        $eps  = "%!PS-Adobe-3.0 EPSF-3.0\n";
        $eps .= "%%BoundingBox: 0 0 {$bbW} {$bbH}\n";
        $eps .= "%%LanguageLevel: 2\n";
        $eps .= "%%Pages: 1\n";
        $eps .= "%%EndComments\n";
        $eps .= "/w {$w} def /h {$h} def /d 8 def\n";
        $eps .= "gsave\n";
        $eps .= "{$bbW} {$bbH} scale\n";
        $eps .= "<< /ImageType 1 /Width w /Height h /BitsPerComponent d\n";
        $eps .= "   /Decode [0 1] /ImageMatrix [w 0 0 -h 0 h]\n";
        $eps .= "   /DataSource currentfile /DCTDecode filter\n";
        $eps .= "   /ColorSpace /DeviceRGB >> image\n";
        $eps .= "%%BeginData: {$len} Binary\n";
        $eps .= $img;
        $eps .= "\n%%EndData\n";
        $eps .= "grestore\n";
        $eps .= "showpage\n";
        $eps .= "%%EOF\n";

        file_put_contents($epsAbs, $eps);
        if (!file_exists($epsAbs) || filesize($epsAbs) === 0) {
            throw new \RuntimeException("Failed to write EPS: {$epsAbs}");
        }
    }
}
