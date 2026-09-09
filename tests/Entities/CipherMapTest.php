<?php
/*
 * Created on   : Tue Sep 09 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : CipherMapTest.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace Tests\Entities;

use PDFToolkit\Entities\CipherMap;
use PHPUnit\Framework\TestCase;

class CipherMapTest extends TestCase {
    private static function map(): CipherMap {
        return new CipherMap(
            map: ['Ψ' => 'a', 'Ω' => 'ü', 'ܘ' => ' '],
            spaceChar: 'ܘ',
            glyphsSeen: 4,
            glyphsMapped: 3,
            consistency: 0.987654,
            learnCoverage: 0.99,
        );
    }

    public function test_decode_translates_and_passes_ascii_through(): void {
        $this->assertSame("Zahl 12,34\naü b", self::map()->decode("Z\u{3A8}hl 12,34\n\u{3A8}\u{3A9}\u{718}b"));
    }

    public function test_decode_keeps_decoded_non_ascii_and_marks_unknown(): void {
        // 'Ω' → 'ü' bleibt erhalten; unbekanntes 'Ж' wird U+FFFD.
        $this->assertSame("ü\u{FFFD}", self::map()->decode('ΩЖ'));
    }

    public function test_decode_keeps_unmapped_printable_ascii(): void {
        // Ungemapptes druckbares ASCII steht meist für sich selbst und wird
        // nicht zerstört; ungemappte Steuerzeichen dagegen schon.
        $this->assertSame("a12,34 x", self::map()->decode("\u{3A8}12,34 x"));
        $this->assertSame("\u{FFFD}", self::map()->decode("\x02"));
    }

    public function test_coverage_on_counts_occurrences(): void {
        $map = self::map();
        $this->assertSame(1.0, $map->coverageOn(''));
        $this->assertSame(1.0, $map->coverageOn(" \n\t"), 'Struktur zählt nicht als Glyphe');
        $this->assertSame(0.5, $map->coverageOn('ΨЖ'));      // 1 von 2 gemappt
        $this->assertSame(0.75, $map->coverageOn("Ψ Ω\nܘЖ")); // 3 von 4, Struktur ignoriert
        // Druckbares ASCII zählt als Kandidat: der Cipher belegt auch diesen Bereich.
        $this->assertSame(0.25, $map->coverageOn('Ψabc'));
    }

    public function test_stats_round_and_expose_fields(): void {
        $stats = self::map()->stats();
        $this->assertSame(4, $stats['glyphsSeen']);
        $this->assertSame(3, $stats['glyphsMapped']);
        $this->assertSame(0.9877, $stats['consistency']);
        $this->assertSame(0.99, $stats['learnCoverage']);
        $this->assertSame('ܘ', $stats['spaceChar']);
    }
}
