<?php
/*
 * Created on   : Mon Apr 07 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : PDFCropHelperTest.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace Tests\Helper;

use PDFToolkit\Helper\PDFCropHelper;
use Tests\Contracts\BaseTestCase;

final class PDFCropHelperTest extends BaseTestCase {
    private const SAMPLE_PDF = __DIR__ . '/../../.samples/PDF/test-a4-text.pdf';

    /** A4 in Punkten */
    private const A4_WIDTH = 595.28;
    private const A4_HEIGHT = 841.89;

    /** Toleranz für Dimensionsvergleiche in Punkten (~0.5mm) */
    private const TOLERANCE = 2.0;

    private string $outputDir;

    protected function setUp(): void {
        if (!file_exists(self::SAMPLE_PDF)) {
            $this->markTestSkipped('Sample PDF test-a4-text.pdf nicht vorhanden');
        }

        if (!PDFCropHelper::isAvailable()) {
            $this->markTestSkipped('Ghostscript (gs-crop) nicht verfügbar');
        }

        $this->outputDir = sys_get_temp_dir() . '/pdf-crop-test-' . uniqid();
        mkdir($this->outputDir, 0755, true);
    }

    protected function tearDown(): void {
        if (isset($this->outputDir) && is_dir($this->outputDir)) {
            array_map('unlink', glob($this->outputDir . '/*.pdf') ?: []);
            rmdir($this->outputDir);
        }
    }

    private function outputPath(string $name): string {
        return $this->outputDir . '/' . $name . '.pdf';
    }

    private function assertPdfDimensions(string $path, float $expectedWidth, float $expectedHeight, string $message = ''): void {
        $dims = PDFCropHelper::getPageDimensions($path);
        $this->assertNotNull($dims, 'Konnte Seitenmaße nicht lesen: ' . $path);

        $prefix = $message ? $message . ': ' : '';
        $this->assertEqualsWithDelta($expectedWidth, $dims['width'], self::TOLERANCE, $prefix . 'Breite');
        $this->assertEqualsWithDelta($expectedHeight, $dims['height'], self::TOLERANCE, $prefix . 'Höhe');
    }

    private function assertPdfSmaller(string $outputPath, float $originalWidth, float $originalHeight): void {
        $dims = PDFCropHelper::getPageDimensions($outputPath);
        $this->assertNotNull($dims);
        $this->assertLessThan($originalWidth + self::TOLERANCE, $dims['width'], 'Breite sollte kleiner oder gleich sein');
        $this->assertLessThan($originalHeight + self::TOLERANCE, $dims['height'], 'Höhe sollte kleiner oder gleich sein');
        $this->assertTrue(
            $dims['width'] < $originalWidth - self::TOLERANCE || $dims['height'] < $originalHeight - self::TOLERANCE,
            'Mindestens eine Dimension muss tatsächlich kleiner sein'
        );
    }

    // ===================================================================
    // getPageDimensions
    // ===================================================================

    public function test_get_page_dimensions_returns_a4(): void {
        $dims = PDFCropHelper::getPageDimensions(self::SAMPLE_PDF);
        $this->assertNotNull($dims);
        $this->assertEqualsWithDelta(self::A4_WIDTH, $dims['width'], self::TOLERANCE);
        $this->assertEqualsWithDelta(self::A4_HEIGHT, $dims['height'], self::TOLERANCE);
    }

    public function test_get_page_dimensions_returns_null_for_invalid_file(): void {
        $this->assertNull(PDFCropHelper::getPageDimensions('/nonexistent/file.pdf'));
    }

    // ===================================================================
    // normalizeMargins
    // ===================================================================

    public function test_normalize_margins_one_value(): void {
        $result = PDFCropHelper::normalizeMargins([10.0]);
        $this->assertSame([10.0, 10.0, 10.0, 10.0], $result);
    }

    public function test_normalize_margins_two_values(): void {
        $result = PDFCropHelper::normalizeMargins([10.0, 20.0]);
        $this->assertSame([10.0, 20.0, 10.0, 20.0], $result);
    }

    public function test_normalize_margins_three_values(): void {
        $result = PDFCropHelper::normalizeMargins([10.0, 20.0, 30.0]);
        $this->assertSame([10.0, 20.0, 30.0, 20.0], $result);
    }

    public function test_normalize_margins_four_values(): void {
        $result = PDFCropHelper::normalizeMargins([10.0, 20.0, 30.0, 40.0]);
        $this->assertSame([10.0, 20.0, 30.0, 40.0], $result);
    }

    public function test_normalize_margins_empty_returns_zeros(): void {
        $result = PDFCropHelper::normalizeMargins([]);
        $this->assertSame([0.0, 0.0, 0.0, 0.0], $result);
    }

    // ===================================================================
    // cropToBox – Dokument wird kleiner
    // ===================================================================

    public function test_crop_to_box_creates_small_file(): void {
        $out = $this->outputPath('crop-box');

        $result = PDFCropHelper::cropToBox(
            self::SAMPLE_PDF,
            $out,
            50.0,   // x
            400.0,  // y
            200.0,  // width
            200.0,  // height
        );

        $this->assertTrue($result);
        $this->assertFileExists($out);
        $this->assertPdfDimensions($out, 200.0, 200.0, 'cropToBox 200x200');
    }

    public function test_crop_to_box_rejects_missing_input(): void {
        $this->assertFalse(PDFCropHelper::cropToBox('/no/such.pdf', $this->outputPath('x'), 0, 0, 100, 100));
    }

    // ===================================================================
    // cropUpperHalf – obere Hälfte
    // ===================================================================

    public function test_crop_upper_half_reduces_height(): void {
        $out = $this->outputPath('upper-half');

        $this->assertTrue(PDFCropHelper::cropUpperHalf(self::SAMPLE_PDF, $out));
        $this->assertFileExists($out);

        $expectedHeight = self::A4_HEIGHT / 2;
        $this->assertPdfDimensions($out, self::A4_WIDTH, $expectedHeight, 'Obere Hälfte');
    }

    // ===================================================================
    // cropLowerHalf – untere Hälfte
    // ===================================================================

    public function test_crop_lower_half_reduces_height(): void {
        $out = $this->outputPath('lower-half');

        $this->assertTrue(PDFCropHelper::cropLowerHalf(self::SAMPLE_PDF, $out));
        $this->assertFileExists($out);

        $expectedHeight = self::A4_HEIGHT / 2;
        $this->assertPdfDimensions($out, self::A4_WIDTH, $expectedHeight, 'Untere Hälfte');
    }

    // ===================================================================
    // cropUpperPercent / cropLowerPercent mit verschiedenen Prozenten
    // ===================================================================

    public function test_crop_upper_percent30(): void {
        $out = $this->outputPath('upper-30');

        $this->assertTrue(PDFCropHelper::cropUpperPercent(self::SAMPLE_PDF, $out, 30.0));
        $this->assertFileExists($out);

        $expectedHeight = self::A4_HEIGHT * 0.30;
        $this->assertPdfDimensions($out, self::A4_WIDTH, $expectedHeight, 'Obere 30%');
    }

    public function test_crop_lower_percent70(): void {
        $out = $this->outputPath('lower-70');

        $this->assertTrue(PDFCropHelper::cropLowerPercent(self::SAMPLE_PDF, $out, 70.0));
        $this->assertFileExists($out);

        $expectedHeight = self::A4_HEIGHT * 0.70;
        $this->assertPdfDimensions($out, self::A4_WIDTH, $expectedHeight, 'Untere 70%');
    }

    // ===================================================================
    // cropUpperPercent mit Margins – Dokument wird zusätzlich verkleinert
    // ===================================================================

    public function test_crop_upper_percent_with_uniform_margin(): void {
        $out = $this->outputPath('upper-50-margin-uniform');
        $margin = 20.0; // 20pt allseitig

        $this->assertTrue(PDFCropHelper::cropUpperPercent(self::SAMPLE_PDF, $out, 50.0, 1, [$margin]));
        $this->assertFileExists($out);

        $expectedWidth = self::A4_WIDTH - 2 * $margin;
        $expectedHeight = (self::A4_HEIGHT * 0.50) - 2 * $margin;
        $this->assertPdfDimensions($out, $expectedWidth, $expectedHeight, 'Obere 50% mit 20pt Margin');
    }

    public function test_crop_upper_percent_with_asymmetric_margins(): void {
        $out = $this->outputPath('upper-50-margin-asym');
        // top=10, right=30, bottom=20, left=40
        $margins = [10.0, 30.0, 20.0, 40.0];

        $this->assertTrue(PDFCropHelper::cropUpperPercent(self::SAMPLE_PDF, $out, 50.0, 1, $margins));
        $this->assertFileExists($out);

        $expectedWidth = self::A4_WIDTH - 40.0 - 30.0;  // -left -right
        $expectedHeight = (self::A4_HEIGHT * 0.50) - 10.0 - 20.0; // -top -bottom
        $this->assertPdfDimensions($out, $expectedWidth, $expectedHeight, 'Obere 50% mit asymmetrischen Margins');
    }

    public function test_crop_lower_percent_with_two_value_margins(): void {
        $out = $this->outputPath('lower-50-margin-2val');
        // top/bottom=15, left/right=25
        $margins = [15.0, 25.0];

        $this->assertTrue(PDFCropHelper::cropLowerPercent(self::SAMPLE_PDF, $out, 50.0, 1, $margins));
        $this->assertFileExists($out);

        $expectedWidth = self::A4_WIDTH - 2 * 25.0;
        $expectedHeight = (self::A4_HEIGHT * 0.50) - 2 * 15.0;
        $this->assertPdfDimensions($out, $expectedWidth, $expectedHeight, 'Untere 50% mit 2-Wert-Margins');
    }

    public function test_crop_with_margins_is_smaller(): void {
        $outNoMargin = $this->outputPath('upper-no-margin');
        $outWithMargin = $this->outputPath('upper-with-margin');

        PDFCropHelper::cropUpperPercent(self::SAMPLE_PDF, $outNoMargin, 50.0);
        PDFCropHelper::cropUpperPercent(self::SAMPLE_PDF, $outWithMargin, 50.0, 1, [30.0]);

        $dimsNo = PDFCropHelper::getPageDimensions($outNoMargin);
        $dimsWith = PDFCropHelper::getPageDimensions($outWithMargin);

        $this->assertNotNull($dimsNo);
        $this->assertNotNull($dimsWith);
        $this->assertLessThan($dimsNo['width'], $dimsWith['width'], 'Mit Margins muss die Breite kleiner sein');
        $this->assertLessThan($dimsNo['height'], $dimsWith['height'], 'Mit Margins muss die Höhe kleiner sein');
    }

    // ===================================================================
    // Einzelne Margin-Richtungen isoliert testen
    // ===================================================================

    public function test_crop_with_top_margin_only(): void {
        $out = $this->outputPath('margin-top-only');
        // top=30, right=0, bottom=0, left=0
        $margins = [30.0, 0.0, 0.0, 0.0];
        $cropHeight = self::A4_HEIGHT * 0.50;

        $this->assertTrue(PDFCropHelper::cropUpperPercent(self::SAMPLE_PDF, $out, 50.0, 1, $margins));
        $this->assertFileExists($out);

        $expectedWidth = self::A4_WIDTH;                  // unverändert
        $expectedHeight = $cropHeight - 30.0;             // nur top abgezogen
        $this->assertPdfDimensions($out, $expectedWidth, $expectedHeight, 'Nur Top-Margin');
    }

    public function test_crop_with_bottom_margin_only(): void {
        $out = $this->outputPath('margin-bottom-only');
        // top=0, right=0, bottom=40, left=0
        $margins = [0.0, 0.0, 40.0, 0.0];
        $cropHeight = self::A4_HEIGHT * 0.50;

        $this->assertTrue(PDFCropHelper::cropUpperPercent(self::SAMPLE_PDF, $out, 50.0, 1, $margins));
        $this->assertFileExists($out);

        $expectedWidth = self::A4_WIDTH;                  // unverändert
        $expectedHeight = $cropHeight - 40.0;             // nur bottom abgezogen
        $this->assertPdfDimensions($out, $expectedWidth, $expectedHeight, 'Nur Bottom-Margin');
    }

    public function test_crop_with_left_margin_only(): void {
        $out = $this->outputPath('margin-left-only');
        // top=0, right=0, bottom=0, left=50
        $margins = [0.0, 0.0, 0.0, 50.0];
        $cropHeight = self::A4_HEIGHT * 0.50;

        $this->assertTrue(PDFCropHelper::cropUpperPercent(self::SAMPLE_PDF, $out, 50.0, 1, $margins));
        $this->assertFileExists($out);

        $expectedWidth = self::A4_WIDTH - 50.0;           // nur left abgezogen
        $expectedHeight = $cropHeight;                     // unverändert
        $this->assertPdfDimensions($out, $expectedWidth, $expectedHeight, 'Nur Left-Margin');
    }

    public function test_crop_with_right_margin_only(): void {
        $out = $this->outputPath('margin-right-only');
        // top=0, right=35, bottom=0, left=0
        $margins = [0.0, 35.0, 0.0, 0.0];
        $cropHeight = self::A4_HEIGHT * 0.50;

        $this->assertTrue(PDFCropHelper::cropUpperPercent(self::SAMPLE_PDF, $out, 50.0, 1, $margins));
        $this->assertFileExists($out);

        $expectedWidth = self::A4_WIDTH - 35.0;           // nur right abgezogen
        $expectedHeight = $cropHeight;                     // unverändert
        $this->assertPdfDimensions($out, $expectedWidth, $expectedHeight, 'Nur Right-Margin');
    }

    public function test_crop_lower_percent_with_top_margin_only(): void {
        $out = $this->outputPath('lower-margin-top-only');
        $margins = [25.0, 0.0, 0.0, 0.0];
        $cropHeight = self::A4_HEIGHT * 0.50;

        $this->assertTrue(PDFCropHelper::cropLowerPercent(self::SAMPLE_PDF, $out, 50.0, 1, $margins));
        $this->assertFileExists($out);

        $expectedWidth = self::A4_WIDTH;
        $expectedHeight = $cropHeight - 25.0;
        $this->assertPdfDimensions($out, $expectedWidth, $expectedHeight, 'Lower 50% nur Top-Margin');
    }

    public function test_crop_lower_percent_with_left_and_right_margins(): void {
        $out = $this->outputPath('lower-margin-lr');
        // top=0, right=20, bottom=0, left=30
        $margins = [0.0, 20.0, 0.0, 30.0];
        $cropHeight = self::A4_HEIGHT * 0.50;

        $this->assertTrue(PDFCropHelper::cropLowerPercent(self::SAMPLE_PDF, $out, 50.0, 1, $margins));
        $this->assertFileExists($out);

        $expectedWidth = self::A4_WIDTH - 30.0 - 20.0;
        $expectedHeight = $cropHeight;
        $this->assertPdfDimensions($out, $expectedWidth, $expectedHeight, 'Lower 50% Links+Rechts Margin');
    }

    // ===================================================================
    // Crop mit Margins → resizeToFitCentered ins Ursprungsformat
    // ===================================================================

    public function test_crop_with_margins_resized_centered_back_to_a4(): void {
        $cropped = $this->outputPath('pipeline-crop-margins');
        $final = $this->outputPath('pipeline-back-to-a4');

        // 1. Obere 50 % mit asymmetrischen Margins croppen → deutlich kleiner als A4
        $margins = [20.0, 30.0, 10.0, 40.0];
        $this->assertTrue(PDFCropHelper::cropUpperPercent(self::SAMPLE_PDF, $cropped, 50.0, 1, $margins));

        $croppedDims = PDFCropHelper::getPageDimensions($cropped);
        $this->assertNotNull($croppedDims);
        $this->assertLessThan(self::A4_WIDTH, $croppedDims['width']);
        $this->assertLessThan(self::A4_HEIGHT, $croppedDims['height']);

        // 2. Zentriert zurück auf A4 skalieren
        $this->assertTrue(PDFCropHelper::resizeToFitCentered($cropped, $final, self::A4_WIDTH, self::A4_HEIGHT));
        $this->assertFileExists($final);
        $this->assertPdfDimensions($final, self::A4_WIDTH, self::A4_HEIGHT, 'Zurück zu A4 zentriert');
    }

    public function test_crop_with_uniform_margin_resized_centered_to_label(): void {
        $cropped = $this->outputPath('pipeline-uniform-crop');
        $final = $this->outputPath('pipeline-label-fit');

        // 1. Obere 50 % mit gleichmäßigem Rand
        $this->assertTrue(PDFCropHelper::cropUpperPercent(self::SAMPLE_PDF, $cropped, 50.0, 1, [25.0]));

        // 2. Zentriert auf Versandetiketten-Größe (100×62mm ≈ 283×176pt)
        $labelW = 283.46;
        $labelH = 175.75;
        $this->assertTrue(PDFCropHelper::resizeToFitCentered($cropped, $final, $labelW, $labelH));
        $this->assertFileExists($final);
        $this->assertPdfDimensions($final, $labelW, $labelH, 'Zentriert auf Etikettengröße');
    }

    public function test_crop_lower_with_margins_resized_centered_to_square(): void {
        $cropped = $this->outputPath('pipeline-lower-crop-margins');
        $final = $this->outputPath('pipeline-square');

        // 1. Untere 40 % mit Margins
        $margins = [15.0, 10.0, 15.0, 10.0];
        $this->assertTrue(PDFCropHelper::cropLowerPercent(self::SAMPLE_PDF, $cropped, 40.0, 1, $margins));

        // 2. Zentriert auf Quadrat 200×200pt
        $this->assertTrue(PDFCropHelper::resizeToFitCentered($cropped, $final, 200.0, 200.0));
        $this->assertFileExists($final);
        $this->assertPdfDimensions($final, 200.0, 200.0, 'Lower Crop → Quadrat zentriert');
    }

    // ===================================================================
    // resizeToFit – Skalierung auf Zielgröße
    // ===================================================================

    public function test_resize_to_fit_creates_target_size(): void {
        $out = $this->outputPath('resize-fit');
        $targetW = 400.0;
        $targetH = 300.0;

        $this->assertTrue(PDFCropHelper::resizeToFit(self::SAMPLE_PDF, $out, $targetW, $targetH));
        $this->assertFileExists($out);
        $this->assertPdfDimensions($out, $targetW, $targetH, 'resizeToFit Zielgröße');
    }

    public function test_resize_to_fit_makes_document_smaller(): void {
        $out = $this->outputPath('resize-smaller');
        // Hälfte der A4-Größe
        $targetW = self::A4_WIDTH / 2;
        $targetH = self::A4_HEIGHT / 2;

        $this->assertTrue(PDFCropHelper::resizeToFit(self::SAMPLE_PDF, $out, $targetW, $targetH));
        $this->assertFileExists($out);
        $this->assertPdfSmaller($out, self::A4_WIDTH, self::A4_HEIGHT);
    }

    public function test_resize_to_fit_rejects_invalid_input(): void {
        $this->assertFalse(PDFCropHelper::resizeToFit('/no/file.pdf', $this->outputPath('x'), 100, 100));
    }

    // ===================================================================
    // resizeToFitCentered – Zentriert auf Zielgröße
    // ===================================================================

    public function test_resize_to_fit_centered_creates_target_size(): void {
        $out = $this->outputPath('resize-centered');
        $targetW = 400.0;
        $targetH = 600.0;

        $this->assertTrue(PDFCropHelper::resizeToFitCentered(self::SAMPLE_PDF, $out, $targetW, $targetH));
        $this->assertFileExists($out);
        $this->assertPdfDimensions($out, $targetW, $targetH, 'resizeToFitCentered Zielgröße');
    }

    public function test_resize_to_fit_centered_small_target(): void {
        $out = $this->outputPath('resize-centered-small');
        // Sehr klein: 100x150pt
        $targetW = 100.0;
        $targetH = 150.0;

        $this->assertTrue(PDFCropHelper::resizeToFitCentered(self::SAMPLE_PDF, $out, $targetW, $targetH));
        $this->assertFileExists($out);
        $this->assertPdfDimensions($out, $targetW, $targetH, 'resizeToFitCentered kleine Zielgröße');
    }

    public function test_resize_to_fit_centered_square_target(): void {
        $out = $this->outputPath('resize-centered-square');
        $targetW = 300.0;
        $targetH = 300.0;

        $this->assertTrue(PDFCropHelper::resizeToFitCentered(self::SAMPLE_PDF, $out, $targetW, $targetH));
        $this->assertFileExists($out);
        $this->assertPdfDimensions($out, $targetW, $targetH, 'resizeToFitCentered quadratisch');
    }

    public function test_resize_to_fit_centered_landscape_target(): void {
        $out = $this->outputPath('resize-centered-landscape');
        // Breit und flach
        $targetW = 600.0;
        $targetH = 200.0;

        $this->assertTrue(PDFCropHelper::resizeToFitCentered(self::SAMPLE_PDF, $out, $targetW, $targetH));
        $this->assertFileExists($out);
        $this->assertPdfDimensions($out, $targetW, $targetH, 'resizeToFitCentered Querformat');
    }

    public function test_resize_to_fit_centered_rejects_invalid_input(): void {
        $this->assertFalse(PDFCropHelper::resizeToFitCentered('/no/file.pdf', $this->outputPath('x'), 100, 100));
    }

    // ===================================================================
    // Kombination: Crop + Resize (Pipeline-Test)
    // ===================================================================

    public function test_crop_then_resize_centered_pipeline(): void {
        $cropped = $this->outputPath('pipeline-cropped');
        $final = $this->outputPath('pipeline-final');

        // 1. Obere Hälfte croppen
        $this->assertTrue(PDFCropHelper::cropUpperHalf(self::SAMPLE_PDF, $cropped));

        // 2. Ergebnis zentriert auf 10x10cm skalieren
        $targetW = 283.46; // ~10cm
        $targetH = 283.46;
        $this->assertTrue(PDFCropHelper::resizeToFitCentered($cropped, $final, $targetW, $targetH));

        $this->assertFileExists($final);
        $this->assertPdfDimensions($final, $targetW, $targetH, 'Pipeline: Crop→Resize');

        // Ergebnis muss kleiner als Original sein
        $this->assertPdfSmaller($final, self::A4_WIDTH, self::A4_HEIGHT);
    }

    // ===================================================================
    // isAvailable
    // ===================================================================

    public function test_is_available_returns_bool(): void {
        $this->assertIsBool(PDFCropHelper::isAvailable());
    }
}
