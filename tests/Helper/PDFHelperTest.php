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

use PDFToolkit\Entities\PageSize;
use PDFToolkit\Enums\PaperFormat;
use PDFToolkit\Helper\PDFHelper;
use Tests\Contracts\BaseTestCase;

final class PDFHelperTest extends BaseTestCase {
    private const SAMPLE_PDF = __DIR__ . '/../../.samples/PDF/test-text.pdf';

    public function test_is_valid_pdf_with_valid_file(): void {
        if (!file_exists(self::SAMPLE_PDF)) {
            $this->markTestSkipped('Sample PDF nicht vorhanden');
        }

        $this->assertTrue(PDFHelper::isValidPdf(self::SAMPLE_PDF));
    }

    public function test_is_valid_pdf_with_non_existent_file(): void {
        $this->assertFalse(PDFHelper::isValidPdf('/nonexistent/file.pdf'));
    }

    public function test_is_valid_pdf_with_non_pdf_file(): void {
        $tempFile = sys_get_temp_dir() . '/test.txt';
        file_put_contents($tempFile, 'This is not a PDF');

        $this->assertFalse(PDFHelper::isValidPdf($tempFile));

        unlink($tempFile);
    }

    public function test_get_pdf_version_returns_version(): void {
        if (!file_exists(self::SAMPLE_PDF)) {
            $this->markTestSkipped('Sample PDF nicht vorhanden');
        }

        $version = PDFHelper::getPdfVersion(self::SAMPLE_PDF);
        $this->assertNotNull($version);
        $this->assertMatchesRegularExpression('/^\d+\.\d+$/', $version);
    }

    public function test_get_page_count_returns_positive_number(): void {
        if (!file_exists(self::SAMPLE_PDF)) {
            $this->markTestSkipped('Sample PDF nicht vorhanden');
        }

        $count = PDFHelper::getPageCount(self::SAMPLE_PDF);
        $this->assertGreaterThan(0, $count);
    }

    public function test_has_embedded_text_returns_true_for_text_pdf(): void {
        if (!file_exists(self::SAMPLE_PDF)) {
            $this->markTestSkipped('Sample PDF nicht vorhanden');
        }

        $this->assertTrue(PDFHelper::hasEmbeddedText(self::SAMPLE_PDF));
    }

    public function test_is_likely_scanned_returns_false_for_text_pdf(): void {
        if (!file_exists(self::SAMPLE_PDF)) {
            $this->markTestSkipped('Sample PDF nicht vorhanden');
        }

        $this->assertFalse(PDFHelper::isLikelyScanned(self::SAMPLE_PDF));
    }

    public function test_get_metadata_returns_array(): void {
        if (!file_exists(self::SAMPLE_PDF)) {
            $this->markTestSkipped('Sample PDF nicht vorhanden');
        }

        $metadata = PDFHelper::getMetadata(self::SAMPLE_PDF);
        $this->assertIsArray($metadata);
        $this->assertArrayHasKey('Pages', $metadata);
    }

    public function test_is_likely_scanned_returns_true_for_image_pdf(): void {
        $scannedPdf = __DIR__ . '/../../.samples/PDF/test-scanned.pdf';
        if (!file_exists($scannedPdf)) {
            $this->markTestSkipped('Scanned PDF nicht vorhanden');
        }

        $this->assertTrue(PDFHelper::isLikelyScanned($scannedPdf));
    }

    public function test_has_embedded_text_returns_false_for_image_pdf(): void {
        $scannedPdf = __DIR__ . '/../../.samples/PDF/test-scanned.pdf';
        if (!file_exists($scannedPdf)) {
            $this->markTestSkipped('Scanned PDF nicht vorhanden');
        }

        $this->assertFalse(PDFHelper::hasEmbeddedText($scannedPdf));
    }

    // === Format-Tests ===

    public function test_get_page_size_returns_page_size(): void {
        if (!file_exists(self::SAMPLE_PDF)) {
            $this->markTestSkipped('Sample PDF nicht vorhanden');
        }

        $size = PDFHelper::getPageSize(self::SAMPLE_PDF);
        $this->assertInstanceOf(PageSize::class, $size);
        $this->assertGreaterThan(0, $size->widthPt);
        $this->assertGreaterThan(0, $size->heightPt);
    }

    public function test_get_page_size_returns_null_for_invalid_file(): void {
        $size = PDFHelper::getPageSize('/nonexistent/file.pdf');
        $this->assertNull($size);
    }

    public function test_is_format_with_enum(): void {
        if (!file_exists(self::SAMPLE_PDF)) {
            $this->markTestSkipped('Sample PDF nicht vorhanden');
        }

        // Prüfen ob die Methode funktioniert (Ergebnis hängt vom konkreten PDF ab)
        $result = PDFHelper::isFormat(self::SAMPLE_PDF, PaperFormat::A4);
        $this->assertIsBool($result);
    }

    public function test_is_format_with_string(): void {
        if (!file_exists(self::SAMPLE_PDF)) {
            $this->markTestSkipped('Sample PDF nicht vorhanden');
        }

        $result = PDFHelper::isFormat(self::SAMPLE_PDF, 'A4');
        $this->assertIsBool($result);
    }

    public function test_is_format_returns_false_for_invalid_file(): void {
        $result = PDFHelper::isFormat('/nonexistent/file.pdf', 'A4');
        $this->assertFalse($result);
    }

    public function test_detect_format_returns_format(): void {
        if (!file_exists(self::SAMPLE_PDF)) {
            $this->markTestSkipped('Sample PDF nicht vorhanden');
        }

        $format = PDFHelper::detectFormat(self::SAMPLE_PDF);
        // Kann PaperFormat oder null sein (je nach PDF)
        $this->assertTrue($format === null || $format instanceof PaperFormat);
    }

    public function test_detect_format_returns_null_for_invalid_file(): void {
        $format = PDFHelper::detectFormat('/nonexistent/file.pdf');
        $this->assertNull($format);
    }

    public function test_is_landscape_returns_bool(): void {
        if (!file_exists(self::SAMPLE_PDF)) {
            $this->markTestSkipped('Sample PDF nicht vorhanden');
        }

        $result = PDFHelper::isLandscape(self::SAMPLE_PDF);
        $this->assertIsBool($result);
    }

    public function test_is_portrait_returns_bool(): void {
        if (!file_exists(self::SAMPLE_PDF)) {
            $this->markTestSkipped('Sample PDF nicht vorhanden');
        }

        $result = PDFHelper::isPortrait(self::SAMPLE_PDF);
        $this->assertIsBool($result);
    }

    public function test_is_landscape_and_is_portrait_are_mutually_exclusive(): void {
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

    public function test_get_all_page_sizes_returns_array(): void {
        if (!file_exists(self::SAMPLE_PDF)) {
            $this->markTestSkipped('Sample PDF nicht vorhanden');
        }

        $sizes = PDFHelper::getAllPageSizes(self::SAMPLE_PDF);
        $this->assertIsArray($sizes);
        $this->assertNotEmpty($sizes);

        $pageCount = PDFHelper::getPageCount(self::SAMPLE_PDF);
        $this->assertCount($pageCount, $sizes);
    }

    public function test_has_uniform_page_size_returns_bool(): void {
        if (!file_exists(self::SAMPLE_PDF)) {
            $this->markTestSkipped('Sample PDF nicht vorhanden');
        }

        $result = PDFHelper::hasUniformPageSize(self::SAMPLE_PDF);
        $this->assertIsBool($result);
    }

    public function test_get_format_description_returns_string(): void {
        if (!file_exists(self::SAMPLE_PDF)) {
            $this->markTestSkipped('Sample PDF nicht vorhanden');
        }

        $desc = PDFHelper::getFormatDescription(self::SAMPLE_PDF);
        $this->assertIsString($desc);
        $this->assertNotEmpty($desc);
    }
}
