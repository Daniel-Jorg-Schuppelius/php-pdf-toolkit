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
    public function test_constructor(): void {
        $size = new PageSize(595.28, 841.89, 1);

        $this->assertEqualsWithDelta(595.28, $size->widthPt, 0.01);
        $this->assertEqualsWithDelta(841.89, $size->heightPt, 0.01);
        $this->assertSame(1, $size->pageNumber);
    }

    public function test_from_pdf_info_string(): void {
        $size = PageSize::fromPdfInfoString('595.3 x 841.9 pts (A4)', 1);

        $this->assertNotNull($size);
        $this->assertEqualsWithDelta(595.3, $size->widthPt, 0.01);
        $this->assertEqualsWithDelta(841.9, $size->heightPt, 0.01);
    }

    public function test_from_pdf_info_string_without_format(): void {
        $size = PageSize::fromPdfInfoString('612 x 792 pts', 1);

        $this->assertNotNull($size);
        $this->assertEqualsWithDelta(612.0, $size->widthPt, 0.01);
        $this->assertEqualsWithDelta(792.0, $size->heightPt, 0.01);
    }

    public function test_from_pdf_info_string_invalid(): void {
        $size = PageSize::fromPdfInfoString('invalid string');
        $this->assertNull($size);
    }

    public function test_from_mm(): void {
        $size = PageSize::fromMm(210.0, 297.0);

        $this->assertEqualsWithDelta(595.28, $size->widthPt, 0.1);
        $this->assertEqualsWithDelta(841.89, $size->heightPt, 0.1);
    }

    public function test_from_inches(): void {
        $size = PageSize::fromInches(8.5, 11.0);

        $this->assertEquals(612.0, $size->widthPt);
        $this->assertEquals(792.0, $size->heightPt);
    }

    public function test_from_format(): void {
        $size = PageSize::fromFormat(PaperFormat::A4);

        $this->assertEqualsWithDelta(595.28, $size->widthPt, 0.01);
        $this->assertEqualsWithDelta(841.89, $size->heightPt, 0.01);
    }

    public function test_from_format_landscape(): void {
        $size = PageSize::fromFormat(PaperFormat::A4, landscape: true);

        // Im Landscape sind Breite und Höhe vertauscht
        $this->assertEqualsWithDelta(841.89, $size->widthPt, 0.01);
        $this->assertEqualsWithDelta(595.28, $size->heightPt, 0.01);
    }

    public function test_width_mm(): void {
        $size = new PageSize(595.28, 841.89);
        $this->assertEqualsWithDelta(210.0, $size->widthMm(), 0.1);
    }

    public function test_height_mm(): void {
        $size = new PageSize(595.28, 841.89);
        $this->assertEqualsWithDelta(297.0, $size->heightMm(), 0.1);
    }

    public function test_width_in(): void {
        $size = new PageSize(612.0, 792.0);
        $this->assertEqualsWithDelta(8.5, $size->widthIn(), 0.01);
    }

    public function test_height_in(): void {
        $size = new PageSize(612.0, 792.0);
        $this->assertEqualsWithDelta(11.0, $size->heightIn(), 0.01);
    }

    public function test_is_landscape(): void {
        $landscape = new PageSize(841.89, 595.28);
        $portrait = new PageSize(595.28, 841.89);

        $this->assertTrue($landscape->isLandscape());
        $this->assertFalse($portrait->isLandscape());
    }

    public function test_is_portrait(): void {
        $portrait = new PageSize(595.28, 841.89);
        $landscape = new PageSize(841.89, 595.28);

        $this->assertTrue($portrait->isPortrait());
        $this->assertFalse($landscape->isPortrait());
    }

    public function test_is_square(): void {
        $square = new PageSize(500.0, 500.0);
        $notSquare = new PageSize(595.28, 841.89);

        $this->assertTrue($square->isSquare());
        $this->assertFalse($notSquare->isSquare());
    }

    public function test_is_format_with_enum(): void {
        $size = new PageSize(595.28, 841.89);

        $this->assertTrue($size->isFormat(PaperFormat::A4));
        $this->assertFalse($size->isFormat(PaperFormat::LETTER));
    }

    public function test_is_format_with_string(): void {
        $size = new PageSize(595.28, 841.89);

        $this->assertTrue($size->isFormat('A4'));
        $this->assertTrue($size->isFormat('a4'));
        $this->assertFalse($size->isFormat('Letter'));
        $this->assertFalse($size->isFormat('invalid'));
    }

    public function test_detect_format(): void {
        $a4 = new PageSize(595.28, 841.89);
        $letter = new PageSize(612.0, 792.0);
        $custom = new PageSize(500.0, 700.0);

        $this->assertSame(PaperFormat::A4, $a4->detectFormat());
        $this->assertSame(PaperFormat::LETTER, $letter->detectFormat());
        $this->assertNull($custom->detectFormat());
    }

    public function test_detect_format_landscape(): void {
        // Landscape A4
        $size = new PageSize(841.89, 595.28);
        $this->assertSame(PaperFormat::A4, $size->detectFormat());
    }

    public function test_description(): void {
        $a4 = new PageSize(595.28, 841.89);
        $desc = $a4->description();

        $this->assertStringContainsString('A4', $desc);
        $this->assertStringContainsString('Portrait', $desc);
    }

    public function test_description_landscape(): void {
        $a4Landscape = new PageSize(841.89, 595.28);
        $desc = $a4Landscape->description();

        $this->assertStringContainsString('A4', $desc);
        $this->assertStringContainsString('Landscape', $desc);
    }

    public function test_description_custom_format(): void {
        $custom = new PageSize(500.0, 700.0);
        $desc = $custom->description();

        $this->assertStringContainsString('Custom', $desc);
    }

    public function test_to_array_default_unit(): void {
        $size = new PageSize(595.28, 841.89);
        $array = $size->toArray();

        $this->assertEqualsWithDelta(595.28, $array['width'], 0.01);
        $this->assertEqualsWithDelta(841.89, $array['height'], 0.01);
        $this->assertSame('pt', $array['unit']);
    }

    public function test_to_array_mm(): void {
        $size = new PageSize(595.28, 841.89);
        $array = $size->toArray('mm');

        $this->assertEqualsWithDelta(210.0, $array['width'], 0.1);
        $this->assertEqualsWithDelta(297.0, $array['height'], 0.1);
        $this->assertSame('mm', $array['unit']);
    }

    public function test_to_array_inches(): void {
        $size = new PageSize(612.0, 792.0);
        $array = $size->toArray('in');

        $this->assertEqualsWithDelta(8.5, $array['width'], 0.01);
        $this->assertEqualsWithDelta(11.0, $array['height'], 0.01);
        $this->assertSame('in', $array['unit']);
    }

    public function test_area(): void {
        $size = new PageSize(100.0, 200.0);
        $this->assertEquals(20000.0, $size->area());
    }

    public function test_aspect_ratio(): void {
        $size = new PageSize(100.0, 200.0);
        $this->assertEquals(0.5, $size->aspectRatio());
    }
}
