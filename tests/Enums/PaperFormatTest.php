<?php
/*
 * Created on   : Wed Mar 11 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : PaperFormatTest.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace Tests\Enums;

use PDFToolkit\Enums\PaperFormat;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Contracts\BaseTestCase;

final class PaperFormatTest extends BaseTestCase {
    public function testA4Dimensions(): void {
        // A4 ist 210 x 297 mm = 595.28 x 841.89 pts
        $this->assertEqualsWithDelta(595.28, PaperFormat::A4->widthPt(), 0.1);
        $this->assertEqualsWithDelta(841.89, PaperFormat::A4->heightPt(), 0.1);
    }

    public function testLetterDimensions(): void {
        // Letter ist 8.5 x 11 inch = 612 x 792 pts
        $this->assertEquals(612.0, PaperFormat::LETTER->widthPt());
        $this->assertEquals(792.0, PaperFormat::LETTER->heightPt());
    }

    public function testDimensionsMm(): void {
        [$width, $height] = PaperFormat::A4->dimensionsMm();
        $this->assertEqualsWithDelta(210, $width, 0.5);
        $this->assertEqualsWithDelta(297, $height, 0.5);
    }

    public function testDimensionsIn(): void {
        [$width, $height] = PaperFormat::LETTER->dimensionsIn();
        $this->assertEqualsWithDelta(8.5, $width, 0.01);
        $this->assertEqualsWithDelta(11.0, $height, 0.01);
    }

    public function testMatchesPortrait(): void {
        $this->assertTrue(PaperFormat::A4->matches(595.28, 841.89));
        $this->assertTrue(PaperFormat::A4->matches(595.0, 842.0, 5.0));
        $this->assertFalse(PaperFormat::A4->matches(500.0, 700.0));
    }

    public function testMatchesLandscape(): void {
        // Landscape A4
        $this->assertTrue(PaperFormat::A4->matches(841.89, 595.28, 5.0, true));
        $this->assertFalse(PaperFormat::A4->matches(841.89, 595.28, 5.0, false));
    }

    public function testFromStringValid(): void {
        $this->assertSame(PaperFormat::A4, PaperFormat::fromString('A4'));
        $this->assertSame(PaperFormat::A4, PaperFormat::fromString('a4'));
        $this->assertSame(PaperFormat::LETTER, PaperFormat::fromString('Letter'));
        $this->assertSame(PaperFormat::LETTER, PaperFormat::fromString('LETTER'));
        $this->assertSame(PaperFormat::JIS_B4, PaperFormat::fromString('JIS-B4'));
        $this->assertSame(PaperFormat::JIS_B4, PaperFormat::fromString('jisb4'));
    }

    public function testFromStringInvalid(): void {
        $this->assertNull(PaperFormat::fromString('invalid'));
        $this->assertNull(PaperFormat::fromString(''));
    }

    public function testDetectA4(): void {
        $format = PaperFormat::detect(595.28, 841.89);
        $this->assertSame(PaperFormat::A4, $format);
    }

    public function testDetectLetter(): void {
        $format = PaperFormat::detect(612.0, 792.0);
        $this->assertSame(PaperFormat::LETTER, $format);
    }

    public function testDetectLandscapeA4(): void {
        // Landscape A4
        $format = PaperFormat::detect(841.89, 595.28);
        $this->assertSame(PaperFormat::A4, $format);
    }

    public function testDetectUnknown(): void {
        $format = PaperFormat::detect(500.0, 700.0);
        $this->assertNull($format);
    }

    public function testIsLandscape(): void {
        // Landscape A4 Abmessungen
        $this->assertTrue(PaperFormat::A4->isLandscape(841.89, 595.28));
        // Portrait A4
        $this->assertFalse(PaperFormat::A4->isLandscape(595.28, 841.89));
    }

    public function testDescription(): void {
        $desc = PaperFormat::A4->description();
        $this->assertStringContainsString('A4', $desc);
        $this->assertStringContainsString('210', $desc);
        $this->assertStringContainsString('297', $desc);
    }

    #[DataProvider('allFormatsProvider')]
    public function testAllFormatsHaveValidDimensions(PaperFormat $format): void {
        $this->assertGreaterThan(0, $format->widthPt());
        $this->assertGreaterThan(0, $format->heightPt());
        // Die meisten Standardformate sind Portrait (Breite < Höhe)
        // Ausnahme: Ledger ist per Definition im Landscape-Format (Tabloid gedreht)
        if ($format !== PaperFormat::LEDGER) {
            $this->assertLessThan($format->heightPt(), $format->widthPt());
        } else {
            // Ledger: 17 x 11 inch (Landscape)
            $this->assertGreaterThan($format->heightPt(), $format->widthPt());
        }
    }

    /**
     * @return array<string, array{PaperFormat}>
     */
    public static function allFormatsProvider(): array {
        $data = [];
        foreach (PaperFormat::cases() as $format) {
            $data[$format->value] = [$format];
        }
        return $data;
    }
}
