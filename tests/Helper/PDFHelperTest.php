<?php
/*
 * Created on   : Wed Jan 22 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : PDFHelperTest.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace Tests\Helper;

use PDFToolkit\Helper\PDFHelper;
use PDFToolkit\Entities\PageSize;
use PDFToolkit\Enums\PaperFormat;
use Tests\Contracts\BaseTestCase;

final class PDFHelperTest extends BaseTestCase {
    private const SAMPLE_PDF = __DIR__ . '/../../.samples/PDF/test-text.pdf';

    public function testIsValidPdfWithValidFile(): void {
        if (!file_exists(self::SAMPLE_PDF)) {
            $this->markTestSkipped('Sample PDF nicht vorhanden');
        }

        $this->assertTrue(PDFHelper::isValidPdf(self::SAMPLE_PDF));
    }

    public function testIsValidPdfWithNonExistentFile(): void {
        $this->assertFalse(PDFHelper::isValidPdf('/nonexistent/file.pdf'));
    }

    public function testIsValidPdfWithNonPdfFile(): void {
        $tempFile = sys_get_temp_dir() . '/test.txt';
        file_put_contents($tempFile, 'This is not a PDF');

        $this->assertFalse(PDFHelper::isValidPdf($tempFile));

        unlink($tempFile);
    }

    public function testGetPdfVersionReturnsVersion(): void {
        if (!file_exists(self::SAMPLE_PDF)) {
            $this->markTestSkipped('Sample PDF nicht vorhanden');
        }

        $version = PDFHelper::getPdfVersion(self::SAMPLE_PDF);
        $this->assertNotNull($version);
        $this->assertMatchesRegularExpression('/^\d+\.\d+$/', $version);
    }

    public function testGetPageCountReturnsPositiveNumber(): void {
        if (!file_exists(self::SAMPLE_PDF)) {
            $this->markTestSkipped('Sample PDF nicht vorhanden');
        }

        $count = PDFHelper::getPageCount(self::SAMPLE_PDF);
        $this->assertGreaterThan(0, $count);
    }

    public function testHasEmbeddedTextReturnsTrueForTextPdf(): void {
        if (!file_exists(self::SAMPLE_PDF)) {
            $this->markTestSkipped('Sample PDF nicht vorhanden');
        }

        $this->assertTrue(PDFHelper::hasEmbeddedText(self::SAMPLE_PDF));
    }

    public function testIsLikelyScannedReturnsFalseForTextPdf(): void {
        if (!file_exists(self::SAMPLE_PDF)) {
            $this->markTestSkipped('Sample PDF nicht vorhanden');
        }

        $this->assertFalse(PDFHelper::isLikelyScanned(self::SAMPLE_PDF));
    }

    public function testGetMetadataReturnsArray(): void {
        if (!file_exists(self::SAMPLE_PDF)) {
            $this->markTestSkipped('Sample PDF nicht vorhanden');
        }

        $metadata = PDFHelper::getMetadata(self::SAMPLE_PDF);
        $this->assertIsArray($metadata);
        $this->assertArrayHasKey('Pages', $metadata);
    }

    public function testIsLikelyScannedReturnsTrueForImagePdf(): void {
        $scannedPdf = __DIR__ . '/../../.samples/PDF/test-scanned.pdf';
        if (!file_exists($scannedPdf)) {
            $this->markTestSkipped('Scanned PDF nicht vorhanden');
        }

        $this->assertTrue(PDFHelper::isLikelyScanned($scannedPdf));
    }

    public function testHasEmbeddedTextReturnsFalseForImagePdf(): void {
        $scannedPdf = __DIR__ . '/../../.samples/PDF/test-scanned.pdf';
        if (!file_exists($scannedPdf)) {
            $this->markTestSkipped('Scanned PDF nicht vorhanden');
        }

        $this->assertFalse(PDFHelper::hasEmbeddedText($scannedPdf));
    }

    // === Format-Tests ===

    public function testGetPageSizeReturnsPageSize(): void {
        if (!file_exists(self::SAMPLE_PDF)) {
            $this->markTestSkipped('Sample PDF nicht vorhanden');
        }

        $size = PDFHelper::getPageSize(self::SAMPLE_PDF);
        $this->assertInstanceOf(PageSize::class, $size);
        $this->assertGreaterThan(0, $size->widthPt);
        $this->assertGreaterThan(0, $size->heightPt);
    }

    public function testGetPageSizeReturnsNullForInvalidFile(): void {
        $size = PDFHelper::getPageSize('/nonexistent/file.pdf');
        $this->assertNull($size);
    }

    public function testIsFormatWithEnum(): void {
        if (!file_exists(self::SAMPLE_PDF)) {
            $this->markTestSkipped('Sample PDF nicht vorhanden');
        }

        // Prüfen ob die Methode funktioniert (Ergebnis hängt vom konkreten PDF ab)
        $result = PDFHelper::isFormat(self::SAMPLE_PDF, PaperFormat::A4);
        $this->assertIsBool($result);
    }

    public function testIsFormatWithString(): void {
        if (!file_exists(self::SAMPLE_PDF)) {
            $this->markTestSkipped('Sample PDF nicht vorhanden');
        }

        $result = PDFHelper::isFormat(self::SAMPLE_PDF, 'A4');
        $this->assertIsBool($result);
    }

    public function testIsFormatReturnsFalseForInvalidFile(): void {
        $result = PDFHelper::isFormat('/nonexistent/file.pdf', 'A4');
        $this->assertFalse($result);
    }

    public function testDetectFormatReturnsFormat(): void {
        if (!file_exists(self::SAMPLE_PDF)) {
            $this->markTestSkipped('Sample PDF nicht vorhanden');
        }

        $format = PDFHelper::detectFormat(self::SAMPLE_PDF);
        // Kann PaperFormat oder null sein (je nach PDF)
        $this->assertTrue($format === null || $format instanceof PaperFormat);
    }

    public function testDetectFormatReturnsNullForInvalidFile(): void {
        $format = PDFHelper::detectFormat('/nonexistent/file.pdf');
        $this->assertNull($format);
    }

    public function testIsLandscapeReturnsBool(): void {
        if (!file_exists(self::SAMPLE_PDF)) {
            $this->markTestSkipped('Sample PDF nicht vorhanden');
        }

        $result = PDFHelper::isLandscape(self::SAMPLE_PDF);
        $this->assertIsBool($result);
    }

    public function testIsPortraitReturnsBool(): void {
        if (!file_exists(self::SAMPLE_PDF)) {
            $this->markTestSkipped('Sample PDF nicht vorhanden');
        }

        $result = PDFHelper::isPortrait(self::SAMPLE_PDF);
        $this->assertIsBool($result);
    }

    public function testIsLandscapeAndIsPortraitAreMutuallyExclusive(): void {
        if (!file_exists(self::SAMPLE_PDF)) {
            $this->markTestSkipped('Sample PDF nicht vorhanden');
        }

        $isLandscape = PDFHelper::isLandscape(self::SAMPLE_PDF);
        $isPortrait = PDFHelper::isPortrait(self::SAMPLE_PDF);

        // Genau eines sollte true sein (außer bei quadratischen PDFs)
        $size = PDFHelper::getPageSize(self::SAMPLE_PDF);
        if ($size !== null && !$size->isSquare()) {
            $this->assertNotEquals($isLandscape, $isPortrait);
        }
    }

    public function testGetAllPageSizesReturnsArray(): void {
        if (!file_exists(self::SAMPLE_PDF)) {
            $this->markTestSkipped('Sample PDF nicht vorhanden');
        }

        $sizes = PDFHelper::getAllPageSizes(self::SAMPLE_PDF);
        $this->assertIsArray($sizes);
        $this->assertNotEmpty($sizes);

        $pageCount = PDFHelper::getPageCount(self::SAMPLE_PDF);
        $this->assertCount($pageCount, $sizes);
    }

    public function testHasUniformPageSizeReturnsBool(): void {
        if (!file_exists(self::SAMPLE_PDF)) {
            $this->markTestSkipped('Sample PDF nicht vorhanden');
        }

        $result = PDFHelper::hasUniformPageSize(self::SAMPLE_PDF);
        $this->assertIsBool($result);
    }

    public function testGetFormatDescriptionReturnsString(): void {
        if (!file_exists(self::SAMPLE_PDF)) {
            $this->markTestSkipped('Sample PDF nicht vorhanden');
        }

        $desc = PDFHelper::getFormatDescription(self::SAMPLE_PDF);
        $this->assertIsString($desc);
        $this->assertNotEmpty($desc);
    }
}
