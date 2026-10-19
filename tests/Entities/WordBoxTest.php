<?php
/*
 * Created on   : Mon Oct 19 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : WordBoxTest.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace Tests\Entities;

use PDFToolkit\Entities\WordBox;
use PHPUnit\Framework\TestCase;

final class WordBoxTest extends TestCase {
    public function test_center_within_zone_and_array_round_trip(): void {
        $box = new WordBox(2, 0.70, 0.10, 0.20, 0.02, 'RE-4711', 96.5);

        $this->assertEqualsWithDelta(0.80, $box->centerX(), 1e-9);
        $this->assertEqualsWithDelta(0.11, $box->centerY(), 1e-9);
        $this->assertTrue($box->centerWithin(0.6, 0.05, 0.4, 0.1), 'Zone oben rechts enthält das Wort');
        $this->assertFalse($box->centerWithin(0.0, 0.0, 0.5, 0.5), 'Zone oben links nicht');

        $copy = WordBox::fromArray($box->toArray());
        $this->assertSame($box->toArray(), $copy->toArray());
        $this->assertNull(WordBox::fromArray(['page' => 1, 'text' => 'x'])->confidence);
    }
}
