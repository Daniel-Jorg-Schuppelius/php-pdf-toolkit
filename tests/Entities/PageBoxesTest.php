<?php
/*
 * Created on   : Thu Nov 12 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : PageBoxesTest.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace Tests\Entities;

use PDFToolkit\Entities\{PageBoxes, PageSize};
use Tests\Contracts\BaseTestCase;

final class PageBoxesTest extends BaseTestCase {
    public function test_largest_takes_the_larger_box_per_edge(): void {
        $boxes = new PageBoxes(3, new PageSize(9000, 500, 3), new PageSize(595, 842, 3));

        $largest = $boxes->largest();

        $this->assertSame(9000.0, $largest->widthPt);
        $this->assertSame(842.0, $largest->heightPt);
        $this->assertSame(3, $largest->pageNumber);
    }

    public function test_largest_keeps_the_rotation(): void {
        $boxes = new PageBoxes(1, new PageSize(9000, 4000, 1, 90), new PageSize(595, 842, 1, 90), 90);

        $largest = $boxes->largest();

        $this->assertSame(90, $largest->rotation);
        $this->assertSame(4000.0, $largest->displayWidthPt());
        $this->assertSame(9000.0, $largest->displayHeightPt());
        $this->assertTrue($largest->isPortrait());
    }
}
