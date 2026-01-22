<?php
/*
 * Created on   : Wed Jan 22 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : DompdfWriterTest.php
 * License      : MIT License
 * License Uri  : https://opensource.org/license/mit
 */

declare(strict_types=1);

namespace Tests\Writers;

use PDFToolkit\Entities\PDFContent;
use PDFToolkit\Writers\DompdfWriter;
use PHPUnit\Framework\TestCase;

final class DompdfWriterTest extends TestCase {
    private DompdfWriter $writer;
    private string $tempDir;

    protected function setUp(): void {
        $this->writer = new DompdfWriter();
        $this->tempDir = sys_get_temp_dir();
    }

    public function testGetName(): void {
        $this->assertEquals('dompdf', DompdfWriter::getName());
    }

    public function testGetPriority(): void {
        $this->assertEquals(10, DompdfWriter::getPriority());
    }

    public function testSupportsHtml(): void {
        $this->assertTrue(DompdfWriter::supportsHtml());
    }

    public function testSupportsText(): void {
        $this->assertTrue(DompdfWriter::supportsText());
    }

    public function testIsAvailable(): void {
        // Dompdf sollte als Dependency installiert sein
        $this->assertTrue($this->writer->isAvailable());
    }

    public function testCanHandle(): void {
        $content = PDFContent::fromHtml('<p>Test</p>');
        $this->assertTrue($this->writer->canHandle($content));
    }

    /**
     * @group integration
     */
    public function testCreatePdfFromHtml(): void {
        if (!$this->writer->isAvailable()) {
            $this->markTestSkipped('Dompdf is not available');
        }

        $html = '<html><body><h1>Test</h1><p>Hello World</p></body></html>';
        $content = PDFContent::fromHtml($html);
        $outputPath = $this->tempDir . '/dompdf_test_' . uniqid() . '.pdf';

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
     */
    public function testCreatePdfFromText(): void {
        if (!$this->writer->isAvailable()) {
            $this->markTestSkipped('Dompdf is not available');
        }

        $text = "Zeile 1\nZeile 2\nZeile 3";
        $content = PDFContent::fromText($text);
        $outputPath = $this->tempDir . '/dompdf_text_' . uniqid() . '.pdf';

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
     */
    public function testCreatePdfWithMetadata(): void {
        if (!$this->writer->isAvailable()) {
            $this->markTestSkipped('Dompdf is not available');
        }

        $html = '<html><body><h1>Metadata Test</h1></body></html>';
        $content = PDFContent::fromHtml($html, [
            'title' => 'Test Titel',
            'author' => 'Test Autor',
            'subject' => 'Test Betreff'
        ]);
        $outputPath = $this->tempDir . '/dompdf_meta_' . uniqid() . '.pdf';

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
     */
    public function testCreatePdfWithCss(): void {
        if (!$this->writer->isAvailable()) {
            $this->markTestSkipped('Dompdf is not available');
        }

        $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <style>
        body { font-family: Arial, sans-serif; }
        h1 { color: blue; border-bottom: 2px solid blue; }
        .highlight { background-color: yellow; }
        table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid #ddd; padding: 8px; }
    </style>
</head>
<body>
    <h1>CSS Test</h1>
    <p class="highlight">Markierter Text</p>
    <table>
        <tr><th>Header 1</th><th>Header 2</th></tr>
        <tr><td>Zelle 1</td><td>Zelle 2</td></tr>
    </table>
</body>
</html>
HTML;

        $content = PDFContent::fromHtml($html);
        $outputPath = $this->tempDir . '/dompdf_css_' . uniqid() . '.pdf';

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
     */
    public function testCreatePdfWithLandscapeOrientation(): void {
        if (!$this->writer->isAvailable()) {
            $this->markTestSkipped('Dompdf is not available');
        }

        $html = '<html><body><h1>Landscape Test</h1></body></html>';
        $content = PDFContent::fromHtml($html);
        $outputPath = $this->tempDir . '/dompdf_landscape_' . uniqid() . '.pdf';

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
     */
    public function testCreatePdfString(): void {
        if (!$this->writer->isAvailable()) {
            $this->markTestSkipped('Dompdf is not available');
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
     */
    public function testCreatePdfWithDifferentPaperSizes(): void {
        if (!$this->writer->isAvailable()) {
            $this->markTestSkipped('Dompdf is not available');
        }

        $html = '<html><body><h1>Paper Size Test</h1></body></html>';
        $content = PDFContent::fromHtml($html);

        $paperSizes = ['A4', 'A5', 'letter', 'legal'];

        foreach ($paperSizes as $size) {
            $outputPath = $this->tempDir . '/dompdf_' . strtolower($size) . '_' . uniqid() . '.pdf';

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
     */
    public function testCreatePdfWithSpecialCharacters(): void {
        if (!$this->writer->isAvailable()) {
            $this->markTestSkipped('Dompdf is not available');
        }

        $html = '<html><body><p>Sonderzeichen: äöü ÄÖÜ ß € © ® ™ α β γ</p></body></html>';
        $content = PDFContent::fromHtml($html);
        $outputPath = $this->tempDir . '/dompdf_special_' . uniqid() . '.pdf';

        try {
            $result = $this->writer->createPdf($content, $outputPath);

            $this->assertTrue($result);
            $this->assertFileExists($outputPath);
        } finally {
            @unlink($outputPath);
        }
    }
}
