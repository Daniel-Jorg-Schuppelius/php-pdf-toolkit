<?php
/*
 * Created on   : Wed Oct 14 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : TesseractReaderDeskewTest.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace Tests\Readers;

use PDFToolkit\Config\Config;
use PDFToolkit\Readers\TesseractReader;
use Tests\Contracts\BaseTestCase;

/**
 * Bild-OCR richtet nur schiefe Bilder gerade: das Neuabtasten eines geraden
 * Scans verwischt dünne Zeichen wie Kommas in Beträgen.
 */
class TesseractReaderDeskewTest extends BaseTestCase {
    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    public function test_gerades_bild_wird_nicht_gedreht_schiefes_schon(): void {
        if (!function_exists('imagecreatetruecolor') || !Config::getInstance()->isExecutableAvailable('imagemagick-deskew-angle')) {
            $this->markTestSkipped('GD oder ImageMagick nicht verfuegbar');
        }

        $straight = $this->createLinesImage(0.0);
        $skewed = $this->createLinesImage(2.0);

        $this->assertFalse(TesseractReader::needsDeskew(Config::getInstance(), $straight), 'Gerades Bild bleibt, wie es ist');
        $this->assertTrue(TesseractReader::needsDeskew(Config::getInstance(), $skewed), 'Um 2 Grad gedrehtes Bild wird gerade gerichtet');
    }

    public function test_ohne_messung_bleibt_es_beim_deskew(): void {
        $this->assertTrue(TesseractReader::needsDeskew(Config::getInstance(), '/nicht/vorhanden.png'));
    }

    /**
     * Weisses Bild mit kraeftigen waagerechten Textbalken, um $angle Grad gedreht.
     */
    private function createLinesImage(float $angle): string {
        $image = imagecreatetruecolor(1600, 1200);
        $white = (int) imagecolorallocate($image, 255, 255, 255);
        $black = (int) imagecolorallocate($image, 0, 0, 0);
        imagefilledrectangle($image, 0, 0, 1599, 1199, $white);
        for ($line = 0; $line < 14; $line++) {
            for ($word = 0; $word < 9; $word++) {
                imagefilledrectangle($image, 100 + $word * 150, 100 + $line * 70, 200 + $word * 150, 125 + $line * 70, $black);
            }
        }
        if ($angle !== 0.0) {
            $rotated = imagerotate($image, $angle, $white);
            $this->assertNotFalse($rotated);
            $image = $rotated;
        }

        $png = $this->tempFiles[] = sys_get_temp_dir() . '/pdftk-deskew-' . bin2hex(random_bytes(8)) . '.png';
        imagepng($image, $png);

        return $png;
    }
}
