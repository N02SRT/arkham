<?php

namespace App\Services;

/**
 * Raster EAN-13 renderer (matches UPC-12 design).
 * - Output: 460 x 300 px JPEG, JFIF tagged 300 DPI
 * - Digits: OCR-B (recommended) or any TTF you pass in
 * - Layout: single outer digit inside left quiet zone; 6+6 groups centered
 */
class Ean13RasterRenderer
{
    // Canvas / resolution
    private const WIDTH   = 460;
    private const HEIGHT  = 300;
    private const DPI     = 72;      // will be embedded in the JPEG's JFIF header

    // Layout (px) — tweak if you want tiny visual adjustments
    private const QUIET_X       = 42; // left/right quiet zone width (also the single-digit box)
    private const PAD_TOP       = 12; // top padding before bars
    private const GAP_BARS_TX   = -10;  // gap between bars and digits baseline
    private const TEXT_H        = 72; // block height reserved for digits (visual cap)
    private const FONT_SIZE     = 34; // OCR-B point size for 300px height; adjust 32–36 if needed
    private const JPEG_QUALITY  = 70;

    /**
     * Build a full 13-digit EAN by appending the check digit.
     * @param string $base12 exactly 12 numeric chars
     */
    public function makeEan13(string $base12): string
    {
        $base12 = preg_replace('/\D+/', '', $base12);
        if (strlen($base12) !== 12) {
            throw new \InvalidArgumentException('EAN-13 base must be 12 digits.');
        }
        return $base12 . $this->checkDigit($base12);
    }

    /**
     * Render an EAN-13 barcode JPG at 460x300 px @ 300 DPI (matches UPC-12 design).
     *
     * @param string $ean13 13 digits (will not recompute)
     * @param string $destAbs absolute path for the .jpg
     * @param string $ttfAbs absolute path to OCRB.ttf
     * @param array  $opts (ignored for consistency with UPC-12 fixed dimensions)
     */
    public function render(string $ean13, string $destAbs, string $ttfAbs, array $opts = []): void
    {
        $ean13 = preg_replace('/\D+/', '', $ean13);
        if (strlen($ean13) !== 13) {
            throw new \InvalidArgumentException('EAN-13 must be 13 digits.');
        }
        if (!is_readable($ttfAbs)) {
            throw new \RuntimeException("TTF not readable: {$ttfAbs}");
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
        $module = $barsW / 95.0; // EAN-13 is always 95 modules wide (same as UPC-A)

        $pattern = $this->encodeModules($ean13);

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

        $lead   = substr($ean13, 0, 1);
        $left6  = substr($ean13, 1, 6);
        $right6 = substr($ean13, 7, 6);

        // left single digit — centered inside left quiet zone
        $this->ttfCenteredBox(
            $im, $ttfAbs, self::FONT_SIZE, $black,
            0, $baselineY,
            self::QUIET_X, self::TEXT_H,
            $lead, 'C'
        );

        // left group — centered under the left half of the bars
        $this->ttfCenteredBox(
            $im, $ttfAbs, self::FONT_SIZE, $black,
            (int) round(self::QUIET_X), $baselineY,
            (int) round($halfW), self::TEXT_H,
            $left6, 'C'
        );

        // right group — centered under the right half of the bars
        $this->ttfCenteredBox(
            $im, $ttfAbs, self::FONT_SIZE, $black,
            (int) round(self::QUIET_X + $halfW), $baselineY,
            (int) round($halfW), self::TEXT_H,
            $right6, 'C'
        );

        // --- save JPEG + tag 300 DPI ---------------------------------------
        // Buffer the JPEG so we can patch JFIF density to 300dpi
        ob_start();
        imagejpeg($im, null, self::JPEG_QUALITY);
        $jpeg = ob_get_clean();
        imagedestroy($im);

        $jpeg = $this->setJpegDpi($jpeg, self::DPI, self::DPI);
        @mkdir(dirname($destAbs), 0775, true);
        if (file_put_contents($destAbs, $jpeg) === false) {
            throw new \RuntimeException("Failed to write {$destAbs}");
        }
    }

    // ---------- internals ----------

    private function checkDigit(string $base12): int
    {
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $d = (int)$base12[$i];
            $sum += ($i % 2 === 0) ? $d : 3 * $d; // positions from left: odd->1x, even->3x
        }
        return (10 - ($sum % 10)) % 10;
    }

    private function encodeModules(string $ean13): string
    {
        // Tables from EAN-13 spec
        static $L = [
            '0'=>'0001101','1'=>'0011001','2'=>'0010011','3'=>'0111101','4'=>'0100011',
            '5'=>'0110001','6'=>'0101111','7'=>'0111011','8'=>'0110111','9'=>'0001011'
        ];
        static $G = [
            '0'=>'0100111','1'=>'0110011','2'=>'0011011','3'=>'0100001','4'=>'0011101',
            '5'=>'0111001','6'=>'0000101','7'=>'0010001','8'=>'0001001','9'=>'0010111'
        ];
        static $R = [
            '0'=>'1110010','1'=>'1100110','2'=>'1101100','3'=>'1000010','4'=>'1011100',
            '5'=>'1001110','6'=>'1010000','7'=>'1000100','8'=>'1001000','9'=>'1110100'
        ];
        // Parity patterns for the left side, based on the first digit
        static $PARITY = [
            '0'=>'LLLLLL','1'=>'LLGLGG','2'=>'LLGGLG','3'=>'LLGGGL','4'=>'LGLLGG',
            '5'=>'LGGLLG','6'=>'LGGGLL','7'=>'LGLGLG','8'=>'LGLGGL','9'=>'LGGLGL'
        ];

        $first = $ean13[0];
        $left  = substr($ean13, 1, 6);
        $right = substr($ean13, 7, 6);
        $par   = $PARITY[$first] ?? 'LLLLLL';

        $startGuard = '101';
        $centerGuard= '01010';
        $endGuard   = '101';

        $leftBits = '';
        for ($i = 0; $i < 6; $i++) {
            $digit = $left[$i];
            $leftBits .= ($par[$i] === 'L') ? $L[$digit] : $G[$digit];
        }

        $rightBits = '';
        for ($i = 0; $i < 6; $i++) {
            $digit = $right[$i];
            $rightBits .= $R[$digit];
        }

        return $startGuard . $leftBits . $centerGuard . $rightBits . $endGuard;
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
