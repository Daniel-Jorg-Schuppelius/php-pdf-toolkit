<?php
/*
 * Created on   : Sat Oct 18 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : BarcodeProbe.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace Tests\Helper;

/**
 * Zeichnet einen Code-39-Strichcode als PNG (GD), damit die Barcode-Tests
 * ohne Generator-Programm auskommen. Code 39: je Zeichen 5 Striche und 4
 * Lücken, drei davon breit; Start und Ende sind "*".
 */
final class BarcodeProbe {
    /** Muster je Zeichen: n = schmal, w = breit, abwechselnd Strich/Lücke, beginnend mit Strich */
    private const CODE39 = [
        '0' => 'nnnwwnwnn', '1' => 'wnnwnnnnw', '2' => 'nnwwnnnnw', '3' => 'wnwwnnnnn', '4' => 'nnnwwnnnw',
        '5' => 'wnnwwnnnn', '6' => 'nnwwwnnnn', '7' => 'nnnwnnwnw', '8' => 'wnnwnnwnn', '9' => 'nnwwnnwnn',
        'A' => 'wnnnnwnnw', 'B' => 'nnwnnwnnw', 'C' => 'wnwnnwnnn', 'D' => 'nnnnwwnnw', 'E' => 'wnnnwwnnn',
        'F' => 'nnwnwwnnn', 'G' => 'nnnnnwwnw', 'H' => 'wnnnnwwnn', 'I' => 'nnwnnwwnn', 'J' => 'nnnnwwwnn',
        'K' => 'wnnnnnnww', 'L' => 'nnwnnnnww', 'M' => 'wnwnnnnwn', 'N' => 'nnnnwnnww', 'O' => 'wnnnwnnwn',
        'P' => 'nnwnwnnwn', 'Q' => 'nnnnnnwww', 'R' => 'wnnnnnwwn', 'S' => 'nnwnnnwwn', 'T' => 'nnnnwnwwn',
        'U' => 'wwnnnnnnw', 'V' => 'nwwnnnnnw', 'W' => 'wwwnnnnnn', 'X' => 'nwnnwnnnw', 'Y' => 'wwnnwnnnn',
        'Z' => 'nwwnwnnnn', '-' => 'nwnnnnwnw', '.' => 'wwnnnnwnn', ' ' => 'nwwnnnwnn', '*' => 'nwnnwnwnn',
        '$' => 'nwnwnwnnn', '/' => 'nwnwnnnwn', '+' => 'nwnnnwnwn', '%' => 'nnnwnwnwn',
    ];

    /**
     * PNG mit dem Code; Text aus Ziffern, Großbuchstaben, "-", ".", Leerzeichen.
     */
    public static function code39(string $text, string $path, int $narrow = 3, int $height = 90, int $quiet = 40): void {
        $text = strtoupper($text);
        $sequence = '*' . $text . '*';
        $width = $quiet * 2;
        foreach (str_split($sequence) as $char) {
            $pattern = self::CODE39[$char] ?? throw new \InvalidArgumentException("Code 39 kennt '$char' nicht");
            $width += (substr_count($pattern, 'n') * $narrow) + (substr_count($pattern, 'w') * $narrow * 3) + $narrow;
        }
        $image = imagecreatetruecolor($width, $height + $quiet * 2);
        $white = imagecolorallocate($image, 255, 255, 255);
        $black = imagecolorallocate($image, 0, 0, 0);
        imagefilledrectangle($image, 0, 0, $width, $height + $quiet * 2, $white);
        $x = $quiet;
        foreach (str_split($sequence) as $char) {
            $pattern = self::CODE39[$char];
            for ($i = 0; $i < 9; $i++) {
                $span = $pattern[$i] === 'w' ? $narrow * 3 : $narrow;
                if ($i % 2 === 0) {
                    imagefilledrectangle($image, $x, $quiet, $x + $span - 1, $quiet + $height, $black);
                }
                $x += $span;
            }
            $x += $narrow; // Lücke zwischen den Zeichen
        }
        imagepng($image, $path);
        imagedestroy($image);
    }
}
