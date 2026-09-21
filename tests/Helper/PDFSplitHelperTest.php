<?php
/*
 * Created on   : Mon Sep 21 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : PDFSplitHelperTest.php
 * License      : MIT License
 * License Uri  : https://opensource.org/license/mit
 */

declare(strict_types=1);

namespace Tests\Helper;

use PDFToolkit\Helper\{PDFHelper, PDFSplitHelper};
use Tests\Contracts\BaseTestCase;

/**
 * Seiten extrahieren mit pdftk oder poppler. Die Testdatei entsteht mit
 * Ghostscript: vier Seiten, abwechselnd quer (200x100) und hoch (100x200).
 */
final class PDFSplitHelperTest extends BaseTestCase {
    private string $workDir;
    private string $samplePdf;

    protected function setUp(): void {
        parent::setUp();

        if (!PDFSplitHelper::isPageExtractionAvailable()) {
            $this->markTestSkipped('Weder pdftk noch poppler (pdfseparate + pdfunite) verfügbar');
        }

        exec('which gs 2>/dev/null', $out, $rc);
        if ($rc !== 0) {
            $this->markTestSkipped('Ghostscript (gs) zum Erzeugen der Testdatei nicht verfügbar');
        }

        $this->workDir = sys_get_temp_dir() . '/pdf-split-test-' . uniqid();
        mkdir($this->workDir, 0755, true);
        $this->samplePdf = $this->workDir . '/mixed.pdf';

        $pages = '';
        foreach ([[200, 100], [100, 200], [200, 100], [100, 200]] as [$w, $h]) {
            $pages .= "<</PageSize [{$w} {$h}]>> setpagedevice showpage ";
        }
        exec(sprintf(
            'gs -q -dBATCH -dNOPAUSE -sDEVICE=pdfwrite -sOutputFile=%s -c %s 2>&1',
            escapeshellarg($this->samplePdf),
            escapeshellarg(trim($pages))
        ), $gsOut, $gsRc);
        $this->assertSame(0, $gsRc, 'Ghostscript konnte die Testdatei nicht erzeugen: ' . implode("\n", $gsOut));
        $this->assertSame(4, PDFSplitHelper::getPageCount($this->samplePdf));
    }

    protected function tearDown(): void {
        if (isset($this->workDir) && is_dir($this->workDir)) {
            array_map('unlink', glob($this->workDir . '/*') ?: []);
            rmdir($this->workDir);
        }
    }

    public function test_extract_pages_keeps_a_contiguous_range(): void {
        $out = $this->workDir . '/range.pdf';

        $this->assertTrue(PDFSplitHelper::extractPages($this->samplePdf, $out, 2, 3));
        $this->assertSame(2, PDFSplitHelper::getPageCount($out));

        $sizes = PDFHelper::getAllPageSizes($out);
        $this->assertTrue($sizes[1]->isPortrait(), 'Seite 2 der Quelle ist hoch');
        $this->assertTrue($sizes[2]->isLandscape(), 'Seite 3 der Quelle ist quer');
    }

    public function test_extract_page_set_takes_arbitrary_pages_in_order(): void {
        $out = $this->workDir . '/landscape.pdf';

        $this->assertTrue(PDFSplitHelper::extractPageSet($this->samplePdf, $out, [1, 3]));
        $this->assertSame(2, PDFSplitHelper::getPageCount($out));
        foreach (PDFHelper::getAllPageSizes($out) as $size) {
            $this->assertTrue($size->isLandscape());
        }
    }

    public function test_single_page_set_yields_one_page(): void {
        $out = $this->workDir . '/single.pdf';

        $this->assertTrue(PDFSplitHelper::extractPageSet($this->samplePdf, $out, [4]));
        $this->assertSame(1, PDFSplitHelper::getPageCount($out));
        $this->assertTrue(PDFHelper::getAllPageSizes($out)[1]->isPortrait());
    }

    public function test_rotated_pages_count_by_their_displayed_orientation(): void {
        // Hochformat-Maße mit /Rotate 90 (per pdfmark gesetzt): angezeigt wird quer
        $rotated = $this->workDir . '/rotated.pdf';
        exec(sprintf(
            'gs -q -dBATCH -dNOPAUSE -dAutoRotatePages=/None -sDEVICE=pdfwrite -sOutputFile=%s -c %s 2>&1',
            escapeshellarg($rotated),
            escapeshellarg('<</PageSize [100 200]>> setpagedevice [ {ThisPage} << /Rotate 90 >> /PUT pdfmark showpage')
        ), $out, $rc);
        $this->assertSame(0, $rc);

        $size = PDFHelper::getAllPageSizes($rotated)[1] ?? null;
        $this->assertNotNull($size);
        $this->assertSame(90, $size->rotation);
        $this->assertTrue($size->isLandscape());
        $this->assertFalse($size->isPortrait());
        $this->assertEqualsWithDelta(200.0, $size->displayWidthPt(), 0.5);
    }

    public function test_empty_page_set_is_rejected(): void {
        $this->assertFalse(PDFSplitHelper::extractPageSet($this->samplePdf, $this->workDir . '/none.pdf', [0, -1]));
        $this->assertFileDoesNotExist($this->workDir . '/none.pdf');
    }
}
