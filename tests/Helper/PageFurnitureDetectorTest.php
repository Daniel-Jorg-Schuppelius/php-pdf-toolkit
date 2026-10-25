<?php
/*
 * Created on   : Sun Oct 25 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : PageFurnitureDetectorTest.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace Tests\Helper;

use PDFToolkit\Helper\PageFurnitureDetector;
use PHPUnit\Framework\TestCase;

class PageFurnitureDetectorTest extends TestCase {
    private const HEADER = [
        'Musterbank AG                                   Kontoauszug 1/2026    Seite %d von 3',
        'Max Mustermann GmbH',
        'Datum        Verwendungszweck                              Betrag          Saldo',
    ];

    private const FOOTER = 'Musterbank AG - Musterstrasse 1 - 12345 Musterstadt - Vorstand Erika Muster';

    /** @var list<list<string>> */
    private const BOOKINGS = [
        [
            '02.01.2026   Gutschrift Kunde Nord                          500,00       1.500,00',
            '             Max Mustermann GmbH',
            '03.01.2026   Lastschrift Stadtwerke                         -80,00       1.420,00',
            '             Vertrag 4711',
        ],
        [
            '             Abschlag Februar',
            '05.01.2026   Gutschrift Kunde Sued                          250,00       1.670,00',
            '             Rechnung 2026-17',
        ],
        [
            '07.01.2026   Kartenzahlung Tankstelle                       -60,00       1.610,00',
            '             Tankstelle Musterstadt',
        ],
    ];

    private static function text(): string {
        $pages = [];
        foreach (self::BOOKINGS as $p => $bookings) {
            $pages[] = implode("\n", [sprintf(self::HEADER[0], $p + 1), self::HEADER[1], self::HEADER[2], '', ...$bookings, '', self::FOOTER]);
        }

        return implode("\n\f", $pages);
    }

    /** Seitentrenner zu Zeilenumbruch, Leerzeilen raus — wie die Textbereinigung der Aufrufer. */
    private static function cleaned(string $text): string {
        $lines = preg_split('/\r?\n/', str_replace("\f", "\n", $text)) ?: [];

        return implode("\n", array_values(array_filter($lines, static fn (string $line): bool => trim($line) !== '')));
    }

    private static function detector(): PageFurnitureDetector {
        return new PageFurnitureDetector(static fn (string $line): bool => str_contains($line, 'Verwendungszweck') && str_contains($line, 'Betrag'));
    }

    /**
     * @param array<int, true> $furniture
     * @return list<string>
     */
    private static function markedLines(string $cleaned, array $furniture): array {
        $lines = explode("\n", $cleaned);

        return array_map(static fn (int $i): string => trim($lines[$i]), array_keys($furniture));
    }

    public function test_marks_header_footer_and_repeated_column_header_of_every_page(): void {
        $cleaned = self::cleaned(self::text());
        $marked = self::markedLines($cleaned, self::detector()->in(self::text(), $cleaned));

        $this->assertCount(12, $marked);
        $this->assertSame(3, count(array_filter($marked, static fn (string $l): bool => str_contains($l, 'Kontoauszug 1/2026'))));
        $this->assertSame(3, count(array_keys($marked, 'Max Mustermann GmbH', true)));
        $this->assertSame(3, count(array_keys($marked, trim(self::HEADER[2]), true)));
        $this->assertSame(3, count(array_keys($marked, self::FOOTER, true)));
    }

    public function test_marks_by_position_not_content(): void {
        $cleaned = self::cleaned(self::text());
        $furniture = self::detector()->in(self::text(), $cleaned);
        $counterparty = array_search('             Max Mustermann GmbH', explode("\n", $cleaned), true);

        $this->assertIsInt($counterparty);
        $this->assertArrayNotHasKey($counterparty, $furniture, 'Kontoinhaber als Gegenpartei mitten auf der Seite');
    }

    public function test_repeated_line_with_amount_is_no_furniture(): void {
        $pages = [];
        foreach ([1, 2, 3] as $p) {
            $pages[] = implode("\n", ["Kontoauszug Seite $p", 'Uebertrag aus Vorseite       1.420,00', "0$p.02.2026   Miete   -500,00", self::FOOTER]);
        }
        $text = implode("\n\f", $pages);
        $marked = self::markedLines(self::cleaned($text), self::detector()->in($text, self::cleaned($text)));

        $this->assertContains('Kontoauszug Seite 1', $marked);
        $this->assertNotContains('Uebertrag aus Vorseite       1.420,00', $marked);
    }

    public function test_line_also_inside_the_page_is_no_furniture(): void {
        $pages = [];
        foreach ([1, 2, 3] as $p) {
            $body = [];
            foreach (range(1, 8) as $n) {
                $body[] = $n === 4 ? 'Transfer Fee' : "Lohn 2025$n";
                $body[] = "1$n.06.2025   Buchung $p-$n   -$n,10";
            }
            $pages[] = implode("\n", ["Kontoauszug Seite $p", 'Transfer Fee', "0$p.06.2025   Charge of OT$p   -2,10", ...$body, self::FOOTER]);
        }
        $text = implode("\n\f", $pages);
        $marked = self::markedLines(self::cleaned($text), self::detector()->in($text, self::cleaned($text)));

        $this->assertContains('Kontoauszug Seite 1', $marked);
        $this->assertNotContains('Transfer Fee', $marked, 'Buchungsart steht auch im Seiteninneren');
    }

    public function test_single_page_has_no_furniture(): void {
        $single = implode("\n", [sprintf(self::HEADER[0], 1), ...self::BOOKINGS[0], self::FOOTER]);

        $this->assertSame([], self::detector()->in($single, $single));
    }

    /** Aufrufer schreiben den bereinigten Text um (relative Daten, abgeschnittene Zweitkonten). */
    public function test_rewritten_lines_stay_unmarked_and_the_rest_still_maps(): void {
        $cleaned = self::cleaned(self::text());
        $lines = explode("\n", $cleaned);
        $footerAt = array_search(self::FOOTER, $lines, true);
        $lines[$footerAt] = 'umgeschriebene Fusszeile';
        array_splice($lines, 5, 0, ['eingefuegte Zeile']);
        $rewritten = implode("\n", $lines);

        $marked = self::markedLines($rewritten, self::detector()->in(self::text(), $rewritten));

        $this->assertNotContains('umgeschriebene Fusszeile', $marked);
        $this->assertNotContains('eingefuegte Zeile', $marked);
        $this->assertSame(2, count(array_keys($marked, self::FOOTER, true)), 'die beiden anderen Seitenfuesse');
        $this->assertSame(3, count(array_keys($marked, 'Max Mustermann GmbH', true)));
    }
}
