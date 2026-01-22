<?php
/*
 * Created on   : Wed Jan 22 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : ZugferdReaderTest.php
 * License      : MIT License
 * License Uri  : https://opensource.org/license/mit
 */

declare(strict_types=1);

namespace Tests\Readers;

use PDFToolkit\Readers\ZugferdReader;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ZUGFeRD PDF Reader.
 */
class ZugferdReaderTest extends TestCase {
    private ZugferdReader $reader;

    protected function setUp(): void {
        $this->reader = new ZugferdReader();
    }

    public function testIsAvailableReturnsBool(): void {
        $result = $this->reader->isAvailable();
        $this->assertIsBool($result);
    }

    public function testListAttachmentsReturnsArray(): void {
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

    public function testIsZugferdPdfWithNonZugferdPdf(): void {
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

    public function testExtractInvoiceXmlReturnsNullForNonZugferdPdf(): void {
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

    public function testExtractInvoiceXmlReturnsNullForNonExistentFile(): void {
        $xml = $this->reader->extractInvoiceXml('/non/existent/file.pdf');
        $this->assertNull($xml);
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
