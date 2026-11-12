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

    /** Ausgabe von zbarimg --xml (Format zbar 0.23), läuft auch ohne zbarimg */
    private static function zbarOutput(string $name): string {
        return (string) file_get_contents(__DIR__ . '/../../.samples/zbarimg/' . $name . '.xml');
    }

    public function test_parse_xml_does_not_invent_codes_from_xml_like_content(): void {
        // Ein QR-Code, dessen Inhalt "</data></symbol><symbol type='QR-Code'>...<data>BCD..." lautet:
        // der Regex-Parser bis 0.32.0 machte daraus einen zweiten GiroCode (Phantom GmbH, 999 EUR)
        $codes = BarcodeHelper::parseXml(self::zbarOutput('injection'));

        $this->assertCount(2, $codes);
        $this->assertSame('QR-Code', $codes[0]['type']);
        $this->assertStringStartsWith("X</data></symbol><symbol type='QR-Code'", $codes[0]['data']);
        $this->assertStringContainsString("Phantom GmbH\nDE89370400440532013000\nEUR999.00</data></symbol>", $codes[0]['data']);
        $this->assertSame(['type' => 'CODE-128', 'data' => 'TRENN 4711'], $codes[1]);
    }

    public function test_parse_xml_decodes_base64_data_of_the_right_symbol(): void {
        // zbar kodiert base64, sobald C1-Bytes vorkommen (UTF-8 von Ö, ß, €) oder "]]>":
        // bis 0.32.0 bekam der GiroCode die Daten des folgenden Code 128, beide anderen fehlten
        $codes = BarcodeHelper::parseXml(self::zbarOutput('base64'));

        $this->assertSame([
            ['type' => 'QR-Code', 'data' => "BCD\n002\n1\nSCT\nBYLADEM1001\nÖko-Bäckerei Größe GmbH\nDE02120300000000202051\nEUR12.50\n\n\nRechnung 2026-0815 über 12,50 €"],
            ['type' => 'CODE-128', 'data' => 'BELEG 12'],
            ['type' => 'QR-Code', 'data' => 'a]]>b'],
        ], $codes);
    }

    public function test_parse_xml_skips_base64_data_that_does_not_match_its_length(): void {
        $xml = "<barcodes xmlns='http://zbar.sourceforge.net/2008/barcode'>\n<source href='x.png'>\n<index num='0'>\n"
            . "<symbol type='QR-Code' quality='1'><data format='base64' length='9'><![CDATA[\nYV1dPmI=\n]]></data></symbol>\n"
            . "<symbol type='QR-Code' quality='1'><data format='base64' length='3'><![CDATA[\n*kein base64*\n]]></data></symbol>\n"
            . "<symbol type='CODE-39' quality='1'><data><![CDATA[OK]]></data></symbol>\n"
            . "</index>\n</source>\n</barcodes>\n";

        $this->assertSame([['type' => 'CODE-39', 'data' => 'OK']], BarcodeHelper::parseXml($xml));
    }

    public function test_parse_xml_survives_the_unescaped_file_name_of_zbarimg(): void {
        // zbarimg schreibt den Pfad ungeschützt in href ("&", "'", "<", Latin-1-Byte);
        // ungültiges UTF-8 in den Daten wird ersetzt statt das Ergebnis zu verlieren
        $this->assertSame([
            ['type' => 'CODE-39', 'data' => 'TRENN-4711'],
            ['type' => 'CODE-128', 'data' => 'Ma?e 12'],
        ], BarcodeHelper::parseXml(self::zbarOutput('unsafe-filename')));
    }

    public function test_parse_xml_rejects_doctype_and_garbage(): void {
        $entities = '<!DOCTYPE barcodes [<!ENTITY a "aaaaaaaaaa"><!ENTITY b "&a;&a;&a;&a;&a;&a;&a;&a;&a;&a;">]>'
            . '<barcodes><source><index num="0"><symbol type="QR-Code"><data>&b;</data></symbol></index></source></barcodes>';
        $external = '<!DOCTYPE barcodes [<!ENTITY x SYSTEM "file:///etc/passwd">]>'
            . '<barcodes><symbol type="QR-Code"><data>&x;</data></symbol></barcodes>';

        $this->assertSame([], BarcodeHelper::parseXml($entities));
        $this->assertSame([], BarcodeHelper::parseXml($external));
        $this->assertSame([], BarcodeHelper::parseXml('<barcodes><symbol type="QR-Code"><data>offen'));
        $this->assertSame([], BarcodeHelper::parseXml(''));
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
