<?php
/*
 * Created on   : Wed Oct 14 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : PDFOcrHelperTest.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace Tests\Helper;

use InvalidArgumentException;
use PDFToolkit\Helper\{PDFHelper, PDFOcrHelper, PDFPageHelper};
use Tests\Contracts\BaseTestCase;

/**
 * Der eine ocrmypdf-Weg des Pakets (Scan 13.10.2026 K3-03): durchsuchbares PDF
 * für Anwendungen und Text für den OcrMyPDFReader mit denselben Schaltern.
 */
class PDFOcrHelperTest extends BaseTestCase {
    /** Die ocrmypdf-Einträge mit Texterkennung - einer je Modus (skip/force, mit/ohne Sidecar) */
    private const OCR_ENTRIES = ['ocrmypdf', 'ocrmypdf-sidecar', 'ocrmypdf-force', 'ocrmypdf-force-sidecar'];

    /** Schalter, die nur den Modus bestimmen und deshalb je Eintrag abweichen */
    private const MODE_SWITCHES = ['--skip-text', '--force-ocr', '--sidecar', '[SIDECAR]'];

    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    public function test_alle_ocr_eintraege_teilen_dieselben_schalter(): void {
        $config = json_decode((string) file_get_contents(__DIR__ . '/../../config/executables.json'), true);
        $flags = [];
        foreach (self::OCR_ENTRIES as $key) {
            $arguments = $config['shellExecutables'][$key]['arguments'] ?? null;
            $this->assertIsArray($arguments, "Eintrag {$key} fehlt");
            $flags[$key] = array_values(array_diff($arguments, self::MODE_SWITCHES));
        }

        foreach ($flags as $key => $set) {
            $this->assertSame($flags['ocrmypdf'], $set, "{$key} weicht von ocrmypdf ab - zwei OCR-Konfigurationen");
        }
        $this->assertContains('--rotate-pages', $flags['ocrmypdf'], 'Quer oder kopfueber gescannte Seiten werden gedreht');
        $this->assertContains('--deskew', $flags['ocrmypdf'], 'Schiefe Seiten werden gerade gerichtet');
        $this->assertContains('--skip-text', $config['shellExecutables']['ocrmypdf']['arguments']);
        $this->assertContains('--force-ocr', $config['shellExecutables']['ocrmypdf-force']['arguments']);
    }

    public function test_unbekannte_sprache_geht_nicht_an_ocrmypdf(): void {
        $this->expectException(InvalidArgumentException::class);
        PDFOcrHelper::makeSearchable(__FILE__, sys_get_temp_dir() . '/nie.pdf', language: 'deu; rm -rf /');
    }

    public function test_fehlende_eingabe_meldet_fehler(): void {
        $messages = null;
        $ok = PDFOcrHelper::makeSearchable('/nicht/vorhanden.pdf', sys_get_temp_dir() . '/nie.pdf', messages: $messages);

        $this->assertFalse($ok);
        $this->assertNotEmpty($messages);
    }

    public function test_scan_bekommt_textebene_und_sidecar(): void {
        if (!PDFOcrHelper::isAvailable() || !function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('ocrmypdf oder GD nicht verfuegbar');
        }
        $scan = $this->createScanPdf(['KONTOAUSZUG NR 4711', 'SALDO 1234,56 EUR', 'BUCHUNG 09.10.2026']);
        $this->assertFalse(PDFHelper::hasEmbeddedText($scan), 'Die Vorlage ist ein reiner Scan ohne Textebene');

        $output = $this->tempFiles[] = sys_get_temp_dir() . '/pdftk-ocrhelper-' . bin2hex(random_bytes(8)) . '.pdf';
        $sidecar = $this->tempFiles[] = $output . '.txt';
        $messages = null;
        $ok = PDFOcrHelper::makeSearchable($scan, $output, sidecarPath: $sidecar, messages: $messages);

        $this->assertTrue($ok, 'ocrmypdf: ' . implode("\n", $messages ?? []));
        $this->assertTrue(PDFHelper::hasEmbeddedText($output), 'Ausgabe traegt eine Textebene');
        $this->assertStringContainsString('4711', (string) file_get_contents($sidecar));
        $this->assertStringContainsString('KONTOAUSZUG', (string) file_get_contents($sidecar));
    }

    public function test_text_pdf_bleibt_durchsuchbar(): void {
        if (!PDFOcrHelper::isAvailable() || !function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('ocrmypdf oder GD nicht verfuegbar');
        }
        $scan = $this->createScanPdf(['KONTOAUSZUG NR 4711', 'SALDO 1234,56 EUR', 'BUCHUNG 09.10.2026']);
        $first = $this->tempFiles[] = sys_get_temp_dir() . '/pdftk-ocrhelper-' . bin2hex(random_bytes(8)) . '.pdf';
        $this->assertTrue(PDFOcrHelper::makeSearchable($scan, $first));

        // Zweiter Lauf ueber die schon durchsuchbare Datei: --skip-text laesst sie, wie sie ist
        $second = $this->tempFiles[] = sys_get_temp_dir() . '/pdftk-ocrhelper-' . bin2hex(random_bytes(8)) . '.pdf';
        $this->assertTrue(PDFOcrHelper::makeSearchable($first, $second));
        $this->assertTrue(PDFHelper::hasEmbeddedText($second));
    }

    /**
     * Kopfueber gescannte Seite: OSD erkennt die Lage sicher, meldet aber eine
     * Konfidenz unter dem ocrmypdf-Standard 14 (hier um 12, an Katalogscans
     * 5 bis 15). Mit der gesenkten Schwelle wird die Seite gedreht und gelesen.
     */
    public function test_kopfueber_gescannte_seite_wird_gedreht_und_gelesen(): void {
        if (!PDFOcrHelper::isAvailable() || !function_exists('imagettftext')) {
            $this->markTestSkipped('ocrmypdf oder GD mit FreeType nicht verfuegbar');
        }
        $font = dirname(__DIR__, 2) . '/vendor/dompdf/dompdf/lib/fonts/DejaVuSans.ttf';
        if (!is_file($font)) {
            $this->markTestSkipped('Schrift DejaVuSans fehlt');
        }
        $scan = $this->createStatementScan($font, 180);

        $output = $this->tempFiles[] = sys_get_temp_dir() . '/pdftk-ocrhelper-' . bin2hex(random_bytes(8)) . '.pdf';
        $sidecar = $this->tempFiles[] = $output . '.txt';
        $messages = null;
        $this->assertTrue(PDFOcrHelper::makeSearchable($scan, $output, sidecarPath: $sidecar, messages: $messages), 'ocrmypdf: ' . implode("\n", $messages ?? []));

        $text = (string) file_get_contents($sidecar);
        $this->assertStringContainsString('Kontoauszug', $text, 'Die gedrehte Seite wurde aufrecht gelesen');
        $this->assertStringContainsString('Musterstrasse', $text);
    }

    /**
     * A4-Seite (300 dpi) mit Buchungszeilen in einer TrueType-Schrift, um
     * $angle Grad gedreht, als Bild-PDF.
     */
    private function createStatementScan(string $font, int $angle): string {
        $image = imagecreatetruecolor(2480, 3508);
        $white = (int) imagecolorallocate($image, 255, 255, 255);
        $black = (int) imagecolorallocate($image, 0, 0, 0);
        imagefilledrectangle($image, 0, 0, 2479, 3507, $white);
        imagettftext($image, 44, 0, 200, 300, $black, $font, 'Kontoauszug Nr. 4711 vom 31.10.2026');
        for ($line = 1; $line <= 22; $line++) {
            $text = sprintf('%02d.10.2026  Gutschrift Miete Wohnung %d Musterstrasse  %s EUR', $line, $line, number_format(100 + $line * 37.5, 2, ',', '.'));
            imagettftext($image, 30, 0, 200, 500 + $line * 110, $black, $font, $text);
        }
        $rotated = imagerotate($image, (float) $angle, $white);
        $this->assertNotFalse($rotated);

        $png = $this->tempFiles[] = sys_get_temp_dir() . '/pdftk-ocrhelper-' . bin2hex(random_bytes(8)) . '.png';
        imagepng($rotated, $png);

        $pdf = $this->tempFiles[] = sys_get_temp_dir() . '/pdftk-ocrhelper-' . bin2hex(random_bytes(8)) . '.pdf';
        if (PDFPageHelper::imagesToPdf([$png], $pdf, 'a4', 0.0, 'portrait') === null || !is_file($pdf)) {
            $this->markTestSkipped('Bild-PDF konnte nicht erzeugt werden');
        }

        return $pdf;
    }

    /**
     * Weisses A4-Blatt mit grosszuegig gerenderten Textzeilen als Bild-PDF
     * (ohne Textebene), wie ein Scanner es liefert.
     *
     * @param list<string> $lines
     */
    private function createScanPdf(array $lines): string {
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

        $png = $this->tempFiles[] = sys_get_temp_dir() . '/pdftk-ocrhelper-' . bin2hex(random_bytes(8)) . '.png';
        imagepng($image, $png);
        imagedestroy($image);

        $pdf = $this->tempFiles[] = sys_get_temp_dir() . '/pdftk-ocrhelper-' . bin2hex(random_bytes(8)) . '.pdf';
        if (PDFPageHelper::imagesToPdf([$png], $pdf, 'a4', 10.0, 'portrait') === null || !is_file($pdf)) {
            $this->markTestSkipped('Bild-PDF konnte nicht erzeugt werden');
        }

        return $pdf;
    }
}
