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
use PDFToolkit\Writers\ZugferdWriter;
use PHPUnit\Framework\Attributes\Group;
use Tests\Contracts\BaseTestCase;

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

    private function sampleInvoiceXml(): string {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<rsm:CrossIndustryInvoice xmlns:rsm="urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100">'
            . '<rsm:ExchangedDocument><ram:ID>RE-2026-0001</ram:ID></rsm:ExchangedDocument>'
            . '</rsm:CrossIndustryInvoice>';
    }
}
