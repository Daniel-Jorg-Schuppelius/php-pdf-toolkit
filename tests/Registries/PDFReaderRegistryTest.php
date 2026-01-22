<?php
/*
 * Created on   : Wed Jan 22 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : PDFReaderRegistryTest.php
 * License      : MIT License
 * License Uri  : https://opensource.org/license/mit
 */

declare(strict_types=1);

namespace Tests\Registries;

use PDFToolkit\Entities\PDFDocument;
use PDFToolkit\Registries\PDFReaderRegistry;
use PHPUnit\Framework\TestCase;

final class PDFReaderRegistryTest extends TestCase {
    private const SAMPLE_PDF = __DIR__ . '/../../.samples/PDF/test-text.pdf';

    public function testCanInstantiate(): void {
        $registry = new PDFReaderRegistry();
        $this->assertInstanceOf(PDFReaderRegistry::class, $registry);
    }

    public function testCountReturnsInteger(): void {
        $registry = new PDFReaderRegistry();
        $this->assertIsInt($registry->count());
    }

    public function testGetAvailableReaderNamesReturnsArray(): void {
        $registry = new PDFReaderRegistry();
        $names = $registry->getAvailableReaderNames();
        $this->assertIsArray($names);
    }

    public function testHasAvailableReaders(): void {
        $registry = new PDFReaderRegistry();
        $this->assertGreaterThan(0, $registry->count(), 'Mindestens ein Reader sollte verfügbar sein');
    }

    public function testPdftotextReaderIsAvailable(): void {
        $registry = new PDFReaderRegistry();
        $names = $registry->getAvailableReaderNames();
        $this->assertContains('pdftotext', $names, 'pdftotext-Reader sollte verfügbar sein');
    }

    public function testExtractTextFromTextPdf(): void {
        if (!file_exists(self::SAMPLE_PDF)) {
            $this->markTestSkipped('Sample PDF nicht vorhanden: ' . self::SAMPLE_PDF);
        }

        $registry = new PDFReaderRegistry();
        $doc = $registry->extractText(self::SAMPLE_PDF);

        $this->assertInstanceOf(PDFDocument::class, $doc);
        $this->assertNotEmpty($doc->text);
        $this->assertGreaterThan(50, strlen($doc->text), 'PDF sollte Text enthalten');
    }

    public function testExtractTextReturnsCorrectReader(): void {
        if (!file_exists(self::SAMPLE_PDF)) {
            $this->markTestSkipped('Sample PDF nicht vorhanden: ' . self::SAMPLE_PDF);
        }

        $registry = new PDFReaderRegistry();
        $doc = $registry->extractText(self::SAMPLE_PDF);

        $this->assertInstanceOf(PDFDocument::class, $doc);
        $this->assertEquals('pdftotext', $doc->reader, 'Text-PDF sollte mit pdftotext extrahiert werden');
        $this->assertFalse($doc->isScanned, 'Text-PDF sollte nicht als gescannt markiert sein');
    }

    public function testExtractTextContainsExpectedContent(): void {
        if (!file_exists(self::SAMPLE_PDF)) {
            $this->markTestSkipped('Sample PDF nicht vorhanden: ' . self::SAMPLE_PDF);
        }

        $registry = new PDFReaderRegistry();
        $doc = $registry->extractText(self::SAMPLE_PDF);

        $this->assertInstanceOf(PDFDocument::class, $doc);
        // Test-PDF sollte erwarteten Inhalt enthalten
        $this->assertStringContainsString('PHP PDF Toolkit Test Document', $doc->text);
    }

    public function testExtractTextFromScannedPdfWithOcr(): void {
        $scannedPdf = __DIR__ . '/../../.samples/PDF/test-scanned.pdf';
        if (!file_exists($scannedPdf)) {
            $this->markTestSkipped('Scanned PDF nicht vorhanden: ' . $scannedPdf);
        }

        $registry = new PDFReaderRegistry();

        // Prüfen ob OCR-Reader verfügbar sind
        $names = $registry->getAvailableReaderNames();
        $hasOcr = in_array('tesseract', $names) || in_array('ocrmypdf', $names);
        if (!$hasOcr) {
            $this->markTestSkipped('Kein OCR-Reader verfügbar');
        }

        $doc = $registry->extractText($scannedPdf);

        $this->assertInstanceOf(PDFDocument::class, $doc);
        $this->assertTrue($doc->isScanned, 'PDF sollte als gescannt erkannt werden');
        $this->assertStringContainsString('PHP PDF Toolkit', $doc->text);
    }

    public function testScannedPdfUsesOcrReader(): void {
        $scannedPdf = __DIR__ . '/../../.samples/PDF/test-scanned.pdf';
        if (!file_exists($scannedPdf)) {
            $this->markTestSkipped('Scanned PDF nicht vorhanden');
        }

        $registry = new PDFReaderRegistry();
        $doc = $registry->extractText($scannedPdf);

        if ($doc === null) {
            $this->markTestSkipped('OCR-Reader nicht verfügbar');
        }

        // Muss ein OCR-fähiger Reader sein
        $this->assertContains($doc->reader, ['tesseract', 'ocrmypdf'], 'Scanned PDF sollte mit OCR-Reader verarbeitet werden');
    }
}
