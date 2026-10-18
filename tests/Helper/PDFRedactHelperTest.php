<?php
/*
 * Created on   : Sat Oct 18 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : PDFRedactHelperTest.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace Tests\Helper;

use PDFToolkit\Helper\{PDFPageHelper, PDFRedactHelper};
use Tests\Contracts\BaseTestCase;

/**
 * Schwärzen: Fundstellen aus pdftotext -bbox-layout, Entfernen über
 * pdf-redact.js. Geprüft wird, dass der Text wirklich aus der Datei
 * verschwindet, auch auf gedrehten Seiten und in Bildern.
 */
final class PDFRedactHelperTest extends BaseTestCase {
    private string $workDir;
    private string $source;

    protected function setUp(): void {
        parent::setUp();
        PDFRedactHelper::resetVersionCache();
        PDFRedactHelper::resetEngineCache();
        if (!PDFRedactHelper::toolsAvailable() || !PDFPageHelper::isAvailable() || !PdfProbe::toolsAvailable()) {
            $this->markTestSkipped('mutool (mutool-pdf-redact), pdftotext oder poppler nicht verfügbar');
        }
        if (!PDFRedactHelper::engineWorks()) {
            // MuPDF 1.23 (Ubuntu 24.04) laesst das zweite Wort eines TJ-Arrays stehen; die Sonde meldet das
            $this->markTestSkipped('MuPDF ' . (PDFRedactHelper::mutoolVersion() ?? '?') . ' besteht die Schwärzungs-Sonde nicht - Schwärzen ist damit nicht verfügbar');
        }
        $this->workDir = sys_get_temp_dir() . '/pdf-redact-test-' . bin2hex(random_bytes(6));
        mkdir($this->workDir, 0755, true);
        $this->source = PdfProbe::labelled($this, $this->workDir, 2);
    }

    protected function tearDown(): void {
        if (isset($this->workDir) && is_dir($this->workDir)) {
            PdfProbe::removeDir($this->workDir);
        }
    }

    public function test_find_text_returns_word_boxes_in_display_space(): void {
        $found = PDFRedactHelper::findText($this->source, ['p1-r1c1', 'P2-R5C2']);

        $this->assertSame([1, 2], array_keys($found));
        $this->assertCount(1, $found[1]);
        $box = $found[1][0];
        // Erste Zeile oben links: x ab 40 pt, y rund 84-103 pt vom oberen Rand
        $this->assertEqualsWithDelta(39.0, $box['x0'], 1.5);
        $this->assertEqualsWithDelta(83.0, $box['y0'], 2.5);
        $this->assertGreaterThan(110.0, $box['x1']);
        $this->assertLessThan(130.0, $box['x1']);
        $this->assertSame([], PDFRedactHelper::findText($this->source, ['gibt es nicht']));
        $this->assertSame([], PDFRedactHelper::findText($this->source, ['', '  ']));
    }

    public function test_find_text_matches_across_words_and_whole_lines(): void {
        $found = PDFRedactHelper::findText($this->source, ['r1c1 p1-r1c2'], false, [1]);
        $this->assertCount(1, $found[1]);
        $this->assertGreaterThan(400.0, $found[1][0]['x1']);

        $lines = PDFRedactHelper::findText($this->source, ['P1-R2C1'], true);
        $this->assertSame([1], array_keys($lines));
        $this->assertSame(0.0, $lines[1][0]['x0']);
        $this->assertEqualsWithDelta(595.0, $lines[1][0]['x1'], 0.5);
    }

    public function test_redact_removes_the_words_from_the_file(): void {
        $output = $this->workDir . '/schwarz.pdf';
        $rects = PDFRedactHelper::findText($this->source, ['P1-R1C1', 'P2-R3C2']);

        $this->assertSame(2, PDFRedactHelper::redact($this->source, $output, $rects));

        $page1 = PdfProbe::pageText($output, 1);
        $this->assertStringNotContainsString('P1-R1C1', $page1);
        $this->assertStringContainsString('P1-R1C2', $page1);
        $this->assertStringContainsString('P1-R2C1', $page1);
        $page2 = PdfProbe::pageText($output, 2);
        $this->assertStringNotContainsString('P2-R3C2', $page2);
        $this->assertStringContainsString('P2-R3C1', $page2);
        $this->assertSame(2, PdfProbe::pageCount($output));
    }

    public function test_whole_line_redaction_clears_both_columns(): void {
        $output = $this->workDir . '/zeile.pdf';
        $rects = PDFRedactHelper::findText($this->source, ['P1-R2C2'], true);

        $this->assertSame(1, PDFRedactHelper::redact($this->source, $output, $rects));

        $page1 = PdfProbe::pageText($output, 1);
        $this->assertStringNotContainsString('P1-R2C1', $page1);
        $this->assertStringNotContainsString('P1-R2C2', $page1);
        $this->assertStringContainsString('P1-R1C1', $page1);
        $this->assertStringContainsString('P1-R3C1', $page1);
    }

    public function test_rotated_page_uses_the_same_display_space(): void {
        $rotated = $this->workDir . '/gedreht.pdf';
        $this->assertSame(2, PDFPageHelper::rotate($this->source, $rotated, 90));
        $this->assertSame(90, PdfProbe::pageRotation($rotated, 1));

        $rects = PDFRedactHelper::findText($rotated, ['P1-R1C1']);
        $this->assertCount(1, $rects[1]);
        $output = $this->workDir . '/gedreht-schwarz.pdf';
        $this->assertSame(1, PDFRedactHelper::redact($rotated, $output, $rects));

        $page1 = PdfProbe::pageText($output, 1);
        $this->assertStringNotContainsString('P1-R1C1', $page1);
        $this->assertStringContainsString('P1-R1C2', $page1);
    }

    public function test_image_pixels_under_the_box_are_removed_or_the_file_is_refused(): void {
        $image = PdfProbe::imageHeavy($this, $this->workDir);
        if ($image === null || trim((string) shell_exec('command -v pdftoppm')) === '') {
            $this->markTestSkipped('ImageMagick oder pdftoppm nicht verfügbar');
        }
        [$width, $height] = PdfProbe::pageSize($image, 1);
        $rects = [1 => [['x0' => 0.0, 'y0' => 0.0, 'x1' => $width / 2, 'y1' => $height / 2]]];
        $output = $this->workDir . '/bild-schwarz.pdf';

        $this->assertTrue(PDFRedactHelper::hasImages($image, [1]));
        $this->assertFalse(PDFRedactHelper::hasImages($this->source, [1]));

        if (!PDFRedactHelper::supportsImages()) {
            $this->assertNull(PDFRedactHelper::redact($image, $output, $rects), 'MuPDF ' . PDFRedactHelper::mutoolVersion() . ' darf Bilder nicht schwärzen');
            $this->assertFileDoesNotExist($output);

            return;
        }

        $this->assertSame(1, PDFRedactHelper::redact($image, $output, $rects));
        $png = $this->workDir . '/render';
        exec(sprintf('pdftoppm -r 36 -png -singlefile %s %s 2>&1', escapeshellarg($output), escapeshellarg($png)), $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
        $gd = imagecreatefrompng($png . '.png');
        $this->assertNotFalse($gd);
        $black = PdfProbe::meanBrightness($gd, 0, 0, (int) (imagesx($gd) / 2) - 2, (int) (imagesy($gd) / 2) - 2);
        $rest = PdfProbe::meanBrightness($gd, (int) (imagesx($gd) / 2) + 2, (int) (imagesy($gd) / 2) + 2, imagesx($gd) - 1, imagesy($gd) - 1);
        $this->assertLessThan(8, $black, 'Bereich muss schwarz sein');
        // Das Plasma-Bild ist zufaellig, mitunter dunkel: der Rest muss nur deutlich heller sein als die Schwaerzung
        $this->assertGreaterThan($black + 10, $rest, 'Rest bleibt Bild');
    }

    public function test_redact_rejects_bad_rects(): void {
        $output = $this->workDir . '/none.pdf';

        $this->assertNull(PDFRedactHelper::redact($this->source, $output, [3 => [['x0' => 0, 'y0' => 0, 'x1' => 10, 'y1' => 10]]]));
        $this->assertNull(PDFRedactHelper::redact($this->source, $output, [1 => [['x0' => 10, 'y0' => 10, 'x1' => 10, 'y1' => 20]]]));
        $this->assertNull(PDFRedactHelper::redact($this->source, $output, []));
        $this->assertFileDoesNotExist($output);
    }

    public function test_probe_pdf_has_two_words_in_one_tj_array_and_the_engine_clears_both(): void {
        $this->assertFileExists(PDFRedactHelper::probePath());
        $this->assertSame('LINKS RECHTS', PdfProbe::pageText(PDFRedactHelper::probePath(), 1));
        $this->assertTrue(PDFRedactHelper::engineWorks());
        $this->assertTrue(PDFRedactHelper::isAvailable());

        $output = $this->workDir . '/sonde.pdf';
        $this->assertSame(1, PDFRedactHelper::redact(PDFRedactHelper::probePath(), $output, [1 => [['x0' => 0, 'y0' => 0, 'x1' => 595, 'y1' => 842]]]));
        // pdftotext laesst Steuerzeichen (Seitenvorschub) zurueck: es darf kein Buchstabe bleiben
        $this->assertDoesNotMatchRegularExpression('/\p{L}/u', PdfProbe::pageText($output, 1));
        $this->assertDoesNotMatchRegularExpression('/\p{L}/u', (string) PDFRedactHelper::plainText($output));
    }

    public function test_parse_layout_reads_pages_lines_and_words(): void {
        $html = '<html><body><doc><page width="595.000000" height="842.000000"><flow><block xMin="1" yMin="2" xMax="3" yMax="4">'
            . '<line xMin="1" yMin="2" xMax="3" yMax="4"><word xMin="40.0" yMin="84.0" xMax="120.0" yMax="102.0">M&#252;ller</word>'
            . '<word xMin="130.0" yMin="84.0" xMax="200.0" yMax="102.0">&amp;</word></line></block></flow></page>'
            . '<page width="842.000000" height="595.000000"><flow></flow></page></doc></body></html>';

        $pages = PDFRedactHelper::parseLayout($html, 4);

        $this->assertSame([4, 5], array_keys($pages));
        $this->assertSame(595.0, $pages[4]['width']);
        $this->assertSame('Müller', $pages[4]['lines'][0][0]['text']);
        $this->assertSame('&', $pages[4]['lines'][0][1]['text']);
        $this->assertSame(842.0, $pages[5]['width']);
        $this->assertSame([], $pages[5]['lines']);
        $this->assertSame([5], array_keys(PDFRedactHelper::parseLayout($html, 4, [5 => 0])));
    }
}
