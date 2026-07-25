<?php
/*
 * Created on   : Sat Jul 25 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : FontDataHelperTest.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace Tests\Helper;

use PDFToolkit\Helper\FontDataHelper;
use TCPDF;
use Tests\Contracts\BaseTestCase;

/**
 * Absicherung der TCPDF-7-Font-Auslieferung.
 *
 * Ab TCPDF 7 liefert das Composer-Paket keine Font-Metriken mehr mit; fehlen
 * sie, scheitert bereits der Konstruktor. Diese Tests halten fest, dass die
 * mitgelieferten Gruppen vorhanden und tatsächlich benutzbar sind.
 */
final class FontDataHelperTest extends BaseTestCase {
    private static function isTcpdf7(): bool {
        return class_exists(\Com\Tecnick\Pdf\Tcpdf::class);
    }

    public function test_core_fonts_are_shipped(): void {
        $this->assertDirectoryExists(FontDataHelper::getLocalFontPath() . '/core');
        $this->assertFileExists(FontDataHelper::getLocalFontPath() . '/core/helvetica.json');
    }

    public function test_pdfa_fonts_are_shipped(): void {
        // PDF/A ist Voraussetzung für ZUGFeRD — ohne diese Gruppe kein Beleg-PDF.
        $this->assertDirectoryExists(FontDataHelper::getLocalFontPath() . '/pdfa');
        $this->assertNotEmpty(glob(FontDataHelper::getLocalFontPath() . '/pdfa/*.json'));
    }

    public function test_shipped_font_definition_is_valid_json(): void {
        $raw = file_get_contents(FontDataHelper::getLocalFontPath() . '/core/helvetica.json');
        $this->assertIsString($raw);

        $data = json_decode($raw, true);
        $this->assertIsArray($data, 'Font-Definition muss dekodierbar sein');
        $this->assertArrayHasKey('type', $data, 'tc-lib-pdf-font erwartet den Schlüssel "type"');
    }

    public function test_availability_checks(): void {
        $this->assertTrue(FontDataHelper::isAvailable());
        $this->assertTrue(FontDataHelper::isPdfaAvailable());
    }

    public function test_bootstrap_points_font_path_at_shipped_fonts(): void {
        if (!self::isTcpdf7()) {
            $this->markTestSkipped('K_PATH_FONTS wird nur für TCPDF 7 gesetzt.');
        }

        $this->assertTrue(defined('K_PATH_FONTS'), 'bootstrap_fonts.php muss K_PATH_FONTS setzen');
        $this->assertDirectoryExists(FontDataHelper::getActiveFontPath() . '/core');
    }

    /**
     * Der eigentliche Regressionstest: Ohne Fonts wirft schon der Konstruktor
     * eine Com\Tecnick\Pdf\Font\Exception ("unable to read file:
     * helvetica.json"). Erzeugt hier ein vollständiges PDF.
     */
    public function test_tcpdf_produces_pdf_with_shipped_fonts(): void {
        if (!class_exists(TCPDF::class)) {
            $this->markTestSkipped('TCPDF nicht installiert.');
        }

        $pdf = new TCPDF;
        $pdf->AddPage();
        $pdf->SetFont('helvetica', '', 12);
        $pdf->Write(0, 'Schriftprüfung');

        $out = $pdf->Output('probe.pdf', 'S');

        $this->assertStringStartsWith('%PDF', $out);
        $this->assertGreaterThan(1000, strlen($out));
    }
}
