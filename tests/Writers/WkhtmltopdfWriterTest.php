<?php
/*
 * Created on   : Wed Jan 22 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : WkhtmltopdfWriterTest.php
 * License      : MIT License
 * License Uri  : https://opensource.org/license/mit
 */

declare(strict_types=1);

namespace Tests\Writers;

use PDFToolkit\Entities\PDFContent;
use PDFToolkit\Enums\PDFWriterType;
use PDFToolkit\Writers\WkhtmltopdfWriter;
use PHPUnit\Framework\TestCase;

final class WkhtmltopdfWriterTest extends TestCase {
    private WkhtmltopdfWriter $writer;
    private string $tempDir;

    protected function setUp(): void {
        $this->writer = new WkhtmltopdfWriter();
        $this->tempDir = sys_get_temp_dir();
    }

    public function testGetName(): void {
        $this->assertSame(PDFWriterType::Wkhtmltopdf, WkhtmltopdfWriter::getType());
    }

    public function testGetPriority(): void {
        $this->assertEquals(30, WkhtmltopdfWriter::getPriority());
    }

    public function testSupportsHtml(): void {
        $this->assertTrue(WkhtmltopdfWriter::supportsHtml());
    }

    public function testSupportsText(): void {
        $this->assertTrue(WkhtmltopdfWriter::supportsText());
    }

    public function testIsAvailableReturnsBool(): void {
        // wkhtmltopdf ist ein externes Tool, also prüfen wir nur den Rückgabetyp
        $this->assertIsBool($this->writer->isAvailable());
    }

    public function testCanHandleReturnsCorrectValue(): void {
        $content = PDFContent::fromHtml('<p>Test</p>');

        // Wenn wkhtmltopdf verfügbar ist, sollte canHandle true sein
        if ($this->writer->isAvailable()) {
            $this->assertTrue($this->writer->canHandle($content));
        } else {
            $this->assertFalse($this->writer->canHandle($content));
        }
    }

    /**
     * @group integration
     * @group external
     */
    public function testCreatePdfFromHtml(): void {
        if (!$this->writer->isAvailable()) {
            $this->markTestSkipped('wkhtmltopdf is not available');
        }

        $html = '<html><body><h1>wkhtmltopdf Test</h1><p>Hello World</p></body></html>';
        $content = PDFContent::fromHtml($html);
        $outputPath = $this->tempDir . '/wkhtmltopdf_test_' . uniqid() . '.pdf';

        try {
            $result = $this->writer->createPdf($content, $outputPath);

            $this->assertTrue($result);
            $this->assertFileExists($outputPath);
            $this->assertGreaterThan(0, filesize($outputPath));

            // Prüfe PDF-Header
            $pdfContent = file_get_contents($outputPath);
            $this->assertStringStartsWith('%PDF-', $pdfContent);
        } finally {
            @unlink($outputPath);
        }
    }

    /**
     * @group integration
     * @group external
     */
    public function testCreatePdfFromText(): void {
        if (!$this->writer->isAvailable()) {
            $this->markTestSkipped('wkhtmltopdf is not available');
        }

        $text = "wkhtmltopdf Text Test\n\nZeile 1\nZeile 2\nZeile 3";
        $content = PDFContent::fromText($text);
        $outputPath = $this->tempDir . '/wkhtmltopdf_text_' . uniqid() . '.pdf';

        try {
            $result = $this->writer->createPdf($content, $outputPath);

            $this->assertTrue($result);
            $this->assertFileExists($outputPath);
        } finally {
            @unlink($outputPath);
        }
    }

    /**
     * @group integration
     * @group external
     */
    public function testCreatePdfWithMetadata(): void {
        if (!$this->writer->isAvailable()) {
            $this->markTestSkipped('wkhtmltopdf is not available');
        }

        $html = '<html><body><h1>Metadata Test</h1></body></html>';
        $content = PDFContent::fromHtml($html, [
            'title' => 'wkhtmltopdf Titel',
            'author' => 'wkhtmltopdf Autor',
            'subject' => 'wkhtmltopdf Betreff'
        ]);
        $outputPath = $this->tempDir . '/wkhtmltopdf_meta_' . uniqid() . '.pdf';

        try {
            $result = $this->writer->createPdf($content, $outputPath);

            $this->assertTrue($result);
            $this->assertFileExists($outputPath);
        } finally {
            @unlink($outputPath);
        }
    }

    /**
     * @group integration
     * @group external
     */
    public function testCreatePdfWithAdvancedCss(): void {
        if (!$this->writer->isAvailable()) {
            $this->markTestSkipped('wkhtmltopdf is not available');
        }

        // wkhtmltopdf hat die beste CSS-Unterstützung (WebKit)
        $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <style>
        body { font-family: Arial, sans-serif; }
        h1 { 
            color: #2c3e50; 
            border-bottom: 3px solid #3498db;
            padding-bottom: 10px;
        }
        .gradient {
            background: linear-gradient(to right, #3498db, #2ecc71);
            color: white;
            padding: 20px;
            border-radius: 10px;
        }
        table { 
            border-collapse: collapse; 
            width: 100%;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        th { background-color: #3498db; color: white; }
        th, td { padding: 12px; border: 1px solid #ddd; }
        tr:nth-child(even) { background-color: #f2f2f2; }
    </style>
</head>
<body>
    <h1>Advanced CSS Test</h1>
    <div class="gradient">
        <p>Gradient Background with border-radius</p>
    </div>
    <table>
        <tr><th>Feature</th><th>Support</th></tr>
        <tr><td>Gradients</td><td>Yes</td></tr>
        <tr><td>Border-radius</td><td>Yes</td></tr>
        <tr><td>Box-shadow</td><td>Yes</td></tr>
    </table>
</body>
</html>
HTML;

        $content = PDFContent::fromHtml($html);
        $outputPath = $this->tempDir . '/wkhtmltopdf_css_' . uniqid() . '.pdf';

        try {
            $result = $this->writer->createPdf($content, $outputPath);

            $this->assertTrue($result);
            $this->assertFileExists($outputPath);
        } finally {
            @unlink($outputPath);
        }
    }

    /**
     * @group integration
     * @group external
     */
    public function testCreatePdfWithLandscapeOrientation(): void {
        if (!$this->writer->isAvailable()) {
            $this->markTestSkipped('wkhtmltopdf is not available');
        }

        $html = '<html><body><h1>Landscape Test</h1></body></html>';
        $content = PDFContent::fromHtml($html);
        $outputPath = $this->tempDir . '/wkhtmltopdf_landscape_' . uniqid() . '.pdf';

        try {
            $result = $this->writer->createPdf($content, $outputPath, [
                'orientation' => 'landscape'
            ]);

            $this->assertTrue($result);
            $this->assertFileExists($outputPath);
        } finally {
            @unlink($outputPath);
        }
    }

    /**
     * @group integration
     * @group external
     */
    public function testCreatePdfString(): void {
        if (!$this->writer->isAvailable()) {
            $this->markTestSkipped('wkhtmltopdf is not available');
        }

        $html = '<html><body><p>String Test</p></body></html>';
        $content = PDFContent::fromHtml($html);

        $pdfString = $this->writer->createPdfString($content);

        $this->assertNotNull($pdfString);
        $this->assertStringStartsWith('%PDF-', $pdfString);
        $this->assertGreaterThan(100, strlen($pdfString));
    }

    /**
     * @group integration
     * @group external
     */
    public function testCreatePdfWithDifferentPaperSizes(): void {
        if (!$this->writer->isAvailable()) {
            $this->markTestSkipped('wkhtmltopdf is not available');
        }

        $html = '<html><body><h1>Paper Size Test</h1></body></html>';
        $content = PDFContent::fromHtml($html);

        $paperSizes = ['A4', 'A5', 'Letter', 'Legal'];

        foreach ($paperSizes as $size) {
            $outputPath = $this->tempDir . '/wkhtmltopdf_' . strtolower($size) . '_' . uniqid() . '.pdf';

            try {
                $result = $this->writer->createPdf($content, $outputPath, [
                    'paper_size' => $size
                ]);

                $this->assertTrue($result, "Failed for paper size: $size");
                $this->assertFileExists($outputPath);
            } finally {
                @unlink($outputPath);
            }
        }
    }

    /**
     * @group integration
     * @group external
     */
    public function testCreatePdfWithMargins(): void {
        if (!$this->writer->isAvailable()) {
            $this->markTestSkipped('wkhtmltopdf is not available');
        }

        $html = '<html><body><h1>Margin Test</h1><p>Content with custom margins</p></body></html>';
        $content = PDFContent::fromHtml($html);
        $outputPath = $this->tempDir . '/wkhtmltopdf_margins_' . uniqid() . '.pdf';

        try {
            $result = $this->writer->createPdf($content, $outputPath, [
                'margins' => [
                    'top' => '30mm',
                    'bottom' => '30mm',
                    'left' => '20mm',
                    'right' => '20mm'
                ]
            ]);

            $this->assertTrue($result);
            $this->assertFileExists($outputPath);
        } finally {
            @unlink($outputPath);
        }
    }

    /**
     * @group integration
     * @group external
     */
    public function testCreatePdfWithSpecialCharacters(): void {
        if (!$this->writer->isAvailable()) {
            $this->markTestSkipped('wkhtmltopdf is not available');
        }

        $html = '<html><head><meta charset="UTF-8"></head><body><p>Sonderzeichen: äöü ÄÖÜ ß € © ® ™ α β γ δ</p></body></html>';
        $content = PDFContent::fromHtml($html);
        $outputPath = $this->tempDir . '/wkhtmltopdf_special_' . uniqid() . '.pdf';

        try {
            $result = $this->writer->createPdf($content, $outputPath);

            $this->assertTrue($result);
            $this->assertFileExists($outputPath);
        } finally {
            @unlink($outputPath);
        }
    }

    /**
     * @group integration
     * @group external
     */
    public function testCreatePdfWithGrayscale(): void {
        if (!$this->writer->isAvailable()) {
            $this->markTestSkipped('wkhtmltopdf is not available');
        }

        $html = '<html><body style="background: #3498db;"><h1 style="color: red;">Grayscale Test</h1></body></html>';
        $content = PDFContent::fromHtml($html);
        $outputPath = $this->tempDir . '/wkhtmltopdf_grayscale_' . uniqid() . '.pdf';

        try {
            $result = $this->writer->createPdf($content, $outputPath, [
                'grayscale' => true
            ]);

            $this->assertTrue($result);
            $this->assertFileExists($outputPath);
        } finally {
            @unlink($outputPath);
        }
    }

    /**
     * @group integration
     * @group external
     */
    public function testCreatePdfWithCustomDpi(): void {
        if (!$this->writer->isAvailable()) {
            $this->markTestSkipped('wkhtmltopdf is not available');
        }

        $html = '<html><body><h1>DPI Test</h1></body></html>';
        $content = PDFContent::fromHtml($html);
        $outputPath = $this->tempDir . '/wkhtmltopdf_dpi_' . uniqid() . '.pdf';

        try {
            $result = $this->writer->createPdf($content, $outputPath, [
                'dpi' => 150
            ]);

            $this->assertTrue($result);
            $this->assertFileExists($outputPath);
        } finally {
            @unlink($outputPath);
        }
    }
}
