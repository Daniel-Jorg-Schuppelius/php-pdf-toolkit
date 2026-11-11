<?php
/*
 * Created on   : Wed Nov 11 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : PDFPhantomSpaceHelperTest.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace Tests\Helper;

use PDFToolkit\Helper\PDFPhantomSpaceHelper;
use Tests\Contracts\BaseTestCase;

/**
 * Phantom-Leerzeichen (pdf-phantom-spaces.js) an einer handgeschriebenen PDF
 * mit den drei Mustern, die der PDF24-Druckertreiber für bedingte
 * Trennstriche aus Word erzeugt (gemessen an FM-SSDV0-2ZEG0, 11.11.2026):
 *
 * - Leerzeichen-Tj, danach "0 0 Td" (Trennstelle im Wort)
 * - Silbe mit Leerzeichen am Ende, das Td rückt nur um die Silbe vor
 * - Leerzeichen am Ende eines Textobjekts, der Trennstrich in einem neuen
 *   Textobjekt mit Tm an derselben Stelle (Zeilenende)
 *
 * dazu ein echtes Leerzeichen, das bleiben muss, und eine CID-Schrift mit
 * ToUnicode wie in der Originaldatei. Den Text prüft MuPDF (mutool draw -F
 * txt): pdftotext verwirft Phantom-Leerzeichen ohnehin und sähe keinen
 * Unterschied.
 */
final class PDFPhantomSpaceHelperTest extends BaseTestCase {
    private string $workDir;

    protected function setUp(): void {
        parent::setUp();
        if (!PDFPhantomSpaceHelper::isAvailable() || !PdfProbe::toolsAvailable()) {
            $this->markTestSkipped('mutool (mutool-pdf-phantom-spaces) oder poppler nicht verfügbar');
        }
        $this->workDir = sys_get_temp_dir() . '/pdf-phantom-test-' . bin2hex(random_bytes(6));
        mkdir($this->workDir, 0755, true);
    }

    protected function tearDown(): void {
        if (isset($this->workDir) && is_dir($this->workDir)) {
            PdfProbe::removeDir($this->workDir);
        }
    }

    /**
     * Helvetica-Breiten (1/1000): I 278, n 556, h 556, a 556, l 222, t 278,
     * s 500, Leerzeichen 278, A 667, b 556, y 500, e 556, v 500, r 333, - 333.
     * Bei 12 pt: "In" 10.008, "halts" 25.344, " " 3.336, "Ana" 21.348,
     * "ly" 8.664, "Ab" 14.676.
     */
    private const CONTENT = <<<'PDF'
        BT
        /F1 12 Tf
        1 0 0 1 72 720 Tm
        (In) Tj
        10.008 0 Td ( ) Tj
        0 0 Td (halts) Tj
        25.344 0 Td ( ) Tj
        3.336 0 Td (ver) Tj
        ET
        BT
        /F1 12 Tf
        1 0 0 1 72 700 Tm
        (Ana ) Tj
        21.348 0 Td (ly ) Tj
        8.664 0 Td (se) Tj
        ET
        BT
        /F1 12 Tf
        1 0 0 1 72 680 Tm
        (Ab) Tj
        14.676 0 Td ( ) Tj
        ET
        BT
        /F1 12 Tf
        1 0 0 1 86.676 680 Tm
        (-) Tj
        ET
        BT
        /F2 12 Tf
        1 0 0 1 72 660 Tm
        <00010002> Tj
        10.008 0 Td <0003> Tj
        0 0 Td <00040005000600070008> Tj
        ET
        PDF;

    private const PLAIN_CONTENT = "BT\n/F1 12 Tf\n1 0 0 1 72 720 Tm\n(Inhalts ver) Tj\nET\n";

    public function test_original_carries_the_phantom_spaces(): void {
        $text = self::mupdfText($this->pdf('original.pdf', self::CONTENT));

        $this->assertStringContainsString('In halts ver', $text);
        $this->assertStringContainsString('Ana ly se', $text);
    }

    public function test_removes_phantom_spaces_and_keeps_real_ones(): void {
        $source = $this->pdf('original.pdf', self::CONTENT);
        $output = $this->workDir . '/bereinigt.pdf';

        $this->assertTrue(PDFPhantomSpaceHelper::removePhantomSpaces($source, $output));

        $text = self::mupdfText($output);
        $this->assertStringContainsString('Inhalts ver', $text, 'Trennstelle weg, echtes Leerzeichen bleibt');
        $this->assertStringContainsString('Analyse', $text, 'Silben mit Leerzeichen am Ende');
        $this->assertStringContainsString('Ab-', $text, 'Trennstrich am Zeilenende in neuem Textobjekt');
        $this->assertSame(2, substr_count($text, 'Inhalts'), 'Auch die CID-Schrift mit ToUnicode');
    }

    /** Ein Leerzeichen zeichnet nichts: Die Seite sieht danach gleich aus. */
    public function test_the_page_looks_the_same(): void {
        $source = $this->pdf('original.pdf', self::CONTENT);
        $output = $this->workDir . '/bereinigt.pdf';
        $this->assertTrue(PDFPhantomSpaceHelper::removePhantomSpaces($source, $output));

        $this->assertSame($this->render($source, 'vorher'), $this->render($output, 'nachher'));
    }

    public function test_nothing_to_remove_writes_no_output(): void {
        $source = $this->pdf('sauber.pdf', self::PLAIN_CONTENT);
        $output = $this->workDir . '/bereinigt.pdf';

        $this->assertFalse(PDFPhantomSpaceHelper::removePhantomSpaces($source, $output));
        $this->assertFileDoesNotExist($output);
    }

    public function test_invalid_input_is_refused(): void {
        $source = $this->workDir . '/kein.pdf';
        file_put_contents($source, 'kein PDF');

        $this->assertFalse(PDFPhantomSpaceHelper::removePhantomSpaces($source, $this->workDir . '/aus.pdf'));
    }

    private static function mupdfText(string $pdf): string {
        $text = (string) shell_exec(sprintf('mutool draw -q -F txt -o - %s 2>/dev/null', escapeshellarg($pdf)));

        return trim((string) preg_replace('/[ \t]+/', ' ', $text));
    }

    /** Pixel der ersten Seite als PNG-Inhalt (pdftoppm) */
    private function render(string $pdf, string $name): string {
        $base = $this->workDir . '/' . $name;
        exec(sprintf('pdftoppm -f 1 -l 1 -r 72 -png -singlefile %s %s 2>&1', escapeshellarg($pdf), escapeshellarg($base)), $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
        $image = imagecreatefrompng($base . '.png');
        $this->assertNotFalse($image);
        $pixels = '';
        for ($y = 0; $y < imagesy($image); $y++) {
            for ($x = 0; $x < imagesx($image); $x++) {
                $pixels .= pack('N', imagecolorat($image, $x, $y));
            }
        }

        return md5($pixels);
    }

    /**
     * PDF mit Helvetica (WinAnsi, /Widths) als F1 und einer CID-Schrift
     * (Identity-H, /W, ToUnicode) als F2: 1=I, 2=n, 3=Leerzeichen, 4=h,
     * 5=a, 6=l, 7=t, 8=s.
     */
    private function pdf(string $name, string $content): string {
        $widths = array_fill(0, 95, 556);
        foreach ([32 => 278, 45 => 333, 65 => 667, 73 => 278, 98 => 556, 101 => 556, 108 => 222, 114 => 333, 115 => 500, 116 => 278, 118 => 500, 121 => 500] as $code => $width) {
            $widths[$code - 32] = $width;
        }
        $toUnicode = "/CIDInit /ProcSet findresource begin\n12 dict begin\nbegincmap\n1 begincodespacerange\n<0000> <FFFF>\nendcodespacerange\n"
            . "8 beginbfchar\n<0001> <0049>\n<0002> <006E>\n<0003> <0020>\n<0004> <0068>\n<0005> <0061>\n<0006> <006C>\n<0007> <0074>\n<0008> <0073>\nendbfchar\n"
            . "endcmap\nCMapName currentdict /CMap defineresource pop\nend\nend\n";

        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R /F2 6 0 R >> >> >>',
            4 => self::stream($content),
            5 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding /FirstChar 32 /LastChar 126 /Widths [' . implode(' ', $widths) . '] >>',
            6 => '<< /Type /Font /Subtype /Type0 /BaseFont /Helvetica /Encoding /Identity-H /DescendantFonts [7 0 R] /ToUnicode 8 0 R >>',
            7 => '<< /Type /Font /Subtype /CIDFontType2 /BaseFont /Helvetica /CIDSystemInfo << /Registry (Adobe) /Ordering (Identity) /Supplement 0 >> /FontDescriptor 9 0 R /DW 1000 /W [1 [278 556 278 556 556 222 278 500]] >>',
            8 => self::stream($toUnicode),
            9 => '<< /Type /FontDescriptor /FontName /Helvetica /Flags 32 /FontBBox [-166 -225 1000 931] /ItalicAngle 0 /Ascent 718 /Descent -207 /CapHeight 718 /StemV 88 >>',
        ];

        $pdf = "%PDF-1.7\n";
        $offsets = [];
        foreach ($objects as $number => $body) {
            $offsets[$number] = strlen($pdf);
            $pdf .= "$number 0 obj\n$body\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= 'xref' . "\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        $pdf .= 'trailer' . "\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";

        $path = $this->workDir . '/' . $name;
        file_put_contents($path, $pdf);

        return $path;
    }

    private static function stream(string $data): string {
        return '<< /Length ' . strlen($data) . " >>\nstream\n" . $data . "\nendstream";
    }
}
