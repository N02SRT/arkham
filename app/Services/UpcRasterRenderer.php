<?php

namespace App\Services;

/**
 * Raster UPC-A renderer (no TCPDF/GS needed).
 * - Output: 460 x 300 px JPEG, JFIF tagged 300 DPI
 * - Digits: OCR-B (recommended) or any TTF you pass in
 * - Layout: single outer digits inside quiet zones; 5+5 groups centered
 */
class UpcRasterRenderer
{
    // Canvas / resolution
    private const WIDTH   = 460;
    private const HEIGHT  = 300;
    private const DPI     = 300;      // will be embedded in the JPEG's JFIF header

    // Layout (px) — tweak if you want tiny visual adjustments
    private const QUIET_X       = 42; // left/right quiet zone width (also the single-digit boxes)
    private const PAD_TOP       = 12; // top padding before bars
    private const GAP_BARS_TX   = -10;  // gap between bars and digits baseline
    private const TEXT_H        = 72; // block height reserved for digits (visual cap)
    private const FONT_SIZE     = 34; // OCR-B point size for 300px height; adjust 32–36 if needed
    private const JPEG_QUALITY  = 95;


    public function renderUpcJpeg(string $upc12, string $ttfAbsPath): string
    {
        // reuse the existing file-renderer and read the temp file
        $tmp = tempnam(sys_get_temp_dir(), 'upc_') . '.jpg';
        $this->render($upc12, $tmp, $ttfAbsPath);   // existing method that writes to a file
        $buf = file_get_contents($tmp) ?: '';
        @unlink($tmp);
        return $buf;
    }

    /**
     * Build 12-digit UPC-A from 11-digit base.
     */
    public function makeUpc12(string $base11): string
    {
        if (!preg_match('/^\d{11}$/', $base11)) {
            throw new \InvalidArgumentException('UPC base must be 11 digits');
        }
        $d = array_map('intval', str_split($base11));
        $sumOdd  = $d[0]+$d[2]+$d[4]+$d[6]+$d[8]+$d[10];
        $sumEven = $d[1]+$d[3]+$d[5]+$d[7]+$d[9];
        $check   = (10 - ((($sumOdd * 3) + $sumEven) % 10)) % 10;
        return $base11 . $check;
    }

    /**
     * Render a UPC-A to a JPEG file (460x300 px, 300 DPI).
     *
     * @param string $upc12       Exactly 12 digits (with check digit)
     * @param string $outAbsPath  Absolute path to write the JPEG
     * @param string $ttfAbsPath  Absolute path to OCR-B (or any TTF) for digits
     */
    public function render(string $upc12, string $outAbsPath, string $ttfAbsPath): void
    {
        if (!preg_match('/^\d{12}$/', $upc12)) {
            throw new \InvalidArgumentException('UPC-A must be exactly 12 digits');
        }
        if (!is_readable($ttfAbsPath)) {
            throw new \RuntimeException("TTF not readable: {$ttfAbsPath}");
        }

        // --- canvas ---------------------------------------------------------
        $im = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        imagealphablending($im, true);
        imagesavealpha($im, false);
        $white = imagecolorallocate($im, 255, 255, 255);
        $black = imagecolorallocate($im, 0, 0, 0);
        imagefilledrectangle($im, 0, 0, self::WIDTH, self::HEIGHT, $white);

        // --- bars geometry --------------------------------------------------
        $barsW  = self::WIDTH - 2 * self::QUIET_X;
        $barsH  = self::HEIGHT - self::PAD_TOP - self::TEXT_H;
        $module = $barsW / 95.0; // UPC-A is always 95 modules wide

        $pattern = $this->upcPattern($upc12);

        $x = (float) self::QUIET_X;    // start bars after left quiet zone
        $y = (int)   self::PAD_TOP;
        $h = (int)   $barsH;

        // Optional: make guard bars a touch taller (visual authenticity)
        $guardExtra = 6; // px
        $guardIdx = $this->guardModuleIndices(); // which module indices are guard bars

        // draw bars
        $cursor = 0.0;
        $modules = strlen($pattern);
        for ($i = 0; $i < $modules; $i++) {
            $isBar = ($pattern[$i] === '1');
            $x1 = (int) round($x + $cursor);
            $x2 = (int) round($x + $cursor + $module - 0.001);

            if ($isBar) {
                $y1 = $y;
                $y2 = $y + $h;

                if (isset($guardIdx[$i])) {
                    // extend guard bars downwards slightly
                    $y2 = min(self::HEIGHT - 1, $y2 + $guardExtra);
                }
                imagefilledrectangle($im, $x1, $y1, $x2, $y2, $black);
            }

            $cursor += $module;
        }

        // --- human-readable digits -----------------------------------------
        $baselineY = (int) round(self::PAD_TOP + $barsH + self::GAP_BARS_TX);
        $halfW     = $barsW / 2.0;

        $lead  = substr($upc12, 0, 1);
        $left  = substr($upc12, 1, 5);
        $right = substr($upc12, 6, 5);
        $chk   = substr($upc12, 11, 1);

        // left single digit — centered inside left quiet zone
        $this->ttfCenteredBox(
            $im, $ttfAbsPath, self::FONT_SIZE, $black,
            0, $baselineY,
            self::QUIET_X, self::TEXT_H,
            $lead, 'C'
        );

        // left group — centered under the left half of the bars
        $this->ttfCenteredBox(
            $im, $ttfAbsPath, self::FONT_SIZE, $black,
            (int) round(self::QUIET_X), $baselineY,
            (int) round($halfW), self::TEXT_H,
            $left, 'C'
        );

        // right group — centered under the right half of the bars
        $this->ttfCenteredBox(
            $im, $ttfAbsPath, self::FONT_SIZE, $black,
            (int) round(self::QUIET_X + $halfW), $baselineY,
            (int) round($halfW), self::TEXT_H,
            $right, 'C'
        );

        // right single digit — centered inside right quiet zone
        $this->ttfCenteredBox(
            $im, $ttfAbsPath, self::FONT_SIZE, $black,
            self::WIDTH - self::QUIET_X, $baselineY,
            self::QUIET_X, self::TEXT_H,
            $chk, 'C'
        );

        // --- save JPEG + tag 300 DPI ---------------------------------------
        // Buffer the JPEG so we can patch JFIF density to 300dpi
        ob_start();
        imagejpeg($im, null, self::JPEG_QUALITY);
        $jpeg = ob_get_clean();
        imagedestroy($im);

        $jpeg = $this->setJpegDpi($jpeg, self::DPI, self::DPI);
        if (file_put_contents($outAbsPath, $jpeg) === false) {
            throw new \RuntimeException("Failed to write {$outAbsPath}");
        }
    }

    // ---------------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------------

    /**
     * UPC-A 95-module pattern: L-codes left, guard bars, R-codes right.
     */
    private function upcPattern(string $upc12): string
    {
        static $L = [
            '0' => '0001101', '1' => '0011001', '2' => '0010011', '3' => '0111101',
            '4' => '0100011', '5' => '0110001', '6' => '0101111', '7' => '0111011',
            '8' => '0110111', '9' => '0001011',
        ];
        static $R = [
            '0' => '1110010', '1' => '1100110', '2' => '1101100', '3' => '1000010',
            '4' => '1011100', '5' => '1001110', '6' => '1010000', '7' => '1000100',
            '8' => '1001000', '9' => '1110100',
        ];

        $left  = substr($upc12, 0, 6);   // includes the leading single digit
        $right = substr($upc12, 6, 6);   // includes the checksum at the end

        $p  = '101';                      // start guard (3)
        for ($i = 0; $i < 6; $i++) {
            $p .= $L[$left[$i]];
        }
        $p .= '01010';                    // center guard (5)
        for ($i = 0; $i < 6; $i++) {
            $p .= $R[$right[$i]];
        }
        $p .= '101';                      // end guard (3)
        // Should always be 95 modules total
        return $p;
    }

    /**
     * Module indices that belong to guard bars (to extend them visually).
     */
    private function guardModuleIndices(): array
    {
        // start guard:   0..2
        // center guard:  45..49
        // end guard:     92..94
        $idx = [];
        for ($i = 0; $i <= 2; $i++)   $idx[$i] = true;
        for ($i = 45; $i <= 49; $i++) $idx[$i] = true;
        for ($i = 92; $i <= 94; $i++) $idx[$i] = true;
        return $idx;
    }

    /**
     * Draw text centered in a box (x,y is LEFT/TOP of the box).
     * $align: 'L' | 'C' | 'R' (horizontal inside the given width)
     */
    private function ttfCenteredBox(
        $im,
        string $ttf,
        int $sizePt,
        int $color,
        int $x,
        int $yTop,
        int $w,
        int $h,
        string $text,
        string $align = 'C'
    ): void {
        $bbox = imagettfbbox($sizePt, 0, $ttf, $text);
        // width/height from bbox
        $textW = $bbox[2] - $bbox[0];
        $textH = $bbox[1] - $bbox[7];
        $baselineOffset = -$bbox[7]; // distance from baseline to top of glyphs

        // horizontal
        switch ($align) {
            case 'L':
                $tx = $x - $bbox[0];
                break;
            case 'R':
                $tx = $x + $w - $textW - $bbox[0];
                break;
            default: // 'C'
                $tx = $x + (int) round(($w - $textW) / 2) - $bbox[0];
        }

        // vertical: center within the box; imagettftext expects baseline Y
        $ty = $yTop + (int) round(($h - $textH) / 2) + $baselineOffset;

        imagettftext($im, $sizePt, 0, $tx, $ty, $color, $ttf, $text);
    }

    /**
     * Patch a JPEG buffer so its JFIF density is set to the given DPI.
     * Returns modified binary string.
     */
    private function setJpegDpi(string $jpeg, int $xdpi, int $ydpi): string
    {
        // Look for the JFIF APP0 segment
        $pos = strpos($jpeg, "JFIF\0");
        if ($pos === false) {
            // No JFIF header? Just return as-is.
            return $jpeg;
        }
        // Right after "JFIF\0" come: version(2), units(1), Xdensity(2), Ydensity(2)
        $unitsOffset = $pos + 5 + 2; // skip "JFIF\0" + version
        $xdensityOff = $unitsOffset + 1;
        $ydensityOff = $unitsOffset + 3;

        // Set units = 1 (dots per inch)
        $jpeg[$unitsOffset] = chr(1);

        // Big-endian 2-byte ints
        $x = pack('n', max(1, min(65535, $xdpi)));
        $y = pack('n', max(1, min(65535, $ydpi)));

        $jpeg[$xdensityOff]     = $x[0];
        $jpeg[$xdensityOff + 1] = $x[1];
        $jpeg[$ydensityOff]     = $y[0];
        $jpeg[$ydensityOff + 1] = $y[1];

        return $jpeg;
    }
}
