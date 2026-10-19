<?php
/*
 * Created on   : Mon Oct 19 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : PDFWordBoxHelperTest.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace Tests\Helper;

use PDFToolkit\Config\Config;
use PDFToolkit\Entities\WordBox;
use PDFToolkit\Helper\PDFWordBoxHelper;
use Tests\Contracts\BaseTestCase;

final class PDFWordBoxHelperTest extends BaseTestCase {
    public function test_tsv_page_is_normalised_by_the_image_size_from_the_page_line(): void {
        $tsv = implode("\n", [
            "level\tpage_num\tblock_num\tpar_num\tline_num\tword_num\tleft\ttop\twidth\theight\tconf\ttext",
            "1\t1\t0\t0\t0\t0\t0\t0\t2480\t3508\t-1\t",
            "5\t1\t1\t1\t1\t1\t1240\t175\t496\t70\t96.5\tRechnungsnummer",
            "5\t1\t1\t1\t1\t2\t1860\t175\t310\t70\t91.0\t4711",
            "5\t1\t1\t1\t2\t1\t124\t3157\t248\t35\t-1\t",
        ]);

        $boxes = PDFWordBoxHelper::parseTsvPage($tsv, 3);

        $this->assertCount(2, $boxes, 'leere Wörter fallen weg');
        $this->assertSame(3, $boxes[0]->page);
        $this->assertSame('Rechnungsnummer', $boxes[0]->text);
        $this->assertEqualsWithDelta(0.5, $boxes[0]->x, 1e-6);
        $this->assertEqualsWithDelta(175 / 3508, $boxes[0]->y, 1e-6);
        $this->assertEqualsWithDelta(0.2, $boxes[0]->w, 1e-6);
        $this->assertEqualsWithDelta(96.5, $boxes[0]->confidence, 1e-6);
        $this->assertSame('4711', $boxes[1]->text);
        $this->assertTrue($boxes[1]->centerWithin(0.7, 0.0, 0.3, 0.2), 'die Nummer liegt oben rechts');
    }

    public function test_tsv_without_page_line_estimates_the_image_size_from_the_words(): void {
        $tsv = "level\tpage_num\tblock_num\tpar_num\tline_num\tword_num\tleft\ttop\twidth\theight\tconf\ttext\n"
            . "5\t1\t1\t1\t1\t1\t0\t0\t100\t10\t80\tlinks\n"
            . "5\t1\t1\t1\t1\t2\t900\t490\t100\t10\t80\trechts";

        $boxes = PDFWordBoxHelper::parseTsvPage($tsv, 1);

        $this->assertEqualsWithDelta(0.9, $boxes[1]->x, 1e-6);
        $this->assertEqualsWithDelta(0.98, $boxes[1]->y, 1e-6);
    }

    public function test_text_layer_boxes_of_a_labelled_probe_pdf_sit_where_the_labels_were_drawn(): void {
        if (!PdfProbe::toolsAvailable() || !Config::getInstance()->isExecutableAvailable('pdftotext-bbox-layout')) {
            $this->markTestSkipped('gs/pdftotext nicht verfügbar.');
        }
        $dir = sys_get_temp_dir() . '/pdf-wordbox-' . uniqid();
        mkdir($dir, 0700, true);
        try {
            $pdf = PdfProbe::labelled($this, $dir, 2);
            $boxes = PDFWordBoxHelper::fromTextLayer($pdf);
            $byText = [];
            foreach ($boxes as $box) {
                $byText[$box->text] = $box;
            }

            $this->assertInstanceOf(WordBox::class, $byText['P1-R1C1'] ?? null);
            $this->assertSame(1, $byText['P1-R1C1']->page);
            $this->assertLessThan(0.5, $byText['P1-R1C1']->x, 'Spalte 1 liegt links');
            $this->assertGreaterThan(0.5, $byText['P1-R1C2']->x, 'Spalte 2 liegt rechts');
            $this->assertLessThan($byText['P1-R5C1']->y, $byText['P1-R1C1']->y, 'Zeile 1 liegt über Zeile 5');
            $this->assertSame(2, $byText['P2-R3C2']->page);
            foreach ($boxes as $box) {
                $this->assertGreaterThanOrEqual(0.0, min($box->x, $box->y, $box->w, $box->h));
                $this->assertLessThanOrEqual(1.0, max($box->x + $box->w, $box->y + $box->h));
            }
        } finally {
            array_map('unlink', glob($dir . '/*') ?: []);
            rmdir($dir);
        }
    }
}
