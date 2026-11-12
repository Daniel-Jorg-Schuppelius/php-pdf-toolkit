<?php
/*
 * Created on   : Thu Nov 12 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : RasterLimitTest.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace Tests\Helper;

use InvalidArgumentException;
use PDFToolkit\Entities\PageSize;
use PDFToolkit\Enums\PaperFormat;
use PDFToolkit\Helper\{PDFHelper, PDFOcrHelper, PDFOptimizeHelper, PDFPageHelper, RasterLimit, TesseractPageOcr};
use Tests\Contracts\BaseTestCase;

/**
 * Pixelgrenze der Rasterwege (0.32.1): Eine PDF mit A4-CropBox und
 * 9000 x 9000 pt MediaBox besteht jede Prüfung über pdfinfos Seitengröße,
 * pdftoppm rastert aber die MediaBox - mit 200 dpi 25000 x 25000 Pixel,
 * 2,4 GB Speicher je Seite. Die Rendertests stellen die Grenze klein
 * (wenige Megapixel), damit kein Test selbst viel Speicher belegt.
 */
final class RasterLimitTest extends BaseTestCase {
    private const BOMB = ['media' => '0 0 9000 9000', 'crop' => '0 0 595 842'];
    private const A4 = ['media' => '0 0 595 842'];

    private string $workDir;

    protected function setUp(): void {
        parent::setUp();
        RasterLimit::reset();
        $this->workDir = sys_get_temp_dir() . '/raster-limit-test-' . bin2hex(random_bytes(6));
        mkdir($this->workDir, 0700, true);
    }

    protected function tearDown(): void {
        RasterLimit::reset();
        if (isset($this->workDir) && is_dir($this->workDir)) {
            PdfProbe::removeDir($this->workDir);
        }
    }

    private function requirePoppler(): void {
        foreach (['pdfinfo', 'pdftoppm'] as $tool) {
            if (trim((string) shell_exec('command -v ' . $tool)) === '') {
                $this->markTestSkipped($tool . ' (poppler-utils) nicht verfügbar');
            }
        }
    }

    /** @param list<array{media: string, crop?: string, rotate?: int}> $pages */
    private function pdf(string $name, array $pages): string {
        return PdfProbe::boxed($this->workDir . '/' . $name, $pages);
    }

    /** @return array{0: int, 1: int} Breite und Höhe eines Bildes */
    private function imageSize(string $path): array {
        $info = getimagesize($path);
        $this->assertNotFalse($info, 'Kein Bild: ' . $path);

        return [$info[0], $info[1]];
    }

    // ---------------------------------------------------------------- Grenze

    public function test_default_limit_is_read_from_the_settings(): void {
        $this->assertSame(RasterLimit::DEFAULT_MAX_PIXELS, RasterLimit::maxPixels());
        $this->assertSame(150_000_000, RasterLimit::DEFAULT_MAX_PIXELS);
    }

    public function test_configure_overrides_the_setting_until_reset(): void {
        RasterLimit::configure(2_000_000);
        $this->assertSame(2_000_000, RasterLimit::maxPixels());

        RasterLimit::configure(null);
        $this->assertSame(RasterLimit::DEFAULT_MAX_PIXELS, RasterLimit::maxPixels());
    }

    public function test_limit_cannot_be_switched_off(): void {
        $this->expectException(InvalidArgumentException::class);
        RasterLimit::configure(0);
    }

    public function test_pixels_follow_pdftoppm_rounding(): void {
        // pdftoppm -r 300 auf A4 (595 x 842 pt): 2480 x 3509 Pixel
        $this->assertSame(2480.0 * 3509.0, (new PageSize(595, 842))->pixelsAt(300));
        // Drehung tauscht nur die Kanten
        $this->assertSame((new PageSize(595, 842))->pixelsAt(300), (new PageSize(595, 842, 1, 90))->pixelsAt(300));
    }

    public function test_ordinary_pages_keep_their_resolution(): void {
        $this->assertSame(600.0, RasterLimit::dpiForSize(PageSize::fromFormat(PaperFormat::A4), 600));
        $this->assertSame(600.0, RasterLimit::dpiForSize(PageSize::fromFormat(PaperFormat::A3), 600));
        // A0 mit 300 dpi: 139 MP, unter der Vorgabe
        $this->assertSame(300.0, RasterLimit::dpiForSize(PageSize::fromFormat(PaperFormat::A0), 300));
    }

    public function test_oversized_page_gets_the_highest_resolution_within_the_limit(): void {
        $bomb = new PageSize(9000, 9000);

        $dpi = RasterLimit::dpiForSize($bomb, 200);

        $this->assertNotNull($dpi);
        $this->assertLessThan(200, $dpi);
        $this->assertLessThanOrEqual(RasterLimit::DEFAULT_MAX_PIXELS, $bomb->pixelsAt($dpi));
        $this->assertGreaterThan(RasterLimit::DEFAULT_MAX_PIXELS, $bomb->pixelsAt($dpi + 0.01), 'Auflösung unnötig tief gesenkt');
    }

    public function test_page_too_large_even_at_one_dpi_is_not_rastered(): void {
        $this->assertNull(RasterLimit::dpiForSize(new PageSize(1.0e7, 1.0e7), 72));
    }

    public function test_dpi_is_formatted_for_pdftoppm(): void {
        $this->assertSame('300', RasterLimit::format(300.0));
        $this->assertSame('97.97', RasterLimit::format(97.97));
        $this->assertSame('97.5', RasterLimit::format(97.5));
    }

    // ------------------------------------------------------------ Seitenmaße

    public function test_page_boxes_show_the_media_box_behind_a_small_crop_box(): void {
        $this->requirePoppler();
        $bomb = $this->pdf('bomb.pdf', [self::BOMB]);

        // Rückwärtskompatibel: die Seitengröße bleibt die CropBox
        $sizes = PDFHelper::getAllPageSizes($bomb);
        $this->assertEqualsWithDelta(595.0, $sizes[1]->widthPt, 0.01);
        $this->assertEqualsWithDelta(842.0, $sizes[1]->heightPt, 0.01);

        $boxes = PDFHelper::getAllPageBoxes($bomb);
        $this->assertSame([1], array_keys($boxes));
        $this->assertEqualsWithDelta(9000.0, $boxes[1]->mediaBox->widthPt, 0.01);
        $this->assertEqualsWithDelta(595.0, $boxes[1]->cropBox->widthPt, 0.01);
        $this->assertEqualsWithDelta(9000.0, $boxes[1]->largest()->widthPt, 0.01);
        $this->assertEqualsWithDelta(9000.0, $boxes[1]->largest()->heightPt, 0.01);
    }

    public function test_page_boxes_carry_rotation_and_offsets(): void {
        $this->requirePoppler();
        $pdf = $this->pdf('rotated.pdf', [
            ['media' => '0 0 9000 4000', 'crop' => '0 0 595 842', 'rotate' => 90],
            ['media' => '-100 -200 495 642'],
        ]);

        $boxes = PDFHelper::getAllPageBoxes($pdf);

        $this->assertSame(90, $boxes[1]->rotation);
        $largest = $boxes[1]->largest();
        $this->assertEqualsWithDelta(4000.0, $largest->displayWidthPt(), 0.01);
        $this->assertEqualsWithDelta(9000.0, $largest->displayHeightPt(), 0.01);
        $this->assertEqualsWithDelta(595.0, $boxes[2]->mediaBox->widthPt, 0.01);
        $this->assertEqualsWithDelta(842.0, $boxes[2]->mediaBox->heightPt, 0.01);
    }

    public function test_page_boxes_cover_a_range_also_without_pdf_extension(): void {
        $this->requirePoppler();
        $pdf = $this->pdf('three.pdf', [self::A4, self::BOMB, self::A4]);
        $noExtension = $this->workDir . '/upload';
        copy($pdf, $noExtension);

        $this->assertSame([2, 3], array_keys(PDFHelper::getAllPageBoxes($noExtension, 2)));
        $this->assertSame([2], array_keys(PDFHelper::getAllPageBoxes($pdf, 2, 2)));
        $this->assertSame([1, 2, 3], array_keys(PDFHelper::getAllPageBoxes($pdf, 1, 99)));
        $this->assertSame([], PDFHelper::getAllPageBoxes($pdf, 4));
    }

    public function test_unreadable_page_sizes_stop_rastering(): void {
        $this->requirePoppler();
        $garbage = $this->workDir . '/kaputt.pdf';
        file_put_contents($garbage, "%PDF-1.4\nkein PDF\n");
        // pdfinfo schreibt "inf" für eine Zahl mit 400 Stellen
        $overflow = $this->pdf('overflow.pdf', [['media' => '0 0 ' . str_repeat('9', 400) . ' 842']]);

        foreach ([$garbage, $overflow] as $pdf) {
            $this->assertSame([], PDFHelper::getAllPageBoxes($pdf), basename($pdf));
            $this->assertNull(RasterLimit::dpiFor($pdf, 72), basename($pdf));
            $this->assertFalse(RasterLimit::fits($pdf, 72), basename($pdf));
        }
        $this->assertNull(PDFHelper::renderPageToImage($overflow, 1, 72));
        $this->assertSame([], PDFPageHelper::renderPages($overflow, $this->workDir . '/overflow'));
    }

    // ------------------------------------------------------------- Rastern

    public function test_bomb_gets_a_resolution_within_the_default_limit(): void {
        $this->requirePoppler();
        $bomb = $this->pdf('bomb.pdf', [self::BOMB]);

        $dpi = RasterLimit::dpiFor($bomb, 200);

        // Ohne Grenze: 25000 x 25000 = 625 MP; gerastert wird hier nicht
        $this->assertNotNull($dpi);
        $this->assertLessThan(100, $dpi);
        $this->assertLessThanOrEqual(RasterLimit::DEFAULT_MAX_PIXELS, PDFHelper::getAllPageBoxes($bomb)[1]->largest()->pixelsAt($dpi));
        $this->assertSame(200.0, RasterLimit::dpiFor($this->pdf('a4.pdf', [self::A4]), 200));
    }

    public function test_render_page_to_image_stays_within_the_limit(): void {
        $this->requirePoppler();
        RasterLimit::configure(2_000_000);
        $bomb = $this->pdf('bomb.pdf', [self::BOMB]);

        $mediaBox = PDFHelper::renderPageToImage($bomb, 1, 200, $this->workDir . '/media');
        $cropBox = PDFHelper::renderPageToImage($bomb, 1, 200, $this->workDir . '/crop', cropBox: true);

        $this->assertNotNull($mediaBox);
        [$width, $height] = $this->imageSize($mediaBox);
        $this->assertLessThanOrEqual(2_000_000, $width * $height);
        $this->assertSame($width, $height, 'ohne -cropbox die quadratische MediaBox');

        $this->assertNotNull($cropBox);
        [$width, $height] = $this->imageSize($cropBox);
        $this->assertEqualsWithDelta(842 / 595, $height / $width, 0.01, 'mit -cropbox die A4-Seite');

        // Gewöhnliche Seiten bleiben, wie sie waren
        $a4 = PDFHelper::renderPageToImage($this->pdf('a4.pdf', [self::A4]), 1, 100, $this->workDir . '/a4');
        $this->assertNotNull($a4);
        $this->assertSame([827, 1170], $this->imageSize($a4));
    }

    public function test_render_pages_lower_the_resolution_of_the_whole_range(): void {
        $this->requirePoppler();
        RasterLimit::configure(3_000_000);
        $pdf = $this->pdf('mixed.pdf', [self::A4, self::BOMB]);

        $images = PDFPageHelper::renderPages($pdf, $this->workDir . '/pages', 'png', 150);
        $single = PDFPageHelper::renderPages($pdf, $this->workDir . '/single', 'png', 150, 1, 1);

        $this->assertCount(2, $images);
        foreach ($images as $image) {
            [$width, $height] = $this->imageSize($image);
            $this->assertLessThanOrEqual(3_000_000, $width * $height);
        }
        // Die A4-Seite teilt die gesenkte Auflösung
        $this->assertLessThan(1240, $this->imageSize($images[0])[0]);
        // Ohne die übergroße Seite im Bereich bleibt es bei 150 dpi
        $this->assertSame([1240, 1755], $this->imageSize($single[0]));
    }

    public function test_thumbnails_show_the_visible_page(): void {
        $this->requirePoppler();
        $bomb = $this->pdf('bomb.pdf', [self::BOMB]);

        $thumbnails = PDFPageHelper::thumbnails($bomb, $this->workDir . '/thumbs', 24);
        $images = PDFPageHelper::renderPages($bomb, $this->workDir . '/media', 'png', 24);

        // 24 dpi: A4-CropBox 199 x 281, MediaBox 3000 x 3000
        $this->assertSame([199, 281], $this->imageSize($thumbnails[0]));
        $this->assertSame([3000, 3000], $this->imageSize($images[0]));
    }

    public function test_ocr_rendering_stays_within_the_limit(): void {
        $this->requirePoppler();
        if (trim((string) shell_exec('command -v tesseract')) === '') {
            $this->markTestSkipped('tesseract nicht verfügbar');
        }
        RasterLimit::configure(1_000_000);
        $bomb = $this->pdf('bomb.pdf', [self::BOMB]);

        $ocr = TesseractPageOcr::tsvPerPage($bomb, 'eng', 300, 6);

        $this->assertNull($ocr['error']);
        // TSV-Ebene 1 nennt die Bildgröße, die Tesseract bekam: pdftoppm lieferte
        // höchstens 1000 x 1000 (ohne Grenze 37500 x 37500), das Geraderichten
        // (mogrify -deskew) legt danach einen schmalen Rand an
        $this->assertMatchesRegularExpression('/^1\t1\t0\t0\t0\t0\t0\t0\t\d+\t\d+\t/m', $ocr['pages'][1]);
        preg_match('/^1\t1\t0\t0\t0\t0\t0\t0\t(\d+)\t(\d+)\t/m', $ocr['pages'][1], $matches);
        $this->assertLessThanOrEqual(1010, (int) $matches[1]);
        $this->assertLessThanOrEqual(1010, (int) $matches[2]);
    }

    public function test_ocrmypdf_paths_refuse_oversized_pages_before_starting(): void {
        $this->requirePoppler();
        $bomb = $this->pdf('bomb.pdf', [self::BOMB]);
        $output = $this->workDir . '/ocr.pdf';

        $messages = null;
        $this->assertFalse(PDFOcrHelper::makeSearchable($bomb, $output, true, 'eng', null, null, $messages));
        $this->assertNotEmpty($messages);
        $this->assertStringContainsString('zu groß', $messages[0]);
        $this->assertFileDoesNotExist($output);

        // 9000 pt mit 400 dpi: 2,5 Gigapixel; A4: 15,5 MP
        $this->assertFalse(RasterLimit::fits($bomb, RasterLimit::OCRMYPDF_DPI));
        $this->assertTrue(RasterLimit::fits($this->pdf('a4.pdf', [self::A4]), RasterLimit::OCRMYPDF_DPI));
        // Nur die genannten Seiten zählen
        $mixed = $this->pdf('mixed.pdf', [self::A4, self::BOMB]);
        $this->assertTrue(RasterLimit::fits($mixed, RasterLimit::OCRMYPDF_DPI, [1]));
        $this->assertFalse(RasterLimit::fits($mixed, RasterLimit::OCRMYPDF_DPI, [1, 2]));

        if (PDFOptimizeHelper::isPdfAAvailable()) {
            $this->assertFalse(PDFOptimizeHelper::toPdfA($bomb, $this->workDir . '/pdfa.pdf'));
            $this->assertFileDoesNotExist($this->workDir . '/pdfa.pdf');
        }
    }
}
