<?php

namespace App\Services;

class VectorBarcodeRenderer
{
    // EAN-13 encode maps
    private array $L = [
        '0'=>'0001101','1'=>'0011001','2'=>'0010011','3'=>'0111101','4'=>'0100011',
        '5'=>'0110001','6'=>'0101111','7'=>'0111011','8'=>'0110111','9'=>'0001011',
    ];
    private array $G = [
        '0'=>'0100111','1'=>'0110011','2'=>'0011011','3'=>'0100001','4'=>'0011101',
        '5'=>'0111001','6'=>'0000101','7'=>'0010001','8'=>'0001001','9'=>'0010111',
    ];
    private array $R = [
        '0'=>'1110010','1'=>'1100110','2'=>'1101100','3'=>'1000010','4'=>'1011100',
        '5'=>'1001110','6'=>'1010000','7'=>'1000100','8'=>'1001000','9'=>'1110100',
    ];
    private array $parity = [
        '0'=>'LLLLLL','1'=>'LLGLGG','2'=>'LLGGLG','3'=>'LLGGGL','4'=>'LGLLGG',
        '5'=>'LGGLLG','6'=>'LGGGLL','7'=>'LGLGLG','8'=>'LGLGGL','9'=>'LGGLGL',
    ];

    // ---------- Public API (vector with HRI digits) ----------

    public function renderPdfEan13(
        string $ean13, string $pdfAbs,
        float $modulePt = 1.0, float $barHpt = 50.0, int $quietMods = 11,
        bool $withText = true, string $fontName = 'Helvetica', float $fontPt = 10.0, float $textGapPt = 2.0
    ): void {
        $pattern = $this->ean13Pattern($ean13);
        $this->writePdfFromPattern($pattern, $pdfAbs, $modulePt, $barHpt, $quietMods, [
            'type'     => 'ean13',
            'digits'   => $ean13,
            'font'     => $fontName,
            'fontPt'   => $fontPt,
            'gapPt'    => $textGapPt,
            'enabled'  => $withText,
        ]);
    }

    public function renderEpsEan13(
        string $ean13, string $epsAbs,
        float $modulePt = 1.0, float $barHpt = 50.0, int $quietMods = 11,
        bool $withText = true, string $fontName = 'Helvetica', float $fontPt = 10.0, float $textGapPt = 2.0
    ): void {
        $pattern = $this->ean13Pattern($ean13);
        $this->writeEpsFromPattern($pattern, $epsAbs, $modulePt, $barHpt, $quietMods, [
            'type'     => 'ean13',
            'digits'   => $ean13,
            'font'     => $fontName,
            'fontPt'   => $fontPt,
            'gapPt'    => $textGapPt,
            'enabled'  => $withText,
        ]);
    }

    // UPC-A (12) is EAN-13 with leading 0 for bars; HRI is 12 digits laid out as UPC
    public function renderPdfUpc12(
        string $upc12, string $pdfAbs,
        float $modulePt = 1.0, float $barHpt = 50.0, int $quietMods = 11,
        bool $withText = true, string $fontName = 'Helvetica', float $fontPt = 10.0, float $textGapPt = 2.0
    ): void {
        $pattern = $this->ean13Pattern('0'.$upc12); // bars
        $this->writePdfFromPattern($pattern, $pdfAbs, $modulePt, $barHpt, $quietMods, [
            'type'     => 'upca',
            'digits'   => $upc12,
            'font'     => $fontName,
            'fontPt'   => $fontPt,
            'gapPt'    => $textGapPt,
            'enabled'  => $withText,
        ]);
    }

    public function renderEpsUpc12(
        string $upc12, string $epsAbs,
        float $modulePt = 1.0, float $barHpt = 50.0, int $quietMods = 11,
        bool $withText = true, string $fontName = 'Helvetica', float $fontPt = 10.0, float $textGapPt = 2.0
    ): void {
        $pattern = $this->ean13Pattern('0'.$upc12); // bars
        $this->writeEpsFromPattern($pattern, $epsAbs, $modulePt, $barHpt, $quietMods, [
            'type'     => 'upca',
            'digits'   => $upc12,
            'font'     => $fontName,
            'fontPt'   => $fontPt,
            'gapPt'    => $textGapPt,
            'enabled'  => $withText,
        ]);
    }

    // ---------- Pattern builder ----------

    private function ean13Pattern(string $ean13): string
    {
        $ean13 = preg_replace('/\D/', '', $ean13 ?? '');
        if (strlen($ean13) !== 13) throw new \InvalidArgumentException("EAN-13 must be 13 digits");

        $d = str_split($ean13);
        $lead = $d[0];
        $left  = array_slice($d, 1, 6);
        $right = array_slice($d, 7, 6);
        $pLeft = str_split($this->parity[$lead]);

        $pat = '101'; // left guard
        for ($i=0; $i<6; $i++) {
            $digit = $left[$i];
            $par   = $pLeft[$i]; // L or G
            $pat  .= ($par === 'L' ? $this->L[$digit] : $this->G[$digit]);
        }
        $pat .= '01010'; // center
        for ($i=0; $i<6; $i++) {
            $digit = $right[$i];
            $pat  .= $this->R[$digit];
        }
        $pat .= '101'; // right guard

        if (strlen($pat) !== 95) {
            throw new \RuntimeException("EAN-13 pattern must be 95 modules; got ".strlen($pat));
        }
        return $pat;
    }

    // ---------- Writers (PDF/EPS) with optional HRI ----------

    private function writePdfFromPattern(
        string $pattern, string $pdfAbs,
        float $modulePt, float $barHpt, int $quietMods,
        array $hri // ['enabled'=>bool,'type'=>'ean13'|'upca','digits'=>string,'font'=>string,'fontPt'=>float,'gapPt'=>float]
    ): void {
        @mkdir(dirname($pdfAbs), 0775, true);

        $mods = strlen($pattern); // 95
        $textBlock = (!empty($hri['enabled'])) ? ($hri['fontPt'] + $hri['gapPt']) : 0.0;
        $width  = ($mods + 2*$quietMods) * $modulePt;
        $height = $barHpt + $textBlock;

        // Bars as rectangles; start at y = $textBlock to leave room for HRI
        $x = $quietMods * $modulePt;
        $bars = "0 g\n"; // fill black
        for ($i=0; $i<$mods; ) {
            if ($pattern[$i] === '1') {
                $run = 0;
                while ($i+$run < $mods && $pattern[$i+$run] === '1') { $run++; }
                $w = $run * $modulePt;
                $bars .= sprintf("%.3f %.3f %.3f %.3f re f\n", $x, $textBlock, $w, $barHpt);
                $x += $w; $i += $run;
            } else {
                $x += $modulePt; $i++;
            }
        }

        // Optional HRI (Helvetica – no embedding)
        $text = '';
        $fontName = $hri['font'] ?? 'Helvetica';
        $fontPt   = (float)($hri['fontPt'] ?? 10.0);
        if (!empty($hri['enabled'])) {
            $text .= $this->pdfHriCommands(
                $hri['type'], $hri['digits'],
                $quietMods, $modulePt, $mods,
                $fontPt, (float)($hri['gapPt'] ?? 2.0)
            );
        }

        $stream = $bars.$text;

        // Minimal PDF wrapper with core font
        $objs = [];
        $objs[] = "1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n";
        $objs[] = "2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj\n";
        $objs[] = "3 0 obj << /Type /Page /Parent 2 0 R /MediaBox [0 0 {$width} {$height}] ".
            "/Resources << /Font << /F1 << /Type /Font /Subtype /Type1 /BaseFont /{$fontName} >> >> >> ".
            "/Contents 4 0 R >> endobj\n";
        $objs[] = "4 0 obj << /Length ".strlen($stream)." >> stream\n{$stream}\nendstream endobj\n";

        $pdf = "%PDF-1.4\n";
        $offs = [0];
        foreach ($objs as $o) { $offs[] = strlen($pdf); $pdf .= $o; }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".count($offs)."\n0000000000 65535 f \n";
        for ($i=1;$i<count($offs);$i++) { $pdf .= sprintf("%010d 00000 n \n", $offs[$i]); }
        $pdf .= "trailer << /Root 1 0 R /Size ".count($offs)." >>\nstartxref\n{$xref}\n%%EOF";

        file_put_contents($pdfAbs, $pdf);
        if (!file_exists($pdfAbs)) throw new \RuntimeException("Failed to write vector PDF: {$pdfAbs}");
    }

    private function writeEpsFromPattern(
        string $pattern, string $epsAbs,
        float $modulePt, float $barHpt, int $quietMods,
        array $hri
    ): void {
        @mkdir(dirname($epsAbs), 0775, true);

        $mods = strlen($pattern);
        $textBlock = (!empty($hri['enabled'])) ? ($hri['fontPt'] + $hri['gapPt']) : 0.0;
        $W = (int)ceil(($mods + 2*$quietMods) * $modulePt);
        $H = (int)ceil($barHpt + $textBlock);

        $ps  = "%!PS-Adobe-3.0 EPSF-3.0\n";
        $ps .= "%%BoundingBox: 0 0 {$W} {$H}\n";
        $ps .= "0 0 0 setrgbcolor\n";
        $ps .= "/b { % x y w h -> -\n";
        $ps .= "  newpath moveto 0 exch rlineto exch 0 rlineto 0 exch neg rlineto closepath fill\n";
        $ps .= "} bind def\n";

        // Bars
        $x = $quietMods * $modulePt;
        for ($i=0; $i<$mods; ) {
            if ($pattern[$i] === '1') {
                $run=0; while ($i+$run<$mods && $pattern[$i+$run]==='1'){ $run++; }
                $w = $run * $modulePt;
                $ps .= sprintf("%.3f %.3f %.3f %.3f b\n", $x, $textBlock, $w, $barHpt);
                $x += $w; $i += $run;
            } else { $x += $modulePt; $i++; }
        }

        // HRI
        if (!empty($hri['enabled'])) {
            $font = $hri['font'] ?? 'Helvetica';
            $sz   = (float)($hri['fontPt'] ?? 10.0);
            $ps .= "/{$font} findfont {$sz} scalefont setfont\n";
            $ps .= $this->epsHriCommands(
                $hri['type'], $hri['digits'],
                $quietMods, $modulePt, $mods,
                $sz, (float)($hri['gapPt'] ?? 2.0)
            );
        }

        $ps .= "%%EOF\n";
        file_put_contents($epsAbs, $ps);
        if (!file_exists($epsAbs)) throw new \RuntimeException("Failed to write EPS: {$epsAbs}");
    }

    // ---------- HRI placement helpers ----------

    // PDF: returns content stream commands (uses /F1)
    private function pdfHriCommands(
        string $type, string $digits,
        int $quietMods, float $modulePt, int $mods,
        float $fontPt, float $gapPt
    ): string {
        $quietX = $quietMods * $modulePt;
        $baselineY = $fontPt * 0.2;            // tiny bottom inset to avoid clipping
        $textY = $baselineY;                   // place digits at bottom
        $cw = 0.60 * $fontPt;                  // approximate glyph width for centering

        $s = "0 g\n"; // black
        $setF = "/F1 {$fontPt} Tf ";
        $prefix = "BT {$setF}"; $suffix = " ET\n";

        if ($type === 'ean13') {
            if (strlen($digits) !== 13) return '';
            $d = str_split($digits);

            // leading digit outside left (within left quiet zone)
            $xLead = $quietX - 3*$modulePt - $cw/2;
            $s .= $prefix . $this->pdfTm($xLead, $textY) . '(' . $d[0] . ') Tj' . $suffix;

            // left 6 digits under bars
            for ($i=1; $i<=6; $i++) {
                $center = $quietX + $modulePt * (3 + 7*($i - 0.5));
                $x = $center - $cw/2;
                $s .= $prefix . $this->pdfTm($x, $textY) . '(' . $d[$i] . ') Tj' . $suffix;
            }
            // right 6 digits
            for ($j=1; $j<=6; $j++) {
                $center = $quietX + $modulePt * (3 + 42 + 5 + 7*($j - 0.5));
                $x = $center - $cw/2;
                $s .= $prefix . $this->pdfTm($x, $textY) . '(' . $d[6+$j] . ') Tj' . $suffix;
            }
        } else { // upca (12 digits)
            if (strlen($digits) !== 12) return '';
            $d = str_split($digits);

            // outside left: number system (d0)
            $xL = $quietX - 3*$modulePt - $cw/2;
            $s .= $prefix . $this->pdfTm($xL, $textY) . '(' . $d[0] . ') Tj' . $suffix;

            // left under: d1..d5 map to encoded positions 2..6 on left
            for ($i=1; $i<=5; $i++) {
                $encIdx = $i + 1; // 2..6
                $center = $quietX + $modulePt * (3 + 7*($encIdx - 0.5));
                $x = $center - $cw/2;
                $s .= $prefix . $this->pdfTm($x, $textY) . '(' . $d[$i] . ') Tj' . $suffix;
            }

            // right under: d6..d10 map to encoded positions 1..5 on right
            for ($j=1; $j<=5; $j++) {
                $center = $quietX + $modulePt * (3 + 42 + 5 + 7*($j - 0.5));
                $x = $center - $cw/2;
                $s .= $prefix . $this->pdfTm($x, $textY) . '(' . $d[5+$j] . ') Tj' . $suffix; // d6..d10
            }

            // outside right: check digit (d11) placed in right quiet zone
            $xR = $quietX + $modulePt * (95 + max(0, $quietMods - 3)) - $cw/2;
            $s .= $prefix . $this->pdfTm($xR, $textY) . '(' . $d[11] . ') Tj' . $suffix;
        }

        return $s;
    }

    private function pdfTm(float $x, float $y): string
    {
        return sprintf("1 0 0 1 %.3f %.3f Tm ", $x, $y);
    }

    // EPS HRI commands
    private function epsHriCommands(
        string $type, string $digits,
        int $quietMods, float $modulePt, int $mods,
        float $fontPt, float $gapPt
    ): string {
        $quietX = $quietMods * $modulePt;
        $baselineY = $fontPt * 0.2;
        $textY = $baselineY;
        $cw = 0.60 * $fontPt;

        $s = "0 setgray\n";

        if ($type === 'ean13') {
            if (strlen($digits) !== 13) return '';
            $d = str_split($digits);

            // leading digit
            $xLead = $quietX - 3*$modulePt - $cw/2;
            $s .= sprintf("%.3f %.3f moveto (%s) show\n", $xLead, $textY, $d[0]);

            for ($i=1; $i<=6; $i++) {
                $center = $quietX + $modulePt * (3 + 7*($i - 0.5));
                $x = $center - $cw/2;
                $s .= sprintf("%.3f %.3f moveto (%s) show\n", $x, $textY, $d[$i]);
            }
            for ($j=1; $j<=6; $j++) {
                $center = $quietX + $modulePt * (3 + 42 + 5 + 7*($j - 0.5));
                $x = $center - $cw/2;
                $s .= sprintf("%.3f %.3f moveto (%s) show\n", $x, $textY, $d[6+$j]);
            }
        } else { // upca
            if (strlen($digits) !== 12) return '';
            $d = str_split($digits);

            $xL = $quietX - 3*$modulePt - $cw/2;
            $s .= sprintf("%.3f %.3f moveto (%s) show\n", $xL, $textY, $d[0]);

            for ($i=1; $i<=5; $i++) {
                $encIdx = $i + 1; // 2..6
                $center = $quietX + $modulePt * (3 + 7*($encIdx - 0.5));
                $x = $center - $cw/2;
                $s .= sprintf("%.3f %.3f moveto (%s) show\n", $x, $textY, $d[$i]);
            }
            for ($j=1; $j<=5; $j++) {
                $center = $quietX + $modulePt * (3 + 42 + 5 + 7*($j - 0.5));
                $x = $center - $cw/2;
                $s .= sprintf("%.3f %.3f moveto (%s) show\n", $x, $textY, $d[5+$j]);
            }

            $xR = $quietX + $modulePt * (95 + max(0, $quietMods - 3)) - $cw/2;
            $s .= sprintf("%.3f %.3f moveto (%s) show\n", $xR, $textY, $d[11]);
        }

        return $s;
    }
}
