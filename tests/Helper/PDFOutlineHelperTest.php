<?php
/*
 * Created on   : Sat Oct 18 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : PDFOutlineHelperTest.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace Tests\Helper;

use PDFToolkit\Helper\PDFOutlineHelper;
use Tests\Contracts\BaseTestCase;

/**
 * Lesezeichen setzen (pdf-outline.js), gelesen mit mutool show.
 */
final class PDFOutlineHelperTest extends BaseTestCase {
    private string $workDir;
    private string $source;

    protected function setUp(): void {
        parent::setUp();
        if (!PDFOutlineHelper::isAvailable() || !PdfProbe::toolsAvailable()) {
            $this->markTestSkipped('mutool (mutool-pdf-outline) oder poppler nicht verfügbar');
        }
        $this->workDir = sys_get_temp_dir() . '/pdf-outline-test-' . bin2hex(random_bytes(6));
        mkdir($this->workDir, 0755, true);
        $this->source = PdfProbe::labelled($this, $this->workDir, 3);
    }

    protected function tearDown(): void {
        if (isset($this->workDir) && is_dir($this->workDir)) {
            PdfProbe::removeDir($this->workDir);
        }
    }

    public function test_set_writes_bookmarks_in_page_order(): void {
        $output = $this->workDir . '/outline.pdf';

        $this->assertSame(2, PDFOutlineHelper::set($this->source, $output, [
            ['page' => 1, 'title' => "Januar 2026 – Auszug 1\tmit Tab"],
            ['page' => 3, 'title' => 'Februar 2026 (Müller & Söhne)'],
        ]));

        // mutool show outline: Seitenangabe je Version "#page=1", "#1" oder "#1,0,842";
        // MuPDF 1.17 druckt Titel mit Nicht-ASCII-Zeichen nicht als UTF-8
        $outline = PdfProbe::outline($output);
        $this->assertMatchesRegularExpression('/Auszug 1 mit Tab"\s+#(page=)?1\b/', $outline);
        $this->assertMatchesRegularExpression('/Februar 2026 \(M.*hne\)"\s+#(page=)?3\b/', $outline);
        if (mb_check_encoding($outline, 'UTF-8')) {
            $this->assertStringContainsString('"Januar 2026 – Auszug 1 mit Tab"', $outline);
            $this->assertStringContainsString('"Februar 2026 (Müller & Söhne)"', $outline);
        }
        $this->assertSame(3, PdfProbe::pageCount($output));
        $this->assertStringStartsWith('P2-', PdfProbe::pageText($output, 2));

        // Erneutes Setzen ersetzt die Liste
        $replaced = $this->workDir . '/replaced.pdf';
        $this->assertSame(1, PDFOutlineHelper::set($output, $replaced, [['page' => 2, 'title' => 'Nur zwei']]));
        $outline = PdfProbe::outline($replaced);
        $this->assertStringContainsString('"Nur zwei"', $outline);
        $this->assertStringNotContainsString('Januar', $outline);
    }

    public function test_set_rejects_missing_pages_titles_and_empty_lists(): void {
        $output = $this->workDir . '/none.pdf';

        $this->assertNull(PDFOutlineHelper::set($this->source, $output, [['page' => 4, 'title' => 'Zu weit']]));
        $this->assertNull(PDFOutlineHelper::set($this->source, $output, [['page' => 1, 'title' => "  \n "]]));
        $this->assertNull(PDFOutlineHelper::set($this->source, $output, []));
        $this->assertFileDoesNotExist($output);
    }

    public function test_clean_title_shortens_and_flattens(): void {
        $this->assertSame('a b c', PDFOutlineHelper::cleanTitle("a\r\nb\tc"));
        $long = PDFOutlineHelper::cleanTitle(str_repeat('x', 300));
        $this->assertSame(PDFOutlineHelper::MAX_TITLE_LENGTH, mb_strlen($long));
        $this->assertStringEndsWith('...', $long);
    }
}
