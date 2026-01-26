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
use PHPUnit\Framework\TestCase;

final class PDFHelperTest extends TestCase {
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
}
