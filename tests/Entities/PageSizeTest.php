<?php
/*
 * Created on   : Wed Mar 11 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : PageSizeTest.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace Tests\Entities;

use PDFToolkit\Entities\PageSize;
use PDFToolkit\Enums\PaperFormat;
use Tests\Contracts\BaseTestCase;

final class PageSizeTest extends BaseTestCase {
    public function testConstructor(): void {
        $size = new PageSize(595.28, 841.89, 1);

        $this->assertEqualsWithDelta(595.28, $size->widthPt, 0.01);
        $this->assertEqualsWithDelta(841.89, $size->heightPt, 0.01);
        $this->assertSame(1, $size->pageNumber);
    }

    public function testFromPdfInfoString(): void {
        $size = PageSize::fromPdfInfoString('595.3 x 841.9 pts (A4)', 1);

        $this->assertNotNull($size);
        $this->assertEqualsWithDelta(595.3, $size->widthPt, 0.01);
        $this->assertEqualsWithDelta(841.9, $size->heightPt, 0.01);
    }

    public function testFromPdfInfoStringWithoutFormat(): void {
        $size = PageSize::fromPdfInfoString('612 x 792 pts', 1);

        $this->assertNotNull($size);
        $this->assertEqualsWithDelta(612.0, $size->widthPt, 0.01);
        $this->assertEqualsWithDelta(792.0, $size->heightPt, 0.01);
    }

    public function testFromPdfInfoStringInvalid(): void {
        $size = PageSize::fromPdfInfoString('invalid string');
        $this->assertNull($size);
    }

    public function testFromMm(): void {
        $size = PageSize::fromMm(210.0, 297.0);

        $this->assertEqualsWithDelta(595.28, $size->widthPt, 0.1);
        $this->assertEqualsWithDelta(841.89, $size->heightPt, 0.1);
    }

    public function testFromInches(): void {
        $size = PageSize::fromInches(8.5, 11.0);

        $this->assertEquals(612.0, $size->widthPt);
        $this->assertEquals(792.0, $size->heightPt);
    }

    public function testFromFormat(): void {
        $size = PageSize::fromFormat(PaperFormat::A4);

        $this->assertEqualsWithDelta(595.28, $size->widthPt, 0.01);
        $this->assertEqualsWithDelta(841.89, $size->heightPt, 0.01);
    }

    public function testFromFormatLandscape(): void {
        $size = PageSize::fromFormat(PaperFormat::A4, landscape: true);

        // Im Landscape sind Breite und Höhe vertauscht
        $this->assertEqualsWithDelta(841.89, $size->widthPt, 0.01);
        $this->assertEqualsWithDelta(595.28, $size->heightPt, 0.01);
    }

    public function testWidthMm(): void {
        $size = new PageSize(595.28, 841.89);
        $this->assertEqualsWithDelta(210.0, $size->widthMm(), 0.1);
    }

    public function testHeightMm(): void {
        $size = new PageSize(595.28, 841.89);
        $this->assertEqualsWithDelta(297.0, $size->heightMm(), 0.1);
    }

    public function testWidthIn(): void {
        $size = new PageSize(612.0, 792.0);
        $this->assertEqualsWithDelta(8.5, $size->widthIn(), 0.01);
    }

    public function testHeightIn(): void {
        $size = new PageSize(612.0, 792.0);
        $this->assertEqualsWithDelta(11.0, $size->heightIn(), 0.01);
    }

    public function testIsLandscape(): void {
        $landscape = new PageSize(841.89, 595.28);
        $portrait = new PageSize(595.28, 841.89);

        $this->assertTrue($landscape->isLandscape());
        $this->assertFalse($portrait->isLandscape());
    }

    public function testIsPortrait(): void {
        $portrait = new PageSize(595.28, 841.89);
        $landscape = new PageSize(841.89, 595.28);

        $this->assertTrue($portrait->isPortrait());
        $this->assertFalse($landscape->isPortrait());
    }

    public function testIsSquare(): void {
        $square = new PageSize(500.0, 500.0);
        $notSquare = new PageSize(595.28, 841.89);

        $this->assertTrue($square->isSquare());
        $this->assertFalse($notSquare->isSquare());
    }

    public function testIsFormatWithEnum(): void {
        $size = new PageSize(595.28, 841.89);

        $this->assertTrue($size->isFormat(PaperFormat::A4));
        $this->assertFalse($size->isFormat(PaperFormat::LETTER));
    }

    public function testIsFormatWithString(): void {
        $size = new PageSize(595.28, 841.89);

        $this->assertTrue($size->isFormat('A4'));
        $this->assertTrue($size->isFormat('a4'));
        $this->assertFalse($size->isFormat('Letter'));
        $this->assertFalse($size->isFormat('invalid'));
    }

    public function testDetectFormat(): void {
        $a4 = new PageSize(595.28, 841.89);
        $letter = new PageSize(612.0, 792.0);
        $custom = new PageSize(500.0, 700.0);

        $this->assertSame(PaperFormat::A4, $a4->detectFormat());
        $this->assertSame(PaperFormat::LETTER, $letter->detectFormat());
        $this->assertNull($custom->detectFormat());
    }

    public function testDetectFormatLandscape(): void {
        // Landscape A4
        $size = new PageSize(841.89, 595.28);
        $this->assertSame(PaperFormat::A4, $size->detectFormat());
    }

    public function testDescription(): void {
        $a4 = new PageSize(595.28, 841.89);
        $desc = $a4->description();

        $this->assertStringContainsString('A4', $desc);
        $this->assertStringContainsString('Portrait', $desc);
    }

    public function testDescriptionLandscape(): void {
        $a4Landscape = new PageSize(841.89, 595.28);
        $desc = $a4Landscape->description();

        $this->assertStringContainsString('A4', $desc);
        $this->assertStringContainsString('Landscape', $desc);
    }

    public function testDescriptionCustomFormat(): void {
        $custom = new PageSize(500.0, 700.0);
        $desc = $custom->description();

        $this->assertStringContainsString('Custom', $desc);
    }

    public function testToArrayDefaultUnit(): void {
        $size = new PageSize(595.28, 841.89);
        $array = $size->toArray();

        $this->assertEqualsWithDelta(595.28, $array['width'], 0.01);
        $this->assertEqualsWithDelta(841.89, $array['height'], 0.01);
        $this->assertSame('pt', $array['unit']);
    }

    public function testToArrayMm(): void {
        $size = new PageSize(595.28, 841.89);
        $array = $size->toArray('mm');

        $this->assertEqualsWithDelta(210.0, $array['width'], 0.1);
        $this->assertEqualsWithDelta(297.0, $array['height'], 0.1);
        $this->assertSame('mm', $array['unit']);
    }

    public function testToArrayInches(): void {
        $size = new PageSize(612.0, 792.0);
        $array = $size->toArray('in');

        $this->assertEqualsWithDelta(8.5, $array['width'], 0.01);
        $this->assertEqualsWithDelta(11.0, $array['height'], 0.01);
        $this->assertSame('in', $array['unit']);
    }

    public function testArea(): void {
        $size = new PageSize(100.0, 200.0);
        $this->assertEquals(20000.0, $size->area());
    }

    public function testAspectRatio(): void {
        $size = new PageSize(100.0, 200.0);
        $this->assertEquals(0.5, $size->aspectRatio());
    }
}
