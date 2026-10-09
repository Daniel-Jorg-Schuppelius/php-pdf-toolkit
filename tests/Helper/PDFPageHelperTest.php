<?php
/*
 * Created on   : Fri Oct 09 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : PDFPageHelperTest.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace Tests\Helper;

use PDFToolkit\Helper\PDFPageHelper;
use Tests\Contracts\BaseTestCase;

/**
 * Seiten anordnen, drehen, auf Blätter legen, als Bilder ausgeben.
 *
 * Probe-PDF wie im Grid-Test: drei A4-Seiten, je 5 Zeilen x 2 Spalten
 * beschriftet ("P2-R3C1" = Seite 2, Zeile 3, Spalte 1).
 */
final class PDFPageHelperTest extends BaseTestCase {
    private string $workDir;
    private string $source;

    protected function setUp(): void {
        parent::setUp();

        if (!PDFPageHelper::isAvailable()) {
            $this->markTestSkipped('mutool (mutool-page-arrange) nicht verfügbar');
        }
        foreach (['gs', 'pdftotext', 'pdfinfo', 'pdftoppm'] as $tool) {
            if (trim((string) shell_exec('command -v ' . $tool)) === '') {
                $this->markTestSkipped("$tool nicht verfügbar");
            }
        }

        $this->workDir = sys_get_temp_dir() . '/pdf-page-test-' . bin2hex(random_bytes(6));
        mkdir($this->workDir, 0755, true);
        $this->source = PdfProbe::labelled($this, $this->workDir, 3);
    }

    protected function tearDown(): void {
        if (isset($this->workDir) && is_dir($this->workDir)) {
            PdfProbe::removeDir($this->workDir);
        }
    }

    public function test_scripts_exist(): void {
        $this->assertFileExists(PDFPageHelper::libraryPath());
        foreach (['grid-split.js', 'page-arrange.js', 'page-nup.js', 'pdf-protect.js', 'image-page.js'] as $script) {
            $this->assertFileExists(PDFPageHelper::scriptPath($script));
        }
    }

    public function test_arrange_reorders_rotates_drops_and_duplicates(): void {
        $output = $this->workDir . '/arranged.pdf';

        $pages = PDFPageHelper::arrange($this->source, $output, [
            ['page' => 3, 'rotate' => 90],
            ['page' => 1],
            ['page' => 1],
            ['page' => 2, 'rotate' => 180],
        ]);

        $this->assertSame(4, $pages);
        $this->assertStringStartsWith('P3-', PdfProbe::pageText($output, 1));
        $this->assertSame(90, PdfProbe::pageRotation($output, 1));
        $this->assertStringStartsWith('P1-', PdfProbe::pageText($output, 2));
        $this->assertStringStartsWith('P1-', PdfProbe::pageText($output, 3));
        $this->assertSame(0, PdfProbe::pageRotation($output, 2));
        $this->assertStringStartsWith('P2-', PdfProbe::pageText($output, 4));
        $this->assertSame(180, PdfProbe::pageRotation($output, 4));
    }

    public function test_arrange_rejects_bad_sequences(): void {
        $output = $this->workDir . '/bad.pdf';

        $this->assertNull(PDFPageHelper::arrange($this->source, $output, []));
        $this->assertNull(PDFPageHelper::arrange($this->source, $output, [['page' => 9]]));
        $this->assertNull(PDFPageHelper::arrange($this->source, $output, [['page' => 1, 'rotate' => 45]]));
        $this->assertFileDoesNotExist($output);
    }

    public function test_rotate_all_pages_and_a_subset(): void {
        $all = $this->workDir . '/all.pdf';
        $this->assertSame(3, PDFPageHelper::rotate($this->source, $all, 90));
        foreach ([1, 2, 3] as $page) {
            $this->assertSame(90, PdfProbe::pageRotation($all, $page));
        }

        $some = $this->workDir . '/some.pdf';
        $this->assertSame(3, PDFPageHelper::rotate($this->source, $some, 270, [2]));
        $this->assertSame(0, PdfProbe::pageRotation($some, 1));
        $this->assertSame(270, PdfProbe::pageRotation($some, 2));
        $this->assertSame(0, PdfProbe::pageRotation($some, 3));

        // Drehung kommt zur bestehenden hinzu
        $twice = $this->workDir . '/twice.pdf';
        $this->assertSame(3, PDFPageHelper::rotate($all, $twice, 90));
        $this->assertSame(180, PdfProbe::pageRotation($twice, 1));

        $this->assertNull(PDFPageHelper::rotate($this->source, $this->workDir . '/x.pdf', 45));
    }

    public function test_nup_puts_pages_on_sheets(): void {
        $two = $this->workDir . '/two.pdf';
        $this->assertSame(2, PDFPageHelper::nup($this->source, $two, 2));
        $this->assertSame(2, PdfProbe::pageCount($two));
        // Zwei Hochformatseiten nebeneinander brauchen ein Querblatt
        [$width, $height] = PdfProbe::pageSize($two, 1);
        $this->assertGreaterThan($height, $width);
        $this->assertStringContainsString('P1-R1C1', PdfProbe::pageText($two, 1));
        $this->assertStringContainsString('P2-R1C1', PdfProbe::pageText($two, 1));
        $this->assertStringContainsString('P3-R1C1', PdfProbe::pageText($two, 2));

        $four = $this->workDir . '/four.pdf';
        $this->assertSame(1, PDFPageHelper::nup($this->source, $four, 4, 'a4', 5.0));
        [$width, $height] = PdfProbe::pageSize($four, 1);
        $this->assertGreaterThan($width, $height);

        $this->assertNull(PDFPageHelper::nup($this->source, $this->workDir . '/x.pdf', 3));
        $this->assertNull(PDFPageHelper::nup($this->source, $this->workDir . '/x.pdf', 2, 'b5'));
    }

    public function test_thumbnails_for_all_pages(): void {
        $files = PDFPageHelper::thumbnails($this->source, $this->workDir . '/thumbs');

        $this->assertCount(3, $files);
        $this->assertStringEndsWith('page-1.png', $files[0]);
        $this->assertStringEndsWith('page-3.png', $files[2]);
        $size = getimagesize($files[0]);
        $this->assertNotFalse($size);
        $this->assertLessThan(300, $size[0]);

        $this->assertCount(2, PDFPageHelper::thumbnails($this->source, $this->workDir . '/few', 24, 2));
    }

    public function test_render_pages_as_png_and_jpeg(): void {
        $png = PDFPageHelper::renderPages($this->source, $this->workDir . '/png', 'png', 50);
        $this->assertCount(3, $png);
        $this->assertSame(IMAGETYPE_PNG, getimagesize($png[0])[2] ?? null);

        $jpeg = PDFPageHelper::renderPages($this->source, $this->workDir . '/jpg', 'jpeg', 50, 2, 3, 70);
        $this->assertCount(2, $jpeg);
        $this->assertStringEndsWith('.jpg', $jpeg[0]);
        $this->assertSame(IMAGETYPE_JPEG, getimagesize($jpeg[0])[2] ?? null);

        $this->assertSame([], PDFPageHelper::renderPages($this->source, $this->workDir . '/x', 'gif'));
        $this->assertSame([], PDFPageHelper::renderPages($this->source, $this->workDir . '/x', 'png', 50, 5, 9));
    }

    public function test_images_to_pdf_keeps_the_bytes(): void {
        if (!function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD fehlt');
        }
        $wide = $this->workDir . '/wide.jpg';
        $tall = $this->workDir . '/tall.png';
        imagejpeg(imagecreatetruecolor(1200, 800), $wide, 90);
        imagepng(imagecreatetruecolor(300, 500), $tall);
        $output = $this->workDir . '/images.pdf';

        $this->assertSame(2, PDFPageHelper::imagesToPdf([$wide, $tall], $output, 'a4', 10.0));

        $this->assertSame(2, PdfProbe::pageCount($output));
        // Querbild auf Querblatt, Hochbild auf Hochblatt (auto)
        [$w1, $h1] = PdfProbe::pageSize($output, 1);
        [$w2, $h2] = PdfProbe::pageSize($output, 2);
        $this->assertGreaterThan($h1, $w1);
        $this->assertGreaterThan($w2, $h2);
        // Das JPEG liegt unverändert in der PDF
        $this->assertStringContainsString('/DCTDecode', (string) file_get_contents($output));

        $this->assertNull(PDFPageHelper::imagesToPdf([], $output));
        $this->assertNull(PDFPageHelper::imagesToPdf(['/nonexistent.jpg'], $output));
        $this->assertNull(PDFPageHelper::imagesToPdf([$wide], $this->workDir . '/x.pdf', 'b5'));
    }

    public function test_sequence_to_string(): void {
        $this->assertSame('3:90,1,1,2:180', PDFPageHelper::sequenceToString([
            ['page' => 3, 'rotate' => 90], ['page' => 1], ['page' => 1, 'rotate' => 360], ['page' => 2, 'rotate' => -180],
        ]));
        $this->assertNull(PDFPageHelper::sequenceToString([]));
        $this->assertNull(PDFPageHelper::sequenceToString([['page' => 0]]));
        $this->assertNull(PDFPageHelper::sequenceToString([['page' => 1, 'rotate' => 10]]));
    }
}
