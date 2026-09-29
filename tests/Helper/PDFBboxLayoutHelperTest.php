<?php
/*
 * Created on   : Tue Sep 29 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : PDFBboxLayoutHelperTest.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace Tests\Helper;

use PDFToolkit\Helper\PDFBboxLayoutHelper;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Zeilenbildung aus OCR-Wortboxen (Tesseract-TSV). Die Positionen stammen
 * aus einem Handyfoto einer Kreditkartenabrechnung (29.09.2026), die Texte
 * sind neutral.
 */
class PDFBboxLayoutHelperTest extends TestCase {
    /**
     * Zwei Buchungszeilen im Abstand von rund 50 px; dazwischen liegen das
     * Vorzeichen der ersten Zeile und zwei als Zeichen gelesene Tabellenlinien.
     * Durfte jedes Zeichen die gleitende Grenze verschieben, ketteten diese
     * kleinen Zeichen beide Zeilen zu einer zusammen.
     */
    public function test_small_glyphs_between_rows_do_not_merge_them(): void {
        $tsv = self::tsv([
            [163, 1933, 30, '30.03.2022'],
            [384, 1935, 28, 'HAENDLER'],
            [598, 1934, 30, 'SHOP'],
            [1224, 1936, 29, '31.03.2022'],
            [2032, 1940, 31, '1.394,60'],
            [2177, 1954, 4, '-'],
            [2327, 1962, 3, '.'],
            [1591, 1970, 3, '|'],
            [382, 1986, 28, '+697'],
            [464, 1986, 28, 'PUNKTE'],
        ]);

        $lines = explode("\n", self::reassembleOcr($tsv));

        $this->assertSame('30.03.2022 HAENDLER SHOP 31.03.2022 1.394,60 -', $lines[0], 'das Vorzeichen gehoert zur ersten Zeile');
        $this->assertContains('+697 PUNKTE', $lines, 'die zweite Zeile bleibt eigenstaendig');
    }

    /**
     * Eng gesetzte Zeilen (UniCredit-Umsatzuebersicht, 16-17 px Abstand bei
     * 15 px Toleranz): Worte mit Unterlaengen sind hoeher, duerfen die Zeilen
     * aber nicht zusammenziehen.
     */
    public function test_tightly_set_rows_with_descenders_stay_apart(): void {
        $tsv = self::tsv([
            [133, 1336, 21, 'KEINE'],
            [226, 1336, 21, 'BELEG'],
            [331, 1336, 26, 'INFORMATIONEN,'],
            [1656, 1353, 21, '01.07.2026'],
            [1889, 1353, 21, '30.06.2026'],
            [2099, 1353, 26, '-18,41'],
            [2188, 1353, 21, 'EUR'],
            [132, 1369, 21, 'SIEHE'],
            [298, 1369, 21, 'KONTOAUSZUG'],
        ]);

        $this->assertSame(
            "KEINE BELEG INFORMATIONEN,\n01.07.2026 30.06.2026 -18,41 EUR\nSIEHE KONTOAUSZUG",
            self::reassembleOcr($tsv)
        );
    }

    public function test_slightly_slanted_row_stays_one_line(): void {
        // Leichte Schraeglage: jedes Wort ein paar Pixel tiefer als das vorige.
        $tsv = self::tsv([
            [100, 500, 30, '01.04.2022'],
            [400, 506, 30, 'LASTSCHRIFT'],
            [800, 512, 30, 'STADTWERKE'],
            [1200, 518, 30, '49,90'],
            [1400, 524, 30, '-'],
        ]);

        $this->assertSame('01.04.2022 LASTSCHRIFT STADTWERKE 49,90 -', self::reassembleOcr($tsv));
    }

    /** @param list<array{0: int, 1: int, 2: int, 3: string}> $words [left, top, height, text] */
    private static function tsv(array $words): string {
        $rows = ["level\tpage_num\tblock_num\tpar_num\tline_num\tword_num\tleft\ttop\twidth\theight\tconf\ttext"];
        foreach ($words as $i => [$left, $top, $height, $text]) {
            $rows[] = implode("\t", [5, 1, 1, 1, 1, $i + 1, $left, $top, 50, $height, 90, $text]);
        }

        return implode("\n", $rows);
    }

    private static function reassembleOcr(string $tsv): string {
        $parse = new ReflectionMethod(PDFBboxLayoutHelper::class, 'parseTsvItems');
        $reassemble = new ReflectionMethod(PDFBboxLayoutHelper::class, 'reassembleItems');

        return (string) $reassemble->invoke(null, $parse->invoke(null, $tsv), 15.0);
    }
}
