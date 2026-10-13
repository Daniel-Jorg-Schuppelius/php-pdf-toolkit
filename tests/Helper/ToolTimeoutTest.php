<?php
/*
 * Created on   : Tue Oct 13 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : ToolTimeoutTest.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace Tests\Helper;

use CommonToolkit\Helper\Shell;
use PDFToolkit\Helper\ToolTimeout;
use Tests\Contracts\BaseTestCase;

class ToolTimeoutTest extends BaseTestCase {
    private string $dir = '';

    protected function setUp(): void {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/pdftk-timeout-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0700);
    }

    protected function tearDown(): void {
        Shell::setDefaultTimeout(null);
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    public function test_klassen_sind_der_groesse_nach_geordnet(): void {
        $this->assertSame(Shell::TIMEOUT_PROBE, ToolTimeout::PROBE);
        $this->assertLessThan(ToolTimeout::PAGE, ToolTimeout::PROBE);
        $this->assertLessThan(ToolTimeout::DOCUMENT, ToolTimeout::PAGE);
        $this->assertLessThan(ToolTimeout::MAX, ToolTimeout::DOCUMENT);
    }

    public function test_dokumentdeckel_waechst_mit_der_dateigroesse(): void {
        $small = $this->file('klein.pdf', 0);
        $big = $this->file('gross.pdf', 5);

        $this->assertSame(ToolTimeout::DOCUMENT, ToolTimeout::forSize($small));
        $this->assertSame(ToolTimeout::DOCUMENT + 5 * ToolTimeout::PER_MEGABYTE, ToolTimeout::forSize($big));
        // Mehrere Eingaben (Zusammenfuegen) zaehlen zusammen; fehlende Dateien zaehlen nicht
        $this->assertSame(ToolTimeout::DOCUMENT + 10 * ToolTimeout::PER_MEGABYTE, ToolTimeout::forSize($big, $big, $this->dir . '/fehlt.pdf'));
    }

    public function test_render_und_ocr_wachsen_mit_der_seitenzahl(): void {
        $pdf = $this->file('scan.pdf', 0);

        $this->assertSame(ToolTimeout::DOCUMENT + 10 * ToolTimeout::RENDER_PER_PAGE, ToolTimeout::forRendering($pdf, 10));
        $this->assertSame(ToolTimeout::DOCUMENT + 10 * ToolTimeout::OCR_PER_PAGE, ToolTimeout::forOcr($pdf, 10));
    }

    public function test_ohne_seitenzahl_wird_aus_der_groesse_geschaetzt(): void {
        // Kein lesbares PDF: pdfinfo liefert nichts, 2 MB gelten als 20 Seiten
        $broken = $this->file('kaputt.pdf', 2);

        $this->assertSame(ToolTimeout::DOCUMENT + 20 * ToolTimeout::OCR_PER_PAGE, ToolTimeout::forOcr($broken));
    }

    public function test_deckel_hat_eine_obergrenze(): void {
        $pdf = $this->file('riesig.pdf', 0);

        $this->assertSame(ToolTimeout::MAX, ToolTimeout::forOcr($pdf, 100000));
    }

    public function test_prozessweiter_standard_hebt_skalierte_deckel_an(): void {
        $pdf = $this->file('lang.pdf', 0);
        Shell::setDefaultTimeout(20000.0);

        $this->assertSame(20000.0, ToolTimeout::forSize($pdf));
        $this->assertSame(20000.0, ToolTimeout::forOcr($pdf, 100000));
        // Proben und Einzelseiten bleiben fest
        $this->assertSame(Shell::TIMEOUT_PROBE, ToolTimeout::PROBE);
    }

    /**
     * Legt eine (duenn belegte) Datei mit $megabytes MB an.
     */
    private function file(string $name, int $megabytes): string {
        $path = $this->dir . '/' . $name;
        $handle = fopen($path, 'w');
        $this->assertIsResource($handle);
        ftruncate($handle, $megabytes * 1048576);
        fclose($handle);

        return $path;
    }
}
