<?php
/*
 * Created on   : Sat Sep 20 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : GlyphNameLayerTest.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace Tests\Helper;

use PDFToolkit\Helper\GlyphNameLayer;
use PHPUnit\Framework\TestCase;

/**
 * Textebene aus Glyphennamen.
 *
 * Die Tests arbeiten auf einem vorgefertigten mutool-Trace, damit sie ohne
 * MuPDF und ohne Beispiel-PDF laufen. Die Zahlen stammen aus einem echten
 * Kontoauszug mit defekter ToUnicode-Tabelle: dort steht "P" fuer die Ziffer
 * 3 und "K" fuer den Punkt.
 */
class GlyphNameLayerTest extends TestCase {
    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        $this->tempFiles = [];
    }

    public function test_glyph_name_beats_broken_to_unicode(): void {
        $trace = $this->trace([
            $this->line(558.63, [
                ['P', 'three', 100.0],
                ['N', 'one', 105.8],
                ['K', 'period', 111.6],
            ]),
        ]);

        $result = GlyphNameLayer::fromTraceFile($trace);

        $this->assertNotNull($result);
        $this->assertSame('31.', $result->text);
        $this->assertSame(3, $result->glyphs);
        $this->assertSame(0, $result->unnamed);
        $this->assertTrue($result->isComplete());
    }

    /**
     * Die Seitenmatrix spiegelt die y-Achse: ohne sie stuende der Fuss des
     * Auszugs vor dem Kopf.
     */
    public function test_rows_follow_the_page_transform(): void {
        $trace = $this->trace([
            $this->line(100.0, [['A', 'u', 50.0], ['A', 'n', 55.838], ['A', 't', 61.676], ['A', 'e', 67.514], ['A', 'n', 73.352]]),
            $this->line(700.0, [['A', 'O', 50.0], ['A', 'b', 55.838], ['A', 'e', 61.676], ['A', 'n', 67.514]]),
        ]);

        $result = GlyphNameLayer::fromTraceFile($trace);

        $this->assertNotNull($result);
        $this->assertSame("Oben\nunten", $result->text);
    }

    /** Spaltenabstaende werden zu Leerzeichen - Kontoauszuege leben davon. */
    public function test_column_gap_becomes_spaces(): void {
        $trace = $this->trace([
            $this->line(500.0, [
                ['A', 'one', 50.0],
                ['A', 'two', 300.0],
            ]),
        ]);

        $result = GlyphNameLayer::fromTraceFile($trace);

        $this->assertNotNull($result);
        $this->assertMatchesRegularExpression('/^1 {2,}2$/', $result->text);
    }

    public function test_unicode_names_are_decoded(): void {
        $trace = $this->trace([
            $this->line(500.0, [
                ['?', 'uni00DC', 50.0],
                ['?', 'u20AC', 55.838],
            ]),
        ]);

        $result = GlyphNameLayer::fromTraceFile($trace);

        $this->assertNotNull($result);
        $this->assertSame('Ü€', $result->text);
    }

    /**
     * Ein Font ohne sprechende Namen ("g17") traegt keine Wahrheit: dann wird
     * gar nicht erst rekonstruiert, statt die ToUnicode-Luege zu verkleiden.
     */
    public function test_unnamed_glyphs_reject_the_layer(): void {
        $glyphs = [];
        for ($i = 0; $i < 20; $i++) {
            $glyphs[] = ['x', 'g' . $i, 50.0 + $i * 5.838];
        }

        $result = GlyphNameLayer::fromTraceFile($this->trace([$this->line(500.0, $glyphs)]));

        $this->assertNull($result);
    }

    /** Einzelne unbenannte Glyphen unterhalb der Schwelle bleiben zaehlbar. */
    public function test_few_unnamed_glyphs_are_counted_but_kept(): void {
        $glyphs = [];
        for ($i = 0; $i < 39; $i++) {
            $glyphs[] = ['A', 'a', 50.0 + $i * 5.838];
        }
        $glyphs[] = ['B', 'cid42', 50.0 + 39 * 5.838];

        $result = GlyphNameLayer::fromTraceFile($this->trace([$this->line(500.0, $glyphs)]));

        $this->assertNotNull($result);
        $this->assertSame(1, $result->unnamed);
        $this->assertFalse($result->isComplete());
        $this->assertStringEndsWith('B', $result->text, 'Rueckfall auf das ToUnicode-Zeichen');
    }

    /**
     * Reprint-PDF mit Font-Mix (Deutsche Bank Kreditkarte 11/2021): die
     * Buchungsseiten tragen sprechende Namen, die Werbeseite ein Subset ohne
     * Namen. Das Gate gilt je Seite - die exakten Seiten bleiben erhalten, die
     * unbenannte wird benannt, damit der Aufrufer den Teil-Layer pruefen kann.
     */
    public function test_pages_are_gated_separately(): void {
        $named = [];
        for ($i = 0; $i < 20; $i++) {
            $named[] = ['x', 'a', 50.0 + $i * 5.838];
        }
        $unnamed = [];
        for ($i = 0; $i < 20; $i++) {
            $unnamed[] = ['x', 'g' . $i, 50.0 + $i * 5.838];
        }

        $result = GlyphNameLayer::fromTraceFile($this->traceWithPages([
            [$this->line(500.0, $named)],
            [$this->line(500.0, $unnamed)],
        ]));

        $this->assertNotNull($result, 'Eine exakte Seite genuegt fuer eine Ebene');
        $this->assertSame(2, $result->pages);
        $this->assertSame([2], $result->unreliablePages);
        $this->assertTrue($result->isPartial());
        $this->assertFalse($result->isComplete());
        $this->assertSame(20, $result->unnamed);
        $this->assertStringStartsWith(str_repeat('a', 20), $result->text, 'Seite 1 exakt aus den Glyphennamen');
        $this->assertStringEndsWith(str_repeat('x', 20), $result->text, 'Seite 2 mit den ToUnicode-Zeichen, nicht verschwiegen');
        $this->assertSame([2], $result->stats()['unreliablePages']);
    }

    /** Sind ALLE Seiten unbenannt, gibt es weiterhin keine Ebene. */
    public function test_all_pages_unnamed_reject_the_layer(): void {
        $unnamed = [];
        for ($i = 0; $i < 20; $i++) {
            $unnamed[] = ['x', 'g' . $i, 50.0 + $i * 5.838];
        }

        $this->assertNull(GlyphNameLayer::fromTraceFile($this->traceWithPages([
            [$this->line(500.0, $unnamed)],
            [$this->line(400.0, $unnamed)],
        ])));
    }

    public function test_empty_trace_yields_null(): void {
        $this->assertNull(GlyphNameLayer::fromTraceFile($this->trace([])));
    }

    /**
     * Eine Zeile im Trace-Format von "mutool draw -F trace".
     *
     * @param list<array{0: string, 1: string, 2: float}> $glyphs unicode, glyph, x
     */
    private function line(float $y, array $glyphs): string {
        $xml = '<fill_text transform="1 0 0 -1 0 841.89">' . "\n" . '<span font="ArialMT" trm="10.5 0 0 10.5">' . "\n";
        foreach ($glyphs as [$unicode, $glyph, $x]) {
            $xml .= sprintf(
                '<g unicode="%s" glyph="%s" x="%s" y="%s" adv=".556"/>' . "\n",
                htmlspecialchars($unicode, ENT_XML1),
                $glyph,
                $x,
                $y
            );
        }

        return $xml . '</span>' . "\n" . '</fill_text>' . "\n";
    }

    /**
     * Mehrseitiger Trace: je Seite eine Liste von Zeilen.
     *
     * @param list<list<string>> $pages
     */
    private function traceWithPages(array $pages): string {
        $xml = '<?xml version="1.0"?>' . "\n" . '<document name="test.pdf">' . "\n";
        foreach ($pages as $lines) {
            $xml .= '<page mediabox="0 0 595.276 841.89">' . "\n" . implode('', $lines) . '</page>' . "\n";
        }
        $xml .= '</document>' . "\n";

        $file = tempnam(sys_get_temp_dir(), 'glyphtrace_test_');
        file_put_contents($file, $xml);
        $this->tempFiles[] = $file;

        return $file;
    }

    /**
     * @param list<string> $lines
     */
    private function trace(array $lines): string {
        $xml = '<?xml version="1.0"?>' . "\n" . '<document name="test.pdf">' . "\n"
            . '<page mediabox="0 0 595.276 841.89">' . "\n"
            . implode('', $lines)
            . '</page>' . "\n" . '</document>' . "\n";

        $file = tempnam(sys_get_temp_dir(), 'glyphtrace_test_');
        file_put_contents($file, $xml);
        $this->tempFiles[] = $file;

        return $file;
    }
}
