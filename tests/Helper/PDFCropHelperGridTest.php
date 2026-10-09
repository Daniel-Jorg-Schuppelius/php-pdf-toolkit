<?php
/*
 * Created on   : Fri Oct 09 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : PDFCropHelperGridTest.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace Tests\Helper;

use PDFToolkit\Helper\PDFCropHelper;
use Tests\Contracts\BaseTestCase;

/**
 * Zerlegen in ein Raster ({@see PDFCropHelper::cropToGrid()}).
 *
 * Die Probe-PDF wird im Test erzeugt: drei A4-Seiten, jede in 5 Zeilen x 2
 * Spalten beschriftet ("P2-R3C1" = Seite 2, Zeile 3, Spalte 1). So prüft der
 * Text jedes Teils, ob Ausschnitt und Reihenfolge stimmen.
 */
final class PDFCropHelperGridTest extends BaseTestCase {
    private const A4_WIDTH = 595.0;
    private const A4_HEIGHT = 842.0;
    private const TOLERANCE = 1.0;

    private string $workDir;
    private string $source;

    protected function setUp(): void {
        parent::setUp();

        if (!PDFCropHelper::isGridAvailable()) {
            $this->markTestSkipped('mutool (mutool-grid-split) nicht verfügbar');
        }
        foreach (['gs', 'pdftotext', 'pdfinfo'] as $tool) {
            if (trim((string) shell_exec('command -v ' . $tool)) === '') {
                $this->markTestSkipped("$tool nicht verfügbar");
            }
        }

        $this->workDir = sys_get_temp_dir() . '/pdf-grid-test-' . bin2hex(random_bytes(6));
        mkdir($this->workDir, 0755, true);
        $this->source = $this->createLabelledPdf();
    }

    protected function tearDown(): void {
        if (isset($this->workDir) && is_dir($this->workDir)) {
            $this->removeDir($this->workDir);
        }
    }

    public function test_splits_every_page_in_reading_order(): void {
        $output = $this->workDir . '/grid.pdf';

        $pages = PDFCropHelper::cropToGrid($this->source, $output, 5, 2);

        $this->assertSame(30, $pages);
        $this->assertSame(30, $this->pageCount($output));
        $this->assertSame('P1-R1C1', $this->pageText($output, 1));
        $this->assertSame('P1-R1C2', $this->pageText($output, 2));
        $this->assertSame('P1-R2C1', $this->pageText($output, 3));
        $this->assertSame('P1-R5C2', $this->pageText($output, 10));
        $this->assertSame('P2-R1C1', $this->pageText($output, 11));
        $this->assertSame('P3-R5C2', $this->pageText($output, 30));
    }

    public function test_parts_have_the_cell_size(): void {
        $output = $this->workDir . '/halves.pdf';

        PDFCropHelper::cropToGrid($this->source, $output, 2, 1);

        $size = $this->pageSize($output, 1);
        $this->assertEqualsWithDelta(self::A4_WIDTH, $size[0], self::TOLERANCE);
        $this->assertEqualsWithDelta(self::A4_HEIGHT / 2, $size[1], self::TOLERANCE);
    }

    public function test_content_is_shared_not_copied(): void {
        $output = $this->workDir . '/grid.pdf';

        PDFCropHelper::cropToGrid($this->source, $output, 5, 2);

        // Zehn Teile je Seite dürfen die Datei nicht verzehnfachen
        $this->assertLessThan(filesize($this->source) * 4, filesize($output));
    }

    public function test_pages_outside_the_range_stay_unchanged(): void {
        $output = $this->workDir . '/range.pdf';

        $pages = PDFCropHelper::cropToGrid($this->source, $output, 2, 1, 2, 2);

        // Seite 1 unverändert, Seite 2 in zwei Teile, Seite 3 unverändert
        $this->assertSame(4, $pages);
        $this->assertStringContainsString('P1-R5C2', $this->pageText($output, 1));
        $this->assertSame(self::A4_HEIGHT, round($this->pageSize($output, 1)[1]));
        $this->assertStringStartsWith('P2-R1C1', $this->pageText($output, 2));
        $this->assertStringContainsString('P2-R5C2', $this->pageText($output, 3));
        $this->assertStringContainsString('P3-R1C1', $this->pageText($output, 4));
    }

    public function test_last_page_zero_means_to_the_end(): void {
        $output = $this->workDir . '/from2.pdf';

        $pages = PDFCropHelper::cropToGrid($this->source, $output, 5, 2, 2, 0);

        $this->assertSame(21, $pages);
        $this->assertStringContainsString('P1-R3C1', $this->pageText($output, 1));
        $this->assertSame('P2-R1C1', $this->pageText($output, 2));
    }

    public function test_margins_are_left_out(): void {
        $output = $this->workDir . '/margin.pdf';
        $rowHeight = self::A4_HEIGHT / 5;

        // Oberste Zeile als Rand: die übrigen vier Zeilen ergeben die Teile
        $pages = PDFCropHelper::cropToGrid($this->source, $output, 4, 1, 1, 1, [$rowHeight, 0, 0, 0]);

        $this->assertSame(6, $pages);
        $this->assertSame('P1-R2C1 P1-R2C2', $this->pageText($output, 1));
        $this->assertSame('P1-R5C1 P1-R5C2', $this->pageText($output, 4));
    }

    public function test_rotated_page_is_split_as_displayed(): void {
        $rotated = $this->rotate($this->source, 90);
        $output = $this->workDir . '/rotated.pdf';

        PDFCropHelper::cropToGrid($rotated, $output, 1, 2, 1, 1);

        // Gedreht um 90 Grad im Uhrzeigersinn liegt die untere Hälfte der
        // ungedrehten Seite (Zeilen 3-5) in der Anzeige links - sie kommt zuerst
        $this->assertStringContainsString('P1-R5C1', $this->pageText($output, 1));
        $this->assertStringNotContainsString('P1-R1C1', $this->pageText($output, 1));
        $this->assertStringContainsString('P1-R1C1', $this->pageText($output, 2));
    }

    public function test_separate_files_in_result_order(): void {
        $dir = $this->workDir . '/parts';

        $files = PDFCropHelper::cropToGridFiles($this->source, $dir, 2, 1, 1, 2);

        // Seiten 1 und 2 je zwei Teile, Seite 3 unverändert als eigene Datei
        $this->assertCount(5, $files);
        $this->assertSame($dir . '/000001.pdf', $files[0]);
        $this->assertStringStartsWith('P1-R1C1', $this->pageText($files[0], 1));
        $this->assertStringStartsWith('P1-R3C1', $this->pageText($files[1], 1));
        $this->assertStringStartsWith('P2-R1C1', $this->pageText($files[2], 1));
        $this->assertStringContainsString('P3-R5C2', $this->pageText($files[4], 1));
        foreach ($files as $file) {
            $this->assertSame(1, $this->pageCount($file));
        }
    }

    public function test_rejects_a_single_part(): void {
        $this->assertNull(PDFCropHelper::cropToGrid($this->source, $this->workDir . '/one.pdf', 1, 1));
    }

    public function test_rejects_margins_larger_than_the_page(): void {
        $this->assertNull(PDFCropHelper::cropToGrid($this->source, $this->workDir . '/big.pdf', 2, 1, 1, 0, [500, 0, 500, 0]));
    }

    public function test_rejects_a_range_beyond_the_document(): void {
        $this->assertNull(PDFCropHelper::cropToGrid($this->source, $this->workDir . '/range.pdf', 2, 1, 5, 0));
    }

    public function test_rejects_invalid_input(): void {
        $this->assertNull(PDFCropHelper::cropToGrid('/nonexistent/file.pdf', $this->workDir . '/x.pdf', 2, 1));
        $this->assertSame([], PDFCropHelper::cropToGridFiles('/nonexistent/file.pdf', $this->workDir . '/x', 2, 1));
    }

    // --- Hilfen ---

    private function createLabelledPdf(): string {
        $ps = $this->workDir . '/labels.ps';
        file_put_contents($ps, <<<'PS'
            /Helvetica findfont 20 scalefont setfont
            1 1 3 { /p exch def
              0 1 4 { /r exch def 0 1 1 { /c exch def
                c 297.5 mul 40 add  842 r 1 add 168.4 mul sub 70 add moveto
                (P) show p 3 string cvs show (-R) show r 1 add 3 string cvs show (C) show c 1 add 3 string cvs show
              } for } for showpage } for
            PS);

        $pdf = $this->workDir . '/labels.pdf';
        exec(sprintf(
            'gs -q -sDEVICE=pdfwrite -dDEVICEWIDTHPOINTS=595 -dDEVICEHEIGHTPOINTS=842 -dFIXEDMEDIA -o %s %s 2>&1',
            escapeshellarg($pdf),
            escapeshellarg($ps)
        ), $output, $code);
        $this->assertSame(0, $code, 'Probe-PDF konnte nicht erzeugt werden: ' . implode("\n", $output));

        return $pdf;
    }

    private function rotate(string $pdf, int $angle): string {
        $script = $this->workDir . '/rotate.js';
        file_put_contents($script, <<<JS
            var doc = Document.openDocument(scriptArgs[0]);
            for (var i = 0; i < doc.countPages(); i++) doc.findPage(i).put("Rotate", $angle);
            doc.save(scriptArgs[1]);
            JS);

        $rotated = $this->workDir . '/rotated-source.pdf';
        exec(sprintf('mutool run %s %s %s 2>&1', escapeshellarg($script), escapeshellarg($pdf), escapeshellarg($rotated)), $output, $code);
        $this->assertSame(0, $code, implode("\n", $output));

        return $rotated;
    }

    private function pageText(string $pdf, int $page): string {
        $text = (string) shell_exec(sprintf('pdftotext -f %d -l %d %s - 2>/dev/null', $page, $page, escapeshellarg($pdf)));

        return trim((string) preg_replace('/\s+/', ' ', $text));
    }

    private function pageCount(string $pdf): int {
        $info = (string) shell_exec('pdfinfo ' . escapeshellarg($pdf) . ' 2>/dev/null');

        return preg_match('/^Pages:\s+(\d+)/m', $info, $m) ? (int) $m[1] : 0;
    }

    /** @return array{0: float, 1: float} Breite und Höhe in Punkten */
    private function pageSize(string $pdf, int $page): array {
        $info = (string) shell_exec(sprintf('pdfinfo -f %d -l %d %s 2>/dev/null', $page, $page, escapeshellarg($pdf)));
        $this->assertMatchesRegularExpression('/Page\s+\d+\s+size:\s+([\d.]+) x ([\d.]+)/', $info);
        preg_match('/Page\s+\d+\s+size:\s+([\d.]+) x ([\d.]+)/', $info, $m);

        return [(float) $m[1], (float) $m[2]];
    }

    private function removeDir(string $dir): void {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
