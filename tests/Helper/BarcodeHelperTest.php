<?php
/*
 * Created on   : Sat Oct 18 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : BarcodeHelperTest.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace Tests\Helper;

use PDFToolkit\Helper\{BarcodeHelper, PDFPageHelper};
use Tests\Contracts\BaseTestCase;

/**
 * Strichcodes lesen (zbarimg): auf einem Bild und auf den Seiten einer PDF.
 * Den Code zeichnet {@see BarcodeProbe} (Code 39) mit GD.
 */
final class BarcodeHelperTest extends BaseTestCase {
    private string $workDir;

    protected function setUp(): void {
        parent::setUp();
        $this->workDir = sys_get_temp_dir() . '/barcode-test-' . bin2hex(random_bytes(6));
        mkdir($this->workDir, 0755, true);
    }

    protected function tearDown(): void {
        if (isset($this->workDir) && is_dir($this->workDir)) {
            PdfProbe::removeDir($this->workDir);
        }
    }

    private function requireZbar(): void {
        if (!BarcodeHelper::isAvailable()) {
            $this->markTestSkipped('zbarimg (zbar-tools) nicht verfügbar');
        }
    }

    public function test_parse_xml_reads_type_and_data(): void {
        $xml = '<barcodes xmlns="http://zbar.sourceforge.net/2008/barcode"><source href="x.png"><index num="0">'
            . '<symbol type="CODE-39" quality="63"><data><![CDATA[TRENN 0815]]></data></symbol>'
            . '<symbol type="QR-Code" quality="1"><data>https://example.org/?a=1&amp;b=2</data></symbol>'
            . '</index></source></barcodes>';

        $this->assertSame([
            ['type' => 'CODE-39', 'data' => 'TRENN 0815'],
            ['type' => 'QR-Code', 'data' => 'https://example.org/?a=1&b=2'],
        ], BarcodeHelper::parseXml($xml));
        $this->assertSame([], BarcodeHelper::parseXml('<barcodes></barcodes>'));
    }

    public function test_scan_image_reads_a_code39(): void {
        $this->requireZbar();
        $image = $this->workDir . '/trenn.png';
        BarcodeProbe::code39('TRENN-4711', $image);

        $codes = BarcodeHelper::scanImage($image);

        $this->assertCount(1, $codes);
        $this->assertSame('CODE-39', $codes[0]['type']);
        $this->assertSame('TRENN-4711', $codes[0]['data']);
    }

    public function test_scan_image_without_code_is_empty(): void {
        $this->requireZbar();
        $image = $this->workDir . '/leer.png';
        $gd = imagecreatetruecolor(300, 200);
        imagefilledrectangle($gd, 0, 0, 300, 200, imagecolorallocate($gd, 255, 255, 255));
        imagepng($gd, $image);

        $this->assertSame([], BarcodeHelper::scanImage($image));
    }

    public function test_scan_pages_finds_the_code_on_its_page_only(): void {
        $this->requireZbar();
        if (!PDFPageHelper::isAvailable() || !PdfProbe::toolsAvailable()) {
            $this->markTestSkipped('mutool oder poppler nicht verfügbar');
        }
        $blank = $this->workDir . '/leer.png';
        $gd = imagecreatetruecolor(600, 800);
        imagefilledrectangle($gd, 0, 0, 600, 800, imagecolorallocate($gd, 255, 255, 255));
        imagepng($gd, $blank);
        $code = $this->workDir . '/code.png';
        BarcodeProbe::code39('BELEG 12', $code, narrow: 4);

        $pdf = $this->workDir . '/stapel.pdf';
        $this->assertSame(3, PDFPageHelper::imagesToPdf([$blank, $code, $blank], $pdf, 'a4', 10.0));

        $found = BarcodeHelper::scanPages($pdf);

        $this->assertSame([2], array_keys($found));
        $this->assertSame('BELEG 12', $found[2][0]['data']);
        $this->assertSame([], BarcodeHelper::scanPages($pdf, [1, 3]));
    }
}
