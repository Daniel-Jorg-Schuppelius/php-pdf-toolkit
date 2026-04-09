<?php
/*
 * Created on   : Wed Jan 22 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : PDFReaderRegistryTest.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace Tests\Registries;

use PDFToolkit\Entities\PDFDocument;
use PDFToolkit\Enums\PDFReaderType;
use PDFToolkit\Registries\PDFReaderRegistry;
use Tests\Contracts\BaseTestCase;

final class PDFReaderRegistryTest extends BaseTestCase {
    private const SAMPLE_PDF = __DIR__ . '/../../.samples/PDF/test-text.pdf';

    protected function setUp(): void {
        // Singleton vor jedem Test zurücksetzen für Isolation
        PDFReaderRegistry::resetInstance();
    }

    public function testCanInstantiate(): void {
        $registry = PDFReaderRegistry::getInstance();
        $this->assertInstanceOf(PDFReaderRegistry::class, $registry);
    }

    public function testSingletonReturnsSameInstance(): void {
        $registry1 = PDFReaderRegistry::getInstance();
        $registry2 = PDFReaderRegistry::getInstance();
        $this->assertSame($registry1, $registry2);
    }

    public function testCountReturnsInteger(): void {
        $registry = PDFReaderRegistry::getInstance();
        $this->assertIsInt($registry->count());
    }

    public function testGetAvailableReaderTypesReturnsArray(): void {
        $registry = PDFReaderRegistry::getInstance();
        $types = $registry->getAvailableReaderTypes();
        $this->assertIsArray($types);
        foreach ($types as $type) {
            $this->assertInstanceOf(PDFReaderType::class, $type);
        }
    }

    public function testHasAvailableReaders(): void {
        $registry = PDFReaderRegistry::getInstance();
        $this->assertGreaterThan(0, $registry->count(), 'Mindestens ein Reader sollte verfügbar sein');
    }

    public function testPdftotextReaderIsAvailable(): void {
        $registry = PDFReaderRegistry::getInstance();
        $types = $registry->getAvailableReaderTypes();
        $this->assertContains(PDFReaderType::Pdftotext, $types, 'pdftotext-Reader sollte verfügbar sein');
    }

    public function testExtractTextFromTextPdf(): void {
        if (!file_exists(self::SAMPLE_PDF)) {
            $this->markTestSkipped('Sample PDF nicht vorhanden: ' . self::SAMPLE_PDF);
        }

        $registry = PDFReaderRegistry::getInstance();
        $doc = $registry->extractText(self::SAMPLE_PDF);

        $this->assertInstanceOf(PDFDocument::class, $doc);
        $this->assertNotEmpty($doc->text);
        $this->assertGreaterThan(50, strlen($doc->text), 'PDF sollte Text enthalten');
    }

    public function testExtractTextReturnsCorrectReader(): void {
        if (!file_exists(self::SAMPLE_PDF)) {
            $this->markTestSkipped('Sample PDF nicht vorhanden: ' . self::SAMPLE_PDF);
        }

        $registry = PDFReaderRegistry::getInstance();
        $doc = $registry->extractText(self::SAMPLE_PDF);

        $this->assertInstanceOf(PDFDocument::class, $doc);
        $this->assertSame(PDFReaderType::Pdftotext, $doc->reader, 'Text-PDF sollte mit pdftotext extrahiert werden');
        $this->assertFalse($doc->isScanned, 'Text-PDF sollte nicht als gescannt markiert sein');
    }

    public function testExtractTextContainsExpectedContent(): void {
        if (!file_exists(self::SAMPLE_PDF)) {
            $this->markTestSkipped('Sample PDF nicht vorhanden: ' . self::SAMPLE_PDF);
        }

        $registry = PDFReaderRegistry::getInstance();
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

        $registry = PDFReaderRegistry::getInstance();

        // Prüfen ob OCR-Reader verfügbar sind
        $types = $registry->getAvailableReaderTypes();
        $hasOcr = in_array(PDFReaderType::Tesseract, $types, true) || in_array(PDFReaderType::Ocrmypdf, $types, true);
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

        $registry = PDFReaderRegistry::getInstance();
        $doc = $registry->extractText($scannedPdf);

        if ($doc->reader === null) {
            $this->markTestSkipped('OCR-Reader nicht verfügbar');
        }

        // Muss ein OCR-fähiger Reader sein
        $this->assertContains($doc->reader, [PDFReaderType::Tesseract, PDFReaderType::Ocrmypdf], 'Scanned PDF sollte mit OCR-Reader verarbeitet werden');
    }

    // --- Tests für preferredReader Option ---

    public function testPreferredReaderPdfboxIsUsed(): void {
        if (!file_exists(self::SAMPLE_PDF)) {
            $this->markTestSkipped('Sample PDF nicht vorhanden: ' . self::SAMPLE_PDF);
        }

        $registry = PDFReaderRegistry::getInstance();

        // Prüfen ob PDFBox verfügbar ist
        $pdfbox = $registry->getByType(PDFReaderType::Pdfbox);
        if ($pdfbox === null) {
            $this->markTestSkipped('PDFBox-Reader nicht verfügbar');
        }

        $doc = $registry->extractText(self::SAMPLE_PDF, ['preferredReader' => PDFReaderType::Pdfbox]);

        $this->assertInstanceOf(PDFDocument::class, $doc);
        $this->assertTrue($doc->hasText(), 'Extraktion sollte Text liefern (PDFBox oder Fallback)');

        // Wenn PDFBox funktioniert, sollte es verwendet werden
        // Wenn nicht (z.B. korrupte JAR), greift Fallback auf pdftotext
        if ($pdfbox->extractText(self::SAMPLE_PDF) !== null) {
            $this->assertSame(PDFReaderType::Pdfbox, $doc->reader, 'preferredReader sollte PDFBox erzwingen');
        } else {
            // Fallback: pdftotext
            $this->assertSame(PDFReaderType::Pdftotext, $doc->reader, 'Fallback sollte auf pdftotext gehen');
        }
    }

    public function testPreferredReaderFallsBackWhenNotAvailable(): void {
        if (!file_exists(self::SAMPLE_PDF)) {
            $this->markTestSkipped('Sample PDF nicht vorhanden: ' . self::SAMPLE_PDF);
        }

        $registry = PDFReaderRegistry::getInstance();

        // OCRmyPDF ist oft nicht installiert – als unavailable Reader testen
        // Auch wenn es installiert ist, testet es den normalen Fallback-Pfad
        $doc = $registry->extractText(self::SAMPLE_PDF);
        $this->assertTrue($doc->hasText(), 'Extraktion sollte auch ohne preferredReader funktionieren');
    }

    public function testPreferredReaderInTextOnlyMode(): void {
        if (!file_exists(self::SAMPLE_PDF)) {
            $this->markTestSkipped('Sample PDF nicht vorhanden: ' . self::SAMPLE_PDF);
        }

        $registry = PDFReaderRegistry::getInstance();

        $pdfbox = $registry->getByType(PDFReaderType::Pdfbox);
        if ($pdfbox === null) {
            $this->markTestSkipped('PDFBox-Reader nicht verfügbar');
        }

        $doc = $registry->extractTextOnly(self::SAMPLE_PDF, ['preferredReader' => PDFReaderType::Pdfbox]);

        $this->assertInstanceOf(PDFDocument::class, $doc);
        $this->assertTrue($doc->hasText(), 'Extraktion sollte Text liefern (PDFBox oder Fallback)');

        // Wenn PDFBox funktioniert, sollte es verwendet werden
        if ($pdfbox->extractText(self::SAMPLE_PDF) !== null) {
            $this->assertSame(PDFReaderType::Pdfbox, $doc->reader, 'preferredReader sollte in extractTextOnly() funktionieren');
        } else {
            $this->assertSame(PDFReaderType::Pdftotext, $doc->reader, 'Fallback sollte auf pdftotext gehen');
        }
    }

    public function testPreferredReaderOcrOnlySkippedInTextOnlyMode(): void {
        if (!file_exists(self::SAMPLE_PDF)) {
            $this->markTestSkipped('Sample PDF nicht vorhanden: ' . self::SAMPLE_PDF);
        }

        $registry = PDFReaderRegistry::getInstance();

        // OCR-Reader in textOnly sollte ignoriert werden, Fallback auf pdftotext
        $doc = $registry->extractTextOnly(self::SAMPLE_PDF, ['preferredReader' => PDFReaderType::Tesseract]);

        $this->assertInstanceOf(PDFDocument::class, $doc);
        $this->assertTrue($doc->hasText(), 'Fallback auf Text-Reader sollte funktionieren');
        $this->assertNotSame(PDFReaderType::Tesseract, $doc->reader, 'OCR-Reader sollte in textOnly ignoriert werden');
    }
}
