<?php
/*
 * Created on   : Sat Nov 07 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : PDFXrefHelperTest.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace Tests\Helper;

use PDFToolkit\Helper\PDFXrefHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Contracts\BaseTestCase;

/**
 * Neuaufbau der Querverweistabelle: Objektanfänge nur in Dateireihenfolge,
 * Ströme über /Length übersprungen; alles andere wird abgelehnt.
 */
final class PDFXrefHelperTest extends BaseTestCase {
    /** Strominhalt, der wie Objektanfänge aussieht - so liegt eine eingebettete PDF roh im Strom. */
    private const FAKE_OBJECTS = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n2 0 obj\n<< /Type /Pages /Count 9 >>\nendobj\n";

    public function test_offsets_found_inside_a_stream_are_corrected(): void {
        $correct = $this->pdf($this->objects());
        // So schreibt tc-lib-pdf die Tabelle: letzter Regex-Treffer im ganzen Puffer
        $broken = $this->withRegexOffsets($correct);
        $this->assertNotSame($correct, $broken, 'Precondition: the regex hits the stream');

        $this->assertSame($correct, PDFXrefHelper::rebuild($broken));
    }

    public function test_correct_table_stays_byte_identical(): void {
        $pdf = $this->pdf($this->objects());

        $this->assertSame($pdf, PDFXrefHelper::rebuild($pdf));
    }

    public function test_missing_object_numbers_become_free_entries(): void {
        $objects = $this->objects();
        $objects[7] = '<< /Unused true >>';
        $pdf = $this->pdf($objects);

        $rebuilt = PDFXrefHelper::rebuild($pdf);

        $this->assertStringContainsString("xref\n0 8\n0000000000 65535 f \n", $rebuilt);
        $this->assertSame(2, substr_count($rebuilt, ' f '), 'Head and number 6 are free');
        $this->assertSame(1, preg_match_all('/^0000000000 65535 f $/m', $rebuilt));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unsupported(): array {
        return [
            'keine PDF' => ['keine-pdf'],
            'ohne startxref' => ['ohne-startxref'],
            'Querverweisstrom' => ['xref-strom'],
            'zweite Revision' => ['revision'],
            'indirekte Länge' => ['indirekte-laenge'],
            'falsche Länge' => ['falsche-laenge'],
            'Signatur' => ['signatur'],
            'Müll zwischen Objekten' => ['muell'],
        ];
    }

    #[DataProvider('unsupported')]
    public function test_unsupported_files_are_rejected(string $kind): void {
        $objects = $this->objects();
        $pdf = match ($kind) {
            'keine-pdf' => 'kein PDF',
            'ohne-startxref' => (string) preg_replace('/startxref\s+\d+\s+%%EOF\s*$/', '', $this->pdf($objects)),
            'xref-strom' => (string) preg_replace('/\nxref\n/', "\nXRef\n", $this->pdf($objects), 1),
            'revision' => $this->pdf($objects, ' /Prev 9'),
            'indirekte-laenge' => $this->pdf(array_replace($objects, [4 => "<< /Length 6 0 R >> stream\nabc\nendstream", 6 => '3'])),
            'falsche-laenge' => $this->pdf(array_replace($objects, [4 => "<< /Length 2 >> stream\nabc\nendstream"])),
            'signatur' => $this->pdf(array_replace($objects, [6 => '<< /Type /Sig /ByteRange [0 0 0 0] /Contents <00> >>'])),
            'muell' => (string) preg_replace('/endobj\n3 0 obj/', "endobj\nMüll\n3 0 obj", $this->pdf($objects), 1),
            default => throw new \LogicException($kind),
        };

        $this->expectException(RuntimeException::class);
        PDFXrefHelper::rebuild($pdf);
    }

    /**
     * Kleine PDF mit Seite, Inhaltsstrom, Strom mit Objekt-Attrappen und
     * Zeichenketten, die endobj und Klammern enthalten.
     *
     * @return array<int, string>
     */
    private function objects(): array {
        $content = "BT /F1 12 Tf 72 720 Td (Hallo) Tj ET";

        return [
            1 => '<< /Type /Catalog /Pages 2 0 R /Names << /Note (Text mit \) und endobj\n3 0 obj) >> >>',
            2 => '<< /Type /Pages /Kids [ 3 0 R ] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 5 0 R % Kommentar mit endobj' . "\n" . '>>',
            4 => '<< /Type /EmbeddedFile /Subtype /application#2Fpdf /Length ' . strlen(self::FAKE_OBJECTS) . ' /Params << /Size ' . strlen(self::FAKE_OBJECTS) . " /Length 1 >> >> stream\n" . self::FAKE_OBJECTS . "\nendstream",
            5 => '<< /Length ' . strlen($content) . " >>\nstream\n" . $content . "\nendstream",
        ];
    }

    /**
     * @param array<int, string> $objects
     */
    private function pdf(array $objects, string $trailerExtra = ''): string {
        $out = "%PDF-1.7\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $number => $body) {
            $offsets[$number] = strlen($out);
            $out .= "{$number} 0 obj\n{$body}\nendobj\n";
        }

        return $out . $this->table(strlen($out), $offsets, max(array_keys($objects)) + 1, $trailerExtra);
    }

    private function withRegexOffsets(string $pdf): string {
        $xref = strpos($pdf, "\nxref\n");
        $this->assertNotFalse($xref);
        $body = substr($pdf, 0, $xref + 1);
        preg_match_all('/(([0-9]+)[\s][0-9]+[\s]obj[\n])/i', $body, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        $offsets = [];
        foreach ($matches as $match) {
            $offsets[(int) $match[2][0]] = $match[2][1];
        }
        preg_match('#trailer\n<< /Size (\d+)#', $pdf, $size);

        return $body . $this->table(strlen($body), $offsets, (int) $size[1]);
    }

    /**
     * @param array<int, int> $offsets
     */
    private function table(int $xrefOffset, array $offsets, int $size, string $trailerExtra = ''): string {
        $table = "xref\n0 {$size}\n0000000000 65535 f \n";
        $free = $size + 1;
        for ($number = 1; $number < $size; $number++) {
            $table .= isset($offsets[$number]) ? sprintf("%010d 00000 n \n", $offsets[$number]) : sprintf("0000000000 %05d f \n", $free++);
        }

        return $table . "trailer\n<< /Size {$size} /Root 1 0 R{$trailerExtra} >>\nstartxref\n{$xrefOffset}\n%%EOF\n";
    }
}
