<?php
/*
 * Created on   : Wed Jan 22 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : TcpdfWriterTest.php
 * License      : MIT License
 * License Uri  : https://opensource.org/license/mit
 */

declare(strict_types=1);

namespace Tests\Writers;

use PDFToolkit\Entities\PDFContent;
use PDFToolkit\Enums\PDFWriterType;
use PDFToolkit\Writers\TcpdfWriter;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class TcpdfWriterTest extends TestCase {
    private TcpdfWriter $writer;
    private string $tempDir;

    protected function setUp(): void {
        $this->writer = new TcpdfWriter();
        $this->tempDir = sys_get_temp_dir();
    }

    public function testGetName(): void {
        $this->assertSame(PDFWriterType::Tcpdf, TcpdfWriter::getType());
    }

    public function testGetPriority(): void {
        $this->assertEquals(20, TcpdfWriter::getPriority());
    }

    public function testSupportsHtml(): void {
        $this->assertTrue(TcpdfWriter::supportsHtml());
    }

    public function testSupportsText(): void {
        $this->assertTrue(TcpdfWriter::supportsText());
    }

    public function testIsAvailableReturnsBool(): void {
        // TCPDF ist optional, also prüfen wir nur den Rückgabetyp
        $this->assertIsBool($this->writer->isAvailable());
    }

    public function testCanHandleReturnsCorrectValue(): void {
        $content = PDFContent::fromHtml('<p>Test</p>');

        // Wenn TCPDF verfügbar ist, sollte canHandle true sein
        if ($this->writer->isAvailable()) {
            $this->assertTrue($this->writer->canHandle($content));
        } else {
            $this->assertFalse($this->writer->canHandle($content));
        }
    }

    #[Group('integration')]
    public function testCreatePdfFromHtml(): void {
        if (!$this->writer->isAvailable()) {
            $this->markTestSkipped('TCPDF is not available');
        }

        $html = '<html><body><h1>TCPDF Test</h1><p>Hello World</p></body></html>';
        $content = PDFContent::fromHtml($html);
        $outputPath = $this->tempDir . '/tcpdf_test_' . uniqid() . '.pdf';

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

    #[Group('integration')]
    public function testCreatePdfFromText(): void {
        if (!$this->writer->isAvailable()) {
            $this->markTestSkipped('TCPDF is not available');
        }

        $text = "TCPDF Text Test\n\nZeile 1\nZeile 2\nZeile 3";
        $content = PDFContent::fromText($text);
        $outputPath = $this->tempDir . '/tcpdf_text_' . uniqid() . '.pdf';

        try {
            $result = $this->writer->createPdf($content, $outputPath);

            $this->assertTrue($result);
            $this->assertFileExists($outputPath);
        } finally {
            @unlink($outputPath);
        }
    }

    #[Group('integration')]
    public function testCreatePdfWithMetadata(): void {
        if (!$this->writer->isAvailable()) {
            $this->markTestSkipped('TCPDF is not available');
        }

        $html = '<html><body><h1>Metadata Test</h1></body></html>';
        $content = PDFContent::fromHtml($html, [
            'title' => 'TCPDF Titel',
            'author' => 'TCPDF Autor',
            'subject' => 'TCPDF Betreff'
        ]);
        $outputPath = $this->tempDir . '/tcpdf_meta_' . uniqid() . '.pdf';

        try {
            $result = $this->writer->createPdf($content, $outputPath);

            $this->assertTrue($result);
            $this->assertFileExists($outputPath);
        } finally {
            @unlink($outputPath);
        }
    }

    #[Group('integration')]
    public function testCreatePdfWithCss(): void {
        if (!$this->writer->isAvailable()) {
            $this->markTestSkipped('TCPDF is not available');
        }

        // TCPDF hat eingeschränkte CSS-Unterstützung
        $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <style>
        body { font-family: helvetica; font-size: 12pt; }
        h1 { color: #0000FF; }
        table { border: 1px solid black; }
        td { padding: 5px; }
    </style>
</head>
<body>
    <h1>TCPDF CSS Test</h1>
    <table>
        <tr><td>Zelle 1</td><td>Zelle 2</td></tr>
    </table>
</body>
</html>
HTML;

        $content = PDFContent::fromHtml($html);
        $outputPath = $this->tempDir . '/tcpdf_css_' . uniqid() . '.pdf';

        try {
            $result = $this->writer->createPdf($content, $outputPath);

            $this->assertTrue($result);
            $this->assertFileExists($outputPath);
        } finally {
            @unlink($outputPath);
        }
    }

    #[Group('integration')]
    public function testCreatePdfWithLandscapeOrientation(): void {
        if (!$this->writer->isAvailable()) {
            $this->markTestSkipped('TCPDF is not available');
        }

        $html = '<html><body><h1>Landscape Test</h1></body></html>';
        $content = PDFContent::fromHtml($html);
        $outputPath = $this->tempDir . '/tcpdf_landscape_' . uniqid() . '.pdf';

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

    #[Group('integration')]
    public function testCreatePdfString(): void {
        if (!$this->writer->isAvailable()) {
            $this->markTestSkipped('TCPDF is not available');
        }

        $html = '<html><body><p>String Test</p></body></html>';
        $content = PDFContent::fromHtml($html);

        $pdfString = $this->writer->createPdfString($content);

        $this->assertNotNull($pdfString);
        $this->assertStringStartsWith('%PDF-', $pdfString);
        $this->assertGreaterThan(100, strlen($pdfString));
    }

    #[Group('integration')]
    public function testCreatePdfWithDifferentPaperSizes(): void {
        if (!$this->writer->isAvailable()) {
            $this->markTestSkipped('TCPDF is not available');
        }

        $html = '<html><body><h1>Paper Size Test</h1></body></html>';
        $content = PDFContent::fromHtml($html);

        $paperSizes = ['A4', 'A5', 'LETTER', 'LEGAL'];

        foreach ($paperSizes as $size) {
            $outputPath = $this->tempDir . '/tcpdf_' . strtolower($size) . '_' . uniqid() . '.pdf';

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

    #[Group('integration')]
    public function testCreatePdfWithSpecialCharacters(): void {
        if (!$this->writer->isAvailable()) {
            $this->markTestSkipped('TCPDF is not available');
        }

        $html = '<html><body><p>Sonderzeichen: äöü ÄÖÜ ß € © ®</p></body></html>';
        $content = PDFContent::fromHtml($html);
        $outputPath = $this->tempDir . '/tcpdf_special_' . uniqid() . '.pdf';

        try {
            $result = $this->writer->createPdf($content, $outputPath);

            $this->assertTrue($result);
            $this->assertFileExists($outputPath);
        } finally {
            @unlink($outputPath);
        }
    }

    #[Group('integration')]
    public function testCreatePdfWithMargins(): void {
        if (!$this->writer->isAvailable()) {
            $this->markTestSkipped('TCPDF is not available');
        }

        $html = '<html><body><h1>Margin Test</h1><p>Content with custom margins</p></body></html>';
        $content = PDFContent::fromHtml($html);
        $outputPath = $this->tempDir . '/tcpdf_margins_' . uniqid() . '.pdf';

        try {
            $result = $this->writer->createPdf($content, $outputPath, [
                'margins' => [
                    'top' => 30,
                    'bottom' => 30,
                    'left' => 20,
                    'right' => 20
                ]
            ]);

            $this->assertTrue($result);
            $this->assertFileExists($outputPath);
        } finally {
            @unlink($outputPath);
        }
    }
}
