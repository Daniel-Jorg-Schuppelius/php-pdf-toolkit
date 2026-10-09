<?php
/*
 * Created on   : Fri Oct 09 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : PDFOptimizeHelperTest.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace Tests\Helper;

use PDFToolkit\Helper\PDFOptimizeHelper;
use Tests\Contracts\BaseTestCase;

/**
 * Verkleinern (stark, verlustfrei) und PDF/A.
 */
final class PDFOptimizeHelperTest extends BaseTestCase {
    private string $workDir;

    protected function setUp(): void {
        parent::setUp();

        foreach (['gs', 'pdfinfo'] as $tool) {
            if (trim((string) shell_exec('command -v ' . $tool)) === '') {
                $this->markTestSkipped("$tool nicht verfügbar");
            }
        }

        $this->workDir = sys_get_temp_dir() . '/pdf-optimize-test-' . bin2hex(random_bytes(6));
        mkdir($this->workDir, 0755, true);
    }

    protected function tearDown(): void {
        if (isset($this->workDir) && is_dir($this->workDir)) {
            PdfProbe::removeDir($this->workDir);
        }
    }

    public function test_strong_compression_shrinks_an_image_heavy_pdf(): void {
        if (!PDFOptimizeHelper::isAvailable(PDFOptimizeHelper::LEVEL_STRONG)) {
            $this->markTestSkipped('gs-compress nicht verfügbar');
        }
        $source = PdfProbe::imageHeavy($this, $this->workDir);
        if ($source === null) {
            $this->markTestSkipped('ImageMagick (convert) nicht verfügbar');
        }
        $output = $this->workDir . '/small.pdf';

        $size = PDFOptimizeHelper::compress($source, $output, PDFOptimizeHelper::LEVEL_STRONG);

        $this->assertNotNull($size);
        $this->assertLessThan(filesize($source) / 2, $size);
        $this->assertSame($size, filesize($output));
        $this->assertSame(PdfProbe::pageCount($source), PdfProbe::pageCount($output));
    }

    public function test_compression_keeps_the_original_when_nothing_is_saved(): void {
        if (!PDFOptimizeHelper::isAvailable(PDFOptimizeHelper::LEVEL_STRONG)) {
            $this->markTestSkipped('gs-compress nicht verfügbar');
        }
        // Eine winzige Text-PDF wird durch Ghostscript eher größer
        $source = PdfProbe::labelled($this, $this->workDir, 1);
        $output = $this->workDir . '/same.pdf';

        $this->assertNull(PDFOptimizeHelper::compress($source, $output, PDFOptimizeHelper::LEVEL_STRONG));
        $this->assertFileDoesNotExist($output);
    }

    public function test_lossless_optimization_runs(): void {
        if (!PDFOptimizeHelper::isAvailable(PDFOptimizeHelper::LEVEL_LOSSLESS)) {
            $this->markTestSkipped('ocrmypdf nicht verfügbar');
        }
        $source = PdfProbe::imageHeavy($this, $this->workDir);
        if ($source === null) {
            $this->markTestSkipped('ImageMagick (convert) nicht verfügbar');
        }
        $output = $this->workDir . '/lossless.pdf';

        $size = PDFOptimizeHelper::compress($source, $output, PDFOptimizeHelper::LEVEL_LOSSLESS);

        // Kleiner oder bewusst verworfen - nie ein größeres Ergebnis
        if ($size !== null) {
            $this->assertLessThan(filesize($source), $size);
            $this->assertSame(PdfProbe::pageCount($source), PdfProbe::pageCount($output));
        } else {
            $this->assertFileDoesNotExist($output);
        }
    }

    public function test_custom_compression_takes_resolution_and_quality(): void {
        if (!PDFOptimizeHelper::isAvailable(PDFOptimizeHelper::LEVEL_CUSTOM)) {
            $this->markTestSkipped('gs-compress-custom nicht verfügbar');
        }
        $source = PdfProbe::imageHeavy($this, $this->workDir);
        if ($source === null) {
            $this->markTestSkipped('ImageMagick (convert) nicht verfügbar');
        }

        $small = $this->workDir . '/small.pdf';
        $size = PDFOptimizeHelper::compress($source, $small, PDFOptimizeHelper::LEVEL_CUSTOM, 72, 30);
        $this->assertNotNull($size);
        $this->assertLessThan(filesize($source) / 4, $size);
        $this->assertSame(1, PdfProbe::pageCount($small));

        // Hoehere Qualitaet bei gleicher Aufloesung ergibt die groessere Datei
        $fine = $this->workDir . '/fine.pdf';
        $fineSize = PDFOptimizeHelper::compress($source, $fine, PDFOptimizeHelper::LEVEL_CUSTOM, 72, 95);
        $this->assertNotNull($fineSize);
        $this->assertGreaterThan($size, $fineSize);

        // Ohne Zahlen keine eigene Stufe
        $this->assertNull(PDFOptimizeHelper::compress($source, $this->workDir . '/x.pdf', PDFOptimizeHelper::LEVEL_CUSTOM));
        $this->assertNull(PDFOptimizeHelper::compress($source, $this->workDir . '/x.pdf', PDFOptimizeHelper::LEVEL_CUSTOM, 10, 50));
        $this->assertFileDoesNotExist($this->workDir . '/x.pdf');
    }

    public function test_clean_rewrites_without_object_streams(): void {
        if (!PDFOptimizeHelper::isCleanAvailable()) {
            $this->markTestSkipped('mutool clean nicht verfügbar');
        }
        $source = PdfProbe::labelled($this, $this->workDir, 2);

        $repaired = $this->workDir . '/repaired.pdf';
        $this->assertTrue(PDFOptimizeHelper::clean($source, $repaired));
        $this->assertSame(2, PdfProbe::pageCount($repaired));
        $this->assertStringStartsWith('P1-', PdfProbe::pageText($repaired, 1));
        $this->assertStringStartsWith('P2-', PdfProbe::pageText($repaired, 2));
        // Klassische Querverweistabelle statt Objektstroeme (lesbar fuer FPDI)
        $this->assertStringNotContainsString('/ObjStm', (string) file_get_contents($repaired));

        $this->assertFalse(PDFOptimizeHelper::clean($this->workDir . '/fehlt.pdf', $this->workDir . '/x.pdf'));
    }

    public function test_rejects_unknown_level_and_invalid_input(): void {
        $this->assertNull(PDFOptimizeHelper::compress('/nonexistent.pdf', $this->workDir . '/x.pdf'));
        $source = PdfProbe::labelled($this, $this->workDir, 1);
        $this->assertNull(PDFOptimizeHelper::compress($source, $this->workDir . '/x.pdf', 'extreme'));
    }

    public function test_pdfa_conversion_keeps_text(): void {
        if (!PDFOptimizeHelper::isPdfAAvailable() || trim((string) shell_exec('command -v pdftotext')) === '') {
            $this->markTestSkipped('ocrmypdf oder pdftotext nicht verfügbar');
        }
        $source = PdfProbe::labelled($this, $this->workDir, 2);
        $output = $this->workDir . '/archive.pdf';

        $this->assertTrue(PDFOptimizeHelper::toPdfA($source, $output));
        $this->assertSame(2, PdfProbe::pageCount($output));
        $this->assertStringStartsWith('P2-', PdfProbe::pageText($output, 2));
        // PDF/A trägt XMP-Metadaten mit der Konformitätsstufe
        $this->assertStringContainsString('pdfaid:conformance', (string) file_get_contents($output));

        $this->assertFalse(PDFOptimizeHelper::toPdfA('/nonexistent.pdf', $this->workDir . '/x.pdf'));
    }
}
