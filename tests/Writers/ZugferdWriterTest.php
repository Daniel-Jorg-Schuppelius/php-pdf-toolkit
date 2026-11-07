<?php
/*
 * Created on   : Sat Jun 14 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : ZugferdWriterTest.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace Tests\Writers;

use PDFToolkit\Entities\PDFContent;
use PDFToolkit\Enums\PDFWriterType;
use PDFToolkit\Helper\{PDFAttachmentHelper, PDFHelper, PDFOptimizeHelper};
use PDFToolkit\Readers\ZugferdReader;
use PDFToolkit\Writers\ZugferdWriter;
use PHPUnit\Framework\Attributes\{DataProvider, Group};
use Tests\Contracts\BaseTestCase;
use Tests\Helper\PdfProbe;

final class ZugferdWriterTest extends BaseTestCase {
    private ZugferdWriter $writer;
    private string $tempDir;

    protected function setUp(): void {
        parent::setUp();
        $this->writer = new ZugferdWriter;
        $this->tempDir = sys_get_temp_dir();
    }

    public function test_get_type(): void {
        $this->assertSame(PDFWriterType::Zugferd, ZugferdWriter::getType());
    }

    public function test_is_available_returns_bool(): void {
        $this->assertIsBool($this->writer->isAvailable());
    }

    public function test_can_handle_requires_invoice_xml(): void {
        if (!$this->writer->isAvailable()) {
            $this->markTestSkipped('TCPDF is not available');
        }

        // Ohne invoice_xml-Meta darf der Writer nicht greifen.
        $plain = PDFContent::fromHtml('<p>Test</p>');
        $this->assertFalse($this->writer->canHandle($plain));

        $withXml = PDFContent::fromHtml('<p>Rechnung</p>', ['invoice_xml' => '<x/>']);
        $this->assertTrue($this->writer->canHandle($withXml));
    }

    public function test_create_pdf_string_returns_null_without_xml(): void {
        if (!$this->writer->isAvailable()) {
            $this->markTestSkipped('TCPDF is not available');
        }

        $content = PDFContent::fromHtml('<p>Kein XML</p>');
        $this->assertNull($this->writer->createPdfString($content));
    }

    /**
     * Default-Dateiname der eingebetteten XML muss der spec-konforme
     * Factur-X-Name 'factur-x.xml' sein (ZUGFeRD 2.1/2.2 = Factur-X 1.0),
     * nicht der Legacy-Name 'zugferd-invoice.xml'.
     */
    #[Group('integration')]
    public function test_default_embedded_filename_is_factur_x(): void {
        if (!$this->writer->isAvailable()) {
            $this->markTestSkipped('TCPDF is not available');
        }

        $content = PDFContent::fromHtml('<h1>Rechnung</h1>', ['invoice_xml' => $this->sampleInvoiceXml()]);

        // TCPDF-Engine erzwingen (kein FPDI/Dompdf nötig, deterministisch).
        $pdf = $this->writer->createPdfString($content, ['render_engine' => ZugferdWriter::ENGINE_TCPDF]);

        $this->assertNotNull($pdf);
        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertStringContainsString('factur-x.xml', $pdf, 'Embedded XML should use the canonical Factur-X filename');
        $this->assertStringNotContainsString('zugferd-invoice.xml', $pdf);
    }

    /**
     * Explizit gesetzter invoice_filename überschreibt den Default.
     */
    #[Group('integration')]
    public function test_invoice_filename_option_overrides_default(): void {
        if (!$this->writer->isAvailable()) {
            $this->markTestSkipped('TCPDF is not available');
        }

        $content = PDFContent::fromHtml('<h1>Rechnung</h1>', ['invoice_xml' => $this->sampleInvoiceXml()]);

        $pdf = $this->writer->createPdfString($content, [
            'render_engine' => ZugferdWriter::ENGINE_TCPDF,
            'invoice_filename' => ZugferdWriter::INVOICE_FILENAME_ZUGFERD_LEGACY,
        ]);

        $this->assertNotNull($pdf);
        $this->assertStringContainsString('zugferd-invoice.xml', $pdf);
    }

    /**
     * createPdf schreibt erfolgreich eine gültige PDF-Datei.
     */
    #[Group('integration')]
    public function test_create_pdf_writes_file(): void {
        if (!$this->writer->isAvailable()) {
            $this->markTestSkipped('TCPDF is not available');
        }

        $content = PDFContent::fromHtml('<h1>Rechnung</h1>', ['invoice_xml' => $this->sampleInvoiceXml()]);
        $outputPath = $this->tempDir . '/zugferd_test_' . uniqid() . '.pdf';

        try {
            $ok = $this->writer->createPdf($content, $outputPath, ['render_engine' => ZugferdWriter::ENGINE_TCPDF]);

            $this->assertTrue($ok);
            $this->assertFileExists($outputPath);
            $this->assertGreaterThan(0, filesize($outputPath));
            $this->assertStringStartsWith('%PDF-', (string) file_get_contents($outputPath));
        } finally {
            @unlink($outputPath);
        }
    }

    /**
     * Atomares Schreiben: schlägt die PDF-Erzeugung fehl (kein XML),
     * darf eine bereits existierende Zieldatei NICHT zerstört/truncatet werden.
     */
    public function test_create_pdf_does_not_truncate_target_on_failure(): void {
        if (!$this->writer->isAvailable()) {
            $this->markTestSkipped('TCPDF is not available');
        }

        $outputPath = $this->tempDir . '/zugferd_existing_' . uniqid() . '.pdf';
        $sentinel = 'EXISTING-CONTENT-MUST-SURVIVE';
        file_put_contents($outputPath, $sentinel);

        try {
            // Ohne invoice_xml liefert createPdfString null -> createPdf gibt false zurück,
            // ohne die existierende Datei anzufassen.
            $content = PDFContent::fromHtml('<p>kein XML</p>');
            $ok = $this->writer->createPdf($content, $outputPath);

            $this->assertFalse($ok);
            $this->assertFileExists($outputPath);
            $this->assertSame($sentinel, file_get_contents($outputPath), 'Existing target file must remain intact on failure');
        } finally {
            @unlink($outputPath);
        }
    }

    /**
     * Weitere Dateien (rechnungsbegründende Unterlagen) liegen neben der
     * Rechnungs-XML im PDF/A-3: Beziehung Supplement, MIME als Subtype,
     * beide im /AF-Array des Katalogs.
     */
    #[Group('integration')]
    public function test_attachments_are_embedded_as_supplement(): void {
        if (!$this->writer->isAvailable()) {
            $this->markTestSkipped('TCPDF is not available');
        }

        $content = PDFContent::fromHtml('<h1>Rechnung</h1>', ['invoice_xml' => $this->sampleInvoiceXml()]);
        $pdf = $this->writer->createPdfString($content, [
            'render_engine' => ZugferdWriter::ENGINE_TCPDF,
            'attachments' => [
                ['name' => 'nachweis-1.pdf', 'content' => "%PDF-1.4\n% Leistungsnachweis\n", 'mime' => 'application/pdf', 'description' => 'Leistungsnachweis Oktober 2026'],
                ['name' => 'nachweis-2.csv', 'content' => "datum;minuten\n2026-10-02;90\n", 'mime' => 'text/csv'],
            ],
        ]);

        $this->assertNotNull($pdf);
        $this->assertStringContainsString('nachweis-1.pdf', $pdf);
        $this->assertStringContainsString('nachweis-2.csv', $pdf);
        $this->assertSame(1, substr_count($pdf, '/AFRelationship /Alternative'), 'Only the invoice XML is the alternative representation');
        $this->assertSame(2, substr_count($pdf, '/AFRelationship /Supplement'));
        $this->assertStringContainsString('/Subtype /application#2Fpdf', $pdf);
        $this->assertStringContainsString('/Subtype /text#2Fcsv', $pdf);
        $this->assertMatchesRegularExpression('#/AF \[ \d+ 0 R \d+ 0 R \d+ 0 R \]#', $pdf, 'Catalog /AF must list invoice and both attachments');
    }

    /**
     * Ein PDF-Leser (pdfdetach) findet Rechnung und Unterlagen und liefert
     * den Inhalt unverändert zurück.
     */
    #[Group('integration')]
    public function test_attachments_are_readable_with_pdfdetach(): void {
        if (!$this->writer->isAvailable()) {
            $this->markTestSkipped('TCPDF is not available');
        }
        if (!PDFAttachmentHelper::isAvailable()) {
            $this->markTestSkipped('pdfdetach is not available');
        }

        $outputPath = $this->tempDir . '/zugferd_attach_' . uniqid() . '.pdf';
        $extractDir = $this->tempDir . '/zugferd_attach_' . uniqid();
        mkdir($extractDir);

        try {
            $content = PDFContent::fromHtml('<h1>Rechnung</h1>', ['invoice_xml' => $this->sampleInvoiceXml()]);
            $ok = $this->writer->createPdf($content, $outputPath, [
                'render_engine' => ZugferdWriter::ENGINE_TCPDF,
                'attachments' => [['name' => 'nachweis-1.csv', 'content' => "datum;minuten\n2026-10-02;90\n", 'mime' => 'text/csv']],
            ]);
            $this->assertTrue($ok);

            $this->assertSame(['factur-x.xml', 'nachweis-1.csv'], PDFAttachmentHelper::list($outputPath));

            $files = PDFAttachmentHelper::extractAll($outputPath, $extractDir);
            $csv = array_values(array_filter($files, static fn (string $f): bool => str_ends_with($f, 'nachweis-1.csv')));
            $this->assertCount(1, $csv);
            $this->assertSame("datum;minuten\n2026-10-02;90\n", file_get_contents($csv[0]));
        } finally {
            @unlink($outputPath);
            foreach (glob($extractDir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($extractDir);
        }
    }

    /**
     * Name der Rechnungs-XML, doppelte Namen ohne Rücksicht auf die
     * Schreibweise und leere Inhalte werden übersprungen; eine unbekannte
     * Beziehung fällt auf Supplement zurück.
     */
    #[Group('integration')]
    public function test_invalid_attachments_are_skipped(): void {
        if (!$this->writer->isAvailable()) {
            $this->markTestSkipped('TCPDF is not available');
        }

        $content = PDFContent::fromHtml('<h1>Rechnung</h1>', ['invoice_xml' => $this->sampleInvoiceXml()]);
        $pdf = $this->writer->createPdfString($content, [
            'render_engine' => ZugferdWriter::ENGINE_TCPDF,
            'attachments' => [
                ['name' => 'FACTUR-X.XML', 'content' => '<x/>', 'mime' => 'text/xml'],
                ['name' => 'stunden.csv', 'content' => "a;b\n", 'mime' => 'text/csv', 'relationship' => 'Foo'],
                ['name' => 'STUNDEN.csv', 'content' => "c;d\n", 'mime' => 'text/csv'],
                ['name' => 'leer.pdf', 'content' => ''],
                'kein-array',
            ],
        ]);

        $this->assertNotNull($pdf);
        $this->assertStringNotContainsString('FACTUR-X.XML', $pdf);
        $this->assertStringContainsString('stunden.csv', $pdf);
        $this->assertStringNotContainsString('STUNDEN.csv', $pdf);
        $this->assertStringNotContainsString('leer.pdf', $pdf);
        $this->assertSame(1, substr_count($pdf, '/AFRelationship /Supplement'));
    }

    /**
     * Die Rechnungs-XML wird im XMP als Factur-X erklärt: Dateiname, Version
     * und die Stufe aus 'zugferd_profile'.
     */
    #[Group('integration')]
    public function test_invoice_is_declared_in_the_xmp_metadata(): void {
        if (!$this->writer->isAvailable()) {
            $this->markTestSkipped('TCPDF is not available');
        }

        $content = PDFContent::fromHtml('<h1>Rechnung</h1>', ['invoice_xml' => $this->sampleInvoiceXml()]);
        $pdf = $this->writer->createPdfString($content, ['render_engine' => ZugferdWriter::ENGINE_TCPDF, 'zugferd_profile' => ZugferdWriter::LEVEL_EXTENDED]);

        $this->assertNotNull($pdf);
        $this->assertStringContainsString('xmlns:fx="urn:factur-x:pdfa:CrossIndustryDocument:invoice:1p0#"', $pdf);
        $this->assertStringContainsString('<fx:DocumentFileName>factur-x.xml</fx:DocumentFileName>', $pdf);
        $this->assertStringContainsString('<fx:ConformanceLevel>EXTENDED</fx:ConformanceLevel>', $pdf);
    }

    /**
     * Ein vorhandenes PDF wird mit neuer Rechnungs-XML neu geschrieben: Seiten
     * und Text bleiben, die bisherige Rechnung und ihre Anhänge weichen den
     * neuen, keine Büroklammer kommt auf die Seite.
     */
    #[Group('integration')]
    public function test_embed_into_pdf_keeps_pages_and_replaces_the_invoice(): void {
        $dir = $this->importWorkDir();
        try {
            $source = PdfProbe::labelled($this, $dir, 2);
            $first = $this->writer->embedIntoPdf($source, $this->sampleInvoiceXml(), [
                'attachments' => [['name' => 'alt.csv', 'content' => "a;b\n", 'mime' => 'text/csv']],
            ]);
            $this->assertNotNull($first);
            file_put_contents($dir . '/first.pdf', $first);

            $newXml = str_replace('RE-2026-0001', 'RE-2026-0002', $this->sampleInvoiceXml());
            $second = $this->writer->embedIntoPdf($dir . '/first.pdf', $newXml, [
                'zugferd_profile' => ZugferdWriter::LEVEL_EN16931,
                'title' => 'Rechnung RE-2026-0002',
                'attachments' => [['name' => 'nachweis.csv', 'content' => "datum;minuten\n2026-10-02;90\n", 'mime' => 'text/csv', 'description' => 'Leistungsnachweis']],
            ]);
            $this->assertNotNull($second);
            file_put_contents($dir . '/second.pdf', $second);

            $this->assertSame(2, PDFHelper::getPageCount($dir . '/second.pdf'));
            $text = (string) shell_exec('pdftotext ' . escapeshellarg($dir . '/second.pdf') . ' -');
            $this->assertStringContainsString('P1-R1C1', $text);
            $this->assertStringContainsString('P2-R5C2', $text);

            $this->assertSame(['factur-x.xml', 'nachweis.csv'], PDFAttachmentHelper::list($dir . '/second.pdf'));
            $this->assertSame(['filename' => 'factur-x.xml', 'xml' => $newXml], (new ZugferdReader)->extractInvoice($dir . '/second.pdf'));

            $this->assertSame(1, substr_count($second, '/AFRelationship /Alternative'));
            $this->assertSame(1, substr_count($second, '/AFRelationship /Supplement'));
            $this->assertStringContainsString('<fx:ConformanceLevel>EN 16931</fx:ConformanceLevel>', $second);
            $this->assertStringContainsString('<pdfaid:part>3</pdfaid:part>', $second);
            $this->assertStringNotContainsString('/FileAttachment', $second, 'Pages stay as they were');
            $this->assertStringContainsString('Rechnung RE-2026-0002', $second);
        } finally {
            PdfProbe::removeDir($dir);
        }
    }

    /**
     * Name und Stufe der Rechnung folgen den Optionen, etwa für eine
     * XRechnung im ZUGFeRD-Profil XRECHNUNG.
     */
    #[Group('integration')]
    public function test_embed_into_pdf_uses_the_given_invoice_filename_and_level(): void {
        $dir = $this->importWorkDir();
        try {
            $source = PdfProbe::labelled($this, $dir, 1);
            $pdf = $this->writer->embedIntoPdf($source, $this->sampleInvoiceXml(), ['invoice_filename' => 'xrechnung.xml', 'zugferd_profile' => 'XRECHNUNG']);

            $this->assertNotNull($pdf);
            $this->assertStringContainsString('<fx:DocumentFileName>xrechnung.xml</fx:DocumentFileName>', $pdf);
            $this->assertStringContainsString('<fx:ConformanceLevel>XRECHNUNG</fx:ConformanceLevel>', $pdf);
            file_put_contents($dir . '/out.pdf', $pdf);
            $this->assertSame(['xrechnung.xml'], PDFAttachmentHelper::list($dir . '/out.pdf'));
        } finally {
            PdfProbe::removeDir($dir);
        }
    }

    /**
     * Die übernommenen Seiten sehen aus wie vorher, auch eine gedrehte (/Rotate 90).
     */
    #[Group('integration')]
    public function test_embed_into_pdf_renders_the_pages_as_before(): void {
        $dir = $this->importWorkDir();
        if (trim((string) shell_exec('command -v qpdf')) === '' || trim((string) shell_exec('command -v pdftoppm')) === '') {
            PdfProbe::removeDir($dir);
            $this->markTestSkipped('qpdf or pdftoppm is not available');
        }
        try {
            exec(sprintf('qpdf --rotate=+90:1 %s %s 2>&1', escapeshellarg(PdfProbe::labelled($this, $dir, 2)), escapeshellarg($dir . '/rotated.pdf')), $output, $code);
            $this->assertSame(0, $code, implode("\n", $output));

            $pdf = $this->writer->embedIntoPdf($dir . '/rotated.pdf', $this->sampleInvoiceXml());
            $this->assertNotNull($pdf);
            file_put_contents($dir . '/out.pdf', $pdf);

            foreach ([1, 2] as $page) {
                $this->assertSame($this->renderGray($dir . '/rotated.pdf', $page), $this->renderGray($dir . '/out.pdf', $page), "Page {$page}");
            }
        } finally {
            PdfProbe::removeDir($dir);
        }
    }

    /**
     * Querverweise und Objekte in komprimierten Strömen (PDF 1.5) liest der Import direkt.
     */
    #[Group('integration')]
    public function test_embed_into_pdf_reads_object_streams(): void {
        $dir = $this->importWorkDir();
        if (trim((string) shell_exec('command -v qpdf')) === '') {
            PdfProbe::removeDir($dir);
            $this->markTestSkipped('qpdf is not available');
        }
        try {
            $source = PdfProbe::labelled($this, $dir, 2);
            exec(sprintf('qpdf --object-streams=generate %s %s 2>&1', escapeshellarg($source), escapeshellarg($dir . '/objstm.pdf')), $output, $code);
            $this->assertSame(0, $code, implode("\n", $output));
            $this->assertStringContainsString('/ObjStm', (string) file_get_contents($dir . '/objstm.pdf'));

            $pdf = $this->writer->embedIntoPdf($dir . '/objstm.pdf', $this->sampleInvoiceXml());
            $this->assertNotNull($pdf);
            file_put_contents($dir . '/out.pdf', $pdf);
            $this->assertSame(2, PDFHelper::getPageCount($dir . '/out.pdf'));
            $this->assertSame(['factur-x.xml'], PDFAttachmentHelper::list($dir . '/out.pdf'));
        } finally {
            PdfProbe::removeDir($dir);
        }
    }

    /**
     * Scheitert der Import an den Querverweisen, bereinigt mutool die Datei
     * und der zweite Versuch gelingt.
     */
    #[Group('integration')]
    public function test_embed_into_pdf_repairs_a_broken_cross_reference(): void {
        $dir = $this->importWorkDir();
        if (!PDFOptimizeHelper::isCleanAvailable()) {
            PdfProbe::removeDir($dir);
            $this->markTestSkipped('mutool (mutool-clean-repair) is not available');
        }
        try {
            $source = PdfProbe::labelled($this, $dir, 2);
            $broken = (string) preg_replace('/startxref\s+\d+/', "startxref\n999999", (string) file_get_contents($source));
            file_put_contents($dir . '/broken.pdf', $broken);

            $pdf = $this->writer->embedIntoPdf($dir . '/broken.pdf', $this->sampleInvoiceXml());
            $this->assertNotNull($pdf);
            file_put_contents($dir . '/out.pdf', $pdf);
            $this->assertSame(2, PDFHelper::getPageCount($dir . '/out.pdf'));
        } finally {
            PdfProbe::removeDir($dir);
        }
    }

    #[Group('integration')]
    public function test_embed_into_pdf_returns_null_without_source_or_xml(): void {
        if (!$this->writer->isImportAvailable()) {
            $this->markTestSkipped('No PDF import available');
        }

        $this->assertNull($this->writer->embedIntoPdf('/nonexistent/rechnung.pdf', $this->sampleInvoiceXml()));

        $dir = $this->importWorkDir();
        try {
            $this->assertNull($this->writer->embedIntoPdf(PdfProbe::labelled($this, $dir, 1), '  '));
        } finally {
            PdfProbe::removeDir($dir);
        }
    }

    /**
     * Eine echte PDF und eine ZUGFeRD-PDF als Beilage: Die Engine legt sie im
     * PDF/A-3-Modus roh ab, ihre "N 0 obj"-Zeilen dürfen die Querverweise
     * nicht verbiegen - auf beiden Wegen.
     *
     * @return array<string, array{string, string}>
     */
    public static function pdfAttachments(): array {
        return [
            'TCPDF-PDF, createPdfString' => ['plain', 'create'],
            'TCPDF-PDF, embedIntoPdf' => ['plain', 'import'],
            'ZUGFeRD-PDF, createPdfString' => ['zugferd', 'create'],
            'ZUGFeRD-PDF, embedIntoPdf' => ['zugferd', 'import'],
        ];
    }

    #[DataProvider('pdfAttachments')]
    #[Group('integration')]
    public function test_pdf_attachment_keeps_the_file_readable(string $attachmentKind, string $way): void {
        $dir = $this->importWorkDir();
        if (trim((string) shell_exec('command -v qpdf')) === '') {
            PdfProbe::removeDir($dir);
            $this->markTestSkipped('qpdf is not available');
        }
        try {
            $attachment = $attachmentKind === 'plain' ? $this->tcpdfDocument(2) : $this->zugferdDocument();
            $this->assertMatchesRegularExpression('/\n\d+ 0 obj\n/', $attachment, 'Precondition: the attachment carries object headers');
            $options = ['attachments' => [['name' => 'beilage.pdf', 'content' => $attachment, 'mime' => 'application/pdf']]];

            if ($way === 'create') {
                $pdf = $this->writer->createPdfString(PDFContent::fromHtml('<h1>Rechnung</h1>', ['invoice_xml' => $this->sampleInvoiceXml()]), $options + ['render_engine' => ZugferdWriter::ENGINE_TCPDF]);
                $pages = 1;
            } else {
                $pdf = $this->writer->embedIntoPdf(PdfProbe::labelled($this, $dir, 2), $this->sampleInvoiceXml(), $options);
                $pages = 2;
            }
            $this->assertNotNull($pdf);
            $path = $dir . '/out.pdf';
            file_put_contents($path, $pdf);

            $this->assertSame($pages, PDFHelper::getPageCount($path));
            $this->assertSame((string) $pages, trim((string) shell_exec('qpdf --show-npages ' . escapeshellarg($path) . ' 2>&1')));
            exec('qpdf --check ' . escapeshellarg($path) . ' 2>&1', $check, $code);
            $this->assertSame(0, $code, implode("\n", $check));
            $this->assertStringNotContainsString('WARNING', implode("\n", $check));

            $this->assertSame(['beilage.pdf', 'factur-x.xml'], PDFAttachmentHelper::list($path));
            $reader = new ZugferdReader;
            $this->assertSame($attachment, $reader->extractAttachment($path, 'beilage.pdf'), 'Attachment comes back byte for byte');
            $this->assertSame(['filename' => 'factur-x.xml', 'xml' => $this->sampleInvoiceXml()], $reader->extractInvoice($path));
        } finally {
            PdfProbe::removeDir($dir);
        }
    }

    private function tcpdfDocument(int $pages): string {
        $pdf = new \TCPDF;
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        for ($page = 1; $page <= $pages; $page++) {
            $pdf->AddPage();
            $pdf->Write(5, "Leistungsnachweis, Seite {$page}");
        }

        return (string) $pdf->Output('', 'S');
    }

    private function zugferdDocument(): string {
        $pdf = $this->writer->createPdfString(PDFContent::fromHtml('<h1>Vorgängerrechnung</h1>', ['invoice_xml' => $this->sampleInvoiceXml()]), [
            'render_engine' => ZugferdWriter::ENGINE_TCPDF,
            'attachments' => [['name' => 'nachweis.pdf', 'content' => $this->tcpdfDocument(1), 'mime' => 'application/pdf']],
        ]);
        $this->assertNotNull($pdf);

        return $pdf;
    }

    /**
     * Graustufenbild einer Seite (PGM). pdftoppm schreibt nicht auf stdout, daher über eine Datei neben dem PDF.
     */
    private function renderGray(string $pdf, int $page): string {
        $root = $pdf . '.p' . $page;
        exec(sprintf('pdftoppm -r 30 -gray -singlefile -f %d -l %d %s %s 2>&1', $page, $page, escapeshellarg($pdf), escapeshellarg($root)), $output, $code);
        $this->assertSame(0, $code, implode("\n", $output));
        $image = (string) file_get_contents($root . '.pgm');
        unlink($root . '.pgm');
        $this->assertStringStartsWith('P5', $image, "Page {$page} of {$pdf} was not rendered");

        return $image;
    }

    private function importWorkDir(): string {
        if (!$this->writer->isImportAvailable()) {
            $this->markTestSkipped('No PDF import available');
        }
        if (!PdfProbe::toolsAvailable() || !PDFAttachmentHelper::isAvailable()) {
            $this->markTestSkipped('gs, pdftotext, pdfinfo or pdfdetach is not available');
        }
        $dir = $this->tempDir . '/zugferd_import_' . bin2hex(random_bytes(6));
        mkdir($dir, 0700);

        return $dir;
    }

    private function sampleInvoiceXml(): string {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<rsm:CrossIndustryInvoice xmlns:rsm="urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100">'
            . '<rsm:ExchangedDocument><ram:ID>RE-2026-0001</ram:ID></rsm:ExchangedDocument>'
            . '</rsm:CrossIndustryInvoice>';
    }
}
