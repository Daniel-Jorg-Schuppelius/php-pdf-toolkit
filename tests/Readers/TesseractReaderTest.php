<?php
/*
 * Created on   : Tue Aug 19 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : TesseractReaderTest.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace Tests\Readers;

use PDFToolkit\Readers\TesseractReader;
use Tests\Contracts\BaseTestCase;

/**
 * Tests für den Tesseract-Reader — Schwerpunkt Bild-Direkt-OCR
 * (extractTextFromImage), die ohne PDF-Rasterisierung auskommt.
 */
class TesseractReaderTest extends BaseTestCase {
    private TesseractReader $reader;

    protected function setUp(): void {
        parent::setUp();
        $this->reader = new TesseractReader;
    }

    public function test_psm_fallbacks_prefer_uniform_block_and_skip_current(): void {
        $this->assertSame([6, 4, 1, 11, 12], TesseractReader::psmFallbacksFor(3));
        $this->assertSame([3, 4, 1, 11, 12], TesseractReader::psmFallbacksFor(6));
    }

    public function test_recognition_args_disable_dictionary_and_add_whitelist(): void {
        $this->assertSame([], TesseractReader::recognitionArgs(false));

        $args = TesseractReader::recognitionArgs(true);
        $this->assertContains('load_system_dawg=0', $args);
        $this->assertContains('load_freq_dawg=0', $args);
        $this->assertNotContains('tessedit_char_whitelist=0123456789', $args);

        $args = TesseractReader::recognitionArgs(true, ' 0123456789 ');
        $this->assertSame('tessedit_char_whitelist=0123456789', end($args));
    }

    public function test_ocr_settings_default_to_uniform_block_with_preprocessing(): void {
        $settings = TesseractReader::ocrSettings();

        $this->assertSame(6, $settings['psm']);
        $this->assertSame(6, $settings['rowsPsm']);
        $this->assertSame(300, $settings['dpi']);
        $this->assertTrue($settings['noDict']);
        $this->assertTrue($settings['preprocess']);
        $this->assertFalse($settings['denoise']);
        $this->assertSame('', $settings['whitelist']);
    }

    public function test_is_available_returns_bool(): void {
        $this->assertIsBool($this->reader->isAvailable());
    }

    public function test_extract_text_from_image_reads_rendered_text(): void {
        if (!function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD not available');
        }

        $imagePath = $this->createTextImage('RECHNUNG 4711');
        try {
            $text = $this->reader->extractTextFromImage($imagePath, ['qualityCheck' => false]);
            if ($text === null) {
                $this->markTestSkipped('tesseract not available');
            }

            $this->assertStringContainsString('4711', $text);
        } finally {
            @unlink($imagePath);
        }
    }

    public function test_extract_text_from_image_returns_null_for_blank_image(): void {
        if (!function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD not available');
        }

        $imagePath = $this->createTextImage('');
        try {
            $text = $this->reader->extractTextFromImage($imagePath, ['qualityCheck' => false]);
            // Ohne tesseract null, mit tesseract: leeres Bild → kein Text → null.
            $this->assertNull($text);
        } finally {
            @unlink($imagePath);
        }
    }

    /** Erzeugt ein weißes PNG, optional mit großzügig gerendertem Text (GD-Built-in-Font). */
    private function createTextImage(string $text): string {
        $image = imagecreatetruecolor(900, 220);
        $white = (int) imagecolorallocate($image, 255, 255, 255);
        $black = (int) imagecolorallocate($image, 0, 0, 0);
        imagefilledrectangle($image, 0, 0, 899, 219, $white);

        if ($text !== '') {
            // Built-in-Font 5 ist klein — hochskalieren, damit die OCR sicher trifft.
            $small = imagecreatetruecolor(300, 40);
            imagefilledrectangle($small, 0, 0, 299, 39, $white);
            imagestring($small, 5, 10, 10, $text, $black);
            imagecopyresized($image, $small, 30, 40, 0, 0, 840, 120, 300, 40);
            imagedestroy($small);
        }

        $path = sys_get_temp_dir() . '/tesseract_reader_test_' . uniqid() . '.png';
        imagepng($image, $path);
        imagedestroy($image);

        return $path;
    }
}
