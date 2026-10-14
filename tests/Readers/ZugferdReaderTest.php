<?php
/*
 * Created on   : Wed Jan 22 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : ZugferdReaderTest.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace Tests\Readers;

use PDFToolkit\Readers\ZugferdReader;
use Tests\Contracts\BaseTestCase;

/**
 * Tests for ZUGFeRD PDF Reader.
 */
class ZugferdReaderTest extends BaseTestCase {
    private ZugferdReader $reader;

    protected function setUp(): void {
        $this->reader = new ZugferdReader;
    }

    public function test_is_available_returns_bool(): void {
        $result = $this->reader->isAvailable();
        $this->assertIsBool($result);
    }

    public function test_list_attachments_returns_array(): void {
        if (!$this->reader->isAvailable()) {
            $this->markTestSkipped('pdfdetach or pdftk not available');
        }

        // Ohne echte ZUGFeRD-PDF können wir nur das Interface testen
        $tempPdf = $this->createTempPdf();

        try {
            $attachments = $this->reader->listAttachments($tempPdf);
            $this->assertIsArray($attachments);
        } finally {
            unlink($tempPdf);
        }
    }

    public function test_is_zugferd_pdf_with_non_zugferd_pdf(): void {
        if (!$this->reader->isAvailable()) {
            $this->markTestSkipped('pdfdetach or pdftk not available');
        }

        $tempPdf = $this->createTempPdf();

        try {
            $result = $this->reader->isZugferdPdf($tempPdf);
            $this->assertFalse($result, 'Regular PDF should not be detected as ZUGFeRD');
        } finally {
            unlink($tempPdf);
        }
    }

    public function test_extract_invoice_xml_returns_null_for_non_zugferd_pdf(): void {
        if (!$this->reader->isAvailable()) {
            $this->markTestSkipped('pdfdetach or pdftk not available');
        }

        $tempPdf = $this->createTempPdf();

        try {
            $xml = $this->reader->extractInvoiceXml($tempPdf);
            $this->assertNull($xml, 'Should return null for PDF without embedded invoice');
        } finally {
            unlink($tempPdf);
        }
    }

    public function test_extract_invoice_xml_returns_null_for_non_existent_file(): void {
        $xml = $this->reader->extractInvoiceXml('/non/existent/file.pdf');
        $this->assertNull($xml);
    }

    /**
     * Der Anhangname stammt aus dem Dokument: "../name.xml" darf nicht neben
     * das Arbeitsverzeichnis schreiben (Pfad aus dem Namen gebaut), der Inhalt
     * kommt trotzdem an.
     */
    public function test_anhangname_mit_pfad_schreibt_nicht_ausserhalb(): void {
        if (!$this->reader->isAvailable()) {
            $this->markTestSkipped('pdfdetach or pdftk not available');
        }
        $name = 'pdftk-trav-' . bin2hex(random_bytes(6)) . '.xml';
        $outside = sys_get_temp_dir() . '/' . $name;
        $content = '<rsm:CrossIndustryInvoice xmlns:rsm="urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100"/>';
        $pdf = $this->createPdfWithAttachment('../' . $name, $content);

        try {
            $this->assertSame(['../' . $name], $this->reader->listAttachments($pdf));
            $this->assertSame($content, trim((string) $this->reader->extractAttachment($pdf, '../' . $name)));
            $this->assertFileDoesNotExist($outside, 'Anhang neben das Temp-Verzeichnis geschrieben');
            $this->assertSame($content, trim((string) $this->reader->extractInvoiceXml($pdf)));
            $this->assertFileDoesNotExist($outside);
        } finally {
            @unlink($pdf);
            @unlink($outside);
        }
    }

    /**
     * Minimales PDF mit einer eingebetteten Datei (EmbeddedFiles-Namensbaum).
     */
    private function createPdfWithAttachment(string $name, string $content): string {
        $escaped = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $name);
        $objects = [
            "<</Type/Catalog/Pages 2 0 R/Names<</EmbeddedFiles<</Names[({$escaped}) 4 0 R]>>>>>>",
            '<</Type/Pages/Count 1/Kids[3 0 R]>>',
            '<</Type/Page/MediaBox[0 0 200 200]/Parent 2 0 R>>',
            "<</Type/Filespec/F({$escaped})/UF({$escaped})/EF<</F 5 0 R>>>>",
            '<</Type/EmbeddedFile/Subtype/text#2Fxml/Length ' . strlen($content) . ">>stream\n{$content}\nendstream",
        ];
        $pdf = "%PDF-1.7\n";
        $offsets = [];
        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1) . " 0 obj\n{$object}\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= 'xref' . "\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        $pdf .= 'trailer<</Size ' . (count($objects) + 1) . "/Root 1 0 R>>\nstartxref\n{$xref}\n%%EOF";

        $file = sys_get_temp_dir() . '/test_' . bin2hex(random_bytes(8)) . '.pdf';
        file_put_contents($file, $pdf);

        return $file;
    }

    /**
     * Creates a minimal PDF for testing.
     */
    private function createTempPdf(): string {
        $tempFile = sys_get_temp_dir() . '/test_' . uniqid() . '.pdf';

        // Minimales gültiges PDF
        $pdf = "%PDF-1.4\n";
        $pdf .= "1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n";
        $pdf .= "2 0 obj<</Type/Pages/Count 1/Kids[3 0 R]>>endobj\n";
        $pdf .= "3 0 obj<</Type/Page/MediaBox[0 0 612 792]/Parent 2 0 R>>endobj\n";
        $pdf .= "xref\n0 4\n0000000000 65535 f\n0000000009 00000 n\n0000000052 00000 n\n0000000101 00000 n\n";
        $pdf .= "trailer<</Size 4/Root 1 0 R>>\nstartxref\n178\n%%EOF";

        file_put_contents($tempFile, $pdf);

        return $tempFile;
    }
}
