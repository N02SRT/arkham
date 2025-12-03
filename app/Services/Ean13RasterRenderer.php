<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;

class Ean13RasterRenderer
{
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
     * Render an EAN-13 barcode JPG at 300dpi-ish sizing.
     *
     * @param string $ean13 13 digits (will not recompute)
     * @param string $destAbs absolute path for the .jpg
     * @param string $ttfAbs absolute path to OCRB.ttf
     * @param array  $opts ['module'=>2,'bar_height'=>120,'font_size'=>18]
     */
    public function render(string $ean13, string $destAbs, string $ttfAbs, array $opts = []): void
    {
        $ean13 = preg_replace('/\D+/', '', $ean13);
        if (strlen($ean13) !== 13) {
            throw new \InvalidArgumentException('EAN-13 must be 13 digits.');
        }
        if (!is_readable($ttfAbs)) {
            throw new \RuntimeException("OCRB.ttf not readable: {$ttfAbs}");
        }

        $module     = max(1, (int)($opts['module']     ?? 2));   // px per module
        $barHeight  = max(50,(int)($opts['bar_height'] ?? 120)); // px
        $fontSize   = max(8, (int)($opts['font_size']  ?? 18));  // pt
        $quiet      = 11;  // modules on each side (spec minimum is 11)

        // EAN-13 = 95 modules (incl. guards). Total with quiet zones:
        $totalModules = $quiet + 95 + $quiet;
        $width  = $totalModules * $module;
        $topPad = 10;
        $textPad = 38;
        $height = $topPad + $barHeight + $textPad;

        $img = imagecreatetruecolor($width, $height);
        imagealphablending($img, true);
        $white = imagecolorallocate($img, 255, 255, 255);
        $black = imagecolorallocate($img,   0,   0,   0);
        imagefilledrectangle($img, 0, 0, $width, $height, $white);

        // Build module bit string
        $bits = $this->encodeModules($ean13);
        // Prepend/append quiet zones (zeros)
        $bits = str_repeat('0', $quiet) . $bits . str_repeat('0', $quiet);

        // Draw bars (only '1' modules)
        $x = 0;
        for ($i = 0, $n = strlen($bits); $i < $n; $i++, $x += $module) {
            if ($bits[$i] === '1') {
                imagefilledrectangle($img, $x, $topPad, $x + $module - 1, $topPad + $barHeight, $black);
            }
        }

        // Text (center the full 13 digits)
        $label = $ean13;
        // Try to center text
        $bbox = imagettfbbox($fontSize, 0, $ttfAbs, $label);
        $textW = abs($bbox[2] - $bbox[0]);
        $textX = max(0, (int)(($width - $textW) / 2));
        $textY = $topPad + $barHeight + ($textPad * 0.75);

        imagettftext($img, $fontSize, 0, $textX, (int)$textY, $black, $ttfAbs, $label);

        // Ensure dir and write
        @mkdir(dirname($destAbs), 0775, true);
        imagejpeg($img, $destAbs, 95);
        imagedestroy($img);

        if (!file_exists($destAbs)) {
            throw new \RuntimeException("Failed writing EAN-13 JPG: {$destAbs}");
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
}
