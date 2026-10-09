<?php
/*
 * Created on   : Fri Oct 09 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : OcrMyPDFReaderTest.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace Tests\Readers;

use PDFToolkit\Helper\{PDFHelper, PDFPageHelper, TesseractDataHelper};
use PDFToolkit\Readers\OcrMyPDFReader;
use Tests\Contracts\BaseTestCase;

/**
 * ocrmypdf laeuft mit TESSDATA_PREFIX auf dem Toolkit-Datenverzeichnis. Das
 * Verzeichnis muss neben den Sprachdaten die Parameterdateien tragen, die
 * ocrmypdf per Namen an Tesseract gibt ("pdf txt" bzw. "hocr txt") - sonst
 * meldet Tesseract "read_params_file: Can't open pdf" und schreibt kein PDF:
 * ocrmypdf 16 bricht ab, ocrmypdf 13/14 liefern still ein PDF ohne Textebene.
 */
final class OcrMyPDFReaderTest extends BaseTestCase {
    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
    }

    public function test_scan_gets_a_text_layer_with_toolkit_tessdata(): void {
        if (!function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD nicht verfuegbar');
        }
        $reader = new OcrMyPDFReader;
        if (!$reader->isAvailable()) {
            $this->markTestSkipped('ocrmypdf nicht verfuegbar');
        }
        $dataPath = TesseractDataHelper::getUsableDataPath('deu+eng');
        if ($dataPath === null) {
            $this->markTestSkipped('Sprachdaten deu+eng nicht verfuegbar');
        }
        $this->assertTrue(TesseractDataHelper::hasConfigs($dataPath), 'TESSDATA_PREFIX-Verzeichnis traegt configs/');

        // Mehrere Zeilen: Der Reader verwirft Ergebnisse unter 10 Zeichen,
        // hasEmbeddedText() verlangt 20 - ein einzelnes Wort reicht nicht.
        $scan = $this->createScanPdf(['KONTOAUSZUG NR 4711', 'SALDO 1234,56 EUR', 'BUCHUNG 09.10.2026']);
        if ($scan === null) {
            $this->markTestSkipped('Bild-PDF konnte nicht erzeugt werden');
        }
        $this->assertFalse(PDFHelper::hasEmbeddedText($scan), 'Die Vorlage ist ein reiner Scan ohne Textebene');

        $text = $reader->extractText($scan, ['qualityCheck' => false, 'language' => 'deu+eng']);

        $this->assertNotNull($text, 'ocrmypdf liefert Text');
        // Ziffern und Grossbuchstaben ohne O/D-Verwechslung: die Bitmap-Schrift
        // von GD liest Tesseract sonst gern als "SALDD"
        $this->assertStringContainsString('4711', $text);
        $this->assertStringContainsString('KONTOAUSZUG', $text);
    }

    /**
     * Weisses A4-Blatt mit grosszuegig gerenderten Textzeilen als Bild-PDF
     * (ohne Textebene), wie ein Scanner es liefert.
     *
     * @param list<string> $lines
     */
    private function createScanPdf(array $lines): ?string {
        $image = imagecreatetruecolor(1200, 60 + 170 * count($lines));
        $white = (int) imagecolorallocate($image, 255, 255, 255);
        $black = (int) imagecolorallocate($image, 0, 0, 0);
        imagefilledrectangle($image, 0, 0, 1199, 59 + 170 * count($lines), $white);

        // Built-in-Font 5 ist klein; hochskaliert trifft die OCR sicher.
        foreach ($lines as $index => $line) {
            $small = imagecreatetruecolor(300, 40);
            imagefilledrectangle($small, 0, 0, 299, 39, $white);
            imagestring($small, 5, 10, 10, $line, $black);
            imagecopyresized($image, $small, 60, 60 + 170 * $index, 0, 0, 1080, 120, 300, 40);
            imagedestroy($small);
        }

        $png = sys_get_temp_dir() . '/ocrmypdf_reader_test_' . uniqid() . '.png';
        imagepng($image, $png);
        imagedestroy($image);
        $this->tempFiles[] = $png;

        $pdf = sys_get_temp_dir() . '/ocrmypdf_reader_test_' . uniqid() . '.pdf';
        $this->tempFiles[] = $pdf;
        if (PDFPageHelper::imagesToPdf([$png], $pdf, 'a4', 10.0, 'portrait') === null || !is_file($pdf)) {
            return null;
        }

        return $pdf;
    }
}
