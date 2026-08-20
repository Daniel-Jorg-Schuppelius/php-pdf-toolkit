<?php
/*
 * Created on   : Thu Aug 20 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : StationeryOverlayWriterTest.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace Tests\Writers;

use PDFToolkit\Helper\PDFHelper;
use PDFToolkit\Writers\StationeryOverlayWriter;
use TCPDF;
use Tests\Contracts\BaseTestCase;

/**
 * Firmenbogen als Vektor-Overlay unter ein fertiges Inhalts-PDF.
 */
final class StationeryOverlayWriterTest extends BaseTestCase {
    private StationeryOverlayWriter $writer;

    /** @var list<string> */
    private array $temps = [];

    private static ?string $pdftotext = null;

    protected function setUp(): void {
        $this->writer = new StationeryOverlayWriter;
    }

    protected function tearDown(): void {
        foreach ($this->temps as $path) {
            @unlink($path);
        }
        $this->temps = [];
    }

    public function test_is_available(): void {
        $this->assertTrue($this->writer->isAvailable());
    }

    public function test_overlay_keeps_the_page_count_of_the_content(): void {
        $content = $this->makePdf(['Seite eins', 'Seite zwei', 'Seite drei']);
        $stationery = $this->makePdf(['Briefbogen']);
        $out = $this->tempPath();

        $this->assertTrue($this->writer->overlay($content, $out, $stationery));
        $this->assertSame(3, PDFHelper::getPageCount($out));
    }

    /** Beide Ebenen landen im Ergebnis — der Bogen ersetzt den Inhalt nicht. */
    public function test_overlay_contains_both_layers(): void {
        $content = $this->makePdf(['Rechnungsinhalt']);
        $stationery = $this->makePdf(['Musterfirma GmbH']);

        $result = $this->writer->overlayToString($content, $stationery);

        $this->assertNotNull($result);
        $text = $this->textOf($result);
        $this->assertStringContainsString('Rechnungsinhalt', $text);
        $this->assertStringContainsString('Musterfirma GmbH', $text);
    }

    /** Leerer Folgebogen = bewusst nur Seite 1 auf Briefpapier. */
    public function test_empty_following_path_means_no_stationery_after_page_one(): void {
        $content = $this->makePdf(['Eins', 'Zwei']);
        $stationery = $this->makePdf(['NUR-ERSTE-SEITE']);

        $result = $this->writer->overlayToString($content, $stationery, '');
        $this->assertNotNull($result);

        $text = (string) $this->textOf($result);
        $this->assertSame(1, substr_count($text, 'NUR-ERSTE-SEITE'));
    }

    /** Ohne expliziten Folgebogen erbt jede Seite den der ersten. */
    public function test_null_following_path_inherits_the_first_page_stationery(): void {
        $content = $this->makePdf(['Eins', 'Zwei']);
        $stationery = $this->makePdf(['AUF-JEDER-SEITE']);

        $result = $this->writer->overlayToString($content, $stationery);
        $this->assertNotNull($result);

        $text = (string) $this->textOf($result);
        $this->assertSame(2, substr_count($text, 'AUF-JEDER-SEITE'));
    }

    public function test_separate_stationery_for_first_and_following_pages(): void {
        $content = $this->makePdf(['Eins', 'Zwei', 'Drei']);
        $first = $this->makePdf(['ERSTBOGEN']);
        $following = $this->makePdf(['FOLGEBOGEN']);

        $result = $this->writer->overlayToString($content, $first, $following);
        $this->assertNotNull($result);

        $text = (string) $this->textOf($result);
        $this->assertSame(1, substr_count($text, 'ERSTBOGEN'));
        $this->assertSame(2, substr_count($text, 'FOLGEBOGEN'));
    }

    /** Ohne Bogen bleibt der Inhalt unverändert erhalten (kein Sonderweg beim Aufrufer). */
    public function test_missing_stationery_still_returns_the_content(): void {
        $content = $this->makePdf(['Nur Inhalt']);

        $result = $this->writer->overlayToString($content, null);

        $this->assertNotNull($result);
        $this->assertStringContainsString('Nur Inhalt', (string) $this->textOf($result));
    }

    public function test_missing_content_file_fails_cleanly(): void {
        $this->assertNull($this->writer->overlayToString('/nonexistent/content.pdf', null));
        $this->assertFalse($this->writer->overlay('/nonexistent/content.pdf', $this->tempPath(), null));
    }

    public function test_overlay_bytes_works_without_touching_the_caller_filesystem(): void {
        $content = (string) file_get_contents($this->makePdf(['Byte-Inhalt']));
        $stationery = (string) file_get_contents($this->makePdf(['Byte-Bogen']));

        $result = $this->writer->overlayBytes($content, $stationery);

        $this->assertNotNull($result);
        $this->assertStringStartsWith('%PDF', $result);
    }

    /**
     * Text der Ausgabe über pdftotext. Der Test überspringt sich, wenn das
     * Werkzeug fehlt — die Struktur-Zusicherungen (Seitenzahl, gültiges PDF)
     * laufen dann trotzdem.
     */
    private function textOf(string $bytes): string {
        if (self::$pdftotext === null) {
            self::$pdftotext = trim((string) shell_exec('command -v pdftotext 2>/dev/null'));
        }
        if (self::$pdftotext === '') {
            $this->markTestSkipped('pdftotext ist nicht installiert.');
        }

        $path = $this->write($bytes);

        return (string) shell_exec(escapeshellarg(self::$pdftotext) . ' ' . escapeshellarg($path) . ' -');
    }

    /** @param list<string> $pages */
    private function makePdf(array $pages): string {
        $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        foreach ($pages as $text) {
            $pdf->AddPage();
            $pdf->SetFont('helvetica', '', 12);
            $pdf->Cell(0, 10, $text);
        }

        return $this->write($pdf->Output('', 'S'));
    }

    private function write(string $bytes): string {
        $path = $this->tempPath();
        file_put_contents($path, $bytes);

        return $path;
    }

    private function tempPath(): string {
        $path = tempnam(sys_get_temp_dir(), 'stw_') . '.pdf';
        $this->temps[] = $path;

        return $path;
    }
}
