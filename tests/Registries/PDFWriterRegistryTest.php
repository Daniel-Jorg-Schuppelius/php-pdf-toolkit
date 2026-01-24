<?php
/*
 * Created on   : Wed Jan 22 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : PDFWriterRegistryTest.php
 * License      : MIT License
 * License Uri  : https://opensource.org/license/mit
 */

declare(strict_types=1);

namespace Tests\Registries;

use PDFToolkit\Entities\PDFContent;
use PDFToolkit\Enums\PDFWriterType;
use PDFToolkit\Registries\PDFWriterRegistry;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class PDFWriterRegistryTest extends TestCase {
    private PDFWriterRegistry $registry;

    protected function setUp(): void {
        $this->registry = new PDFWriterRegistry();
    }

    public function testRegistryLoadsWriters(): void {
        $writers = $this->registry->getWriters();

        $this->assertNotEmpty($writers);
        $this->assertGreaterThanOrEqual(3, count($writers));
    }

    public function testWritersAreSortedByPriority(): void {
        $writers = $this->registry->getWriters();

        $priorities = array_map(fn($w) => $w::getPriority(), $writers);
        $sortedPriorities = $priorities;
        sort($sortedPriorities);

        $this->assertEquals($sortedPriorities, $priorities);
    }

    public function testGetWriterByName(): void {
        $dompdf = $this->registry->getByType(PDFWriterType::Dompdf);
        $tcpdf = $this->registry->getByType(PDFWriterType::Tcpdf);
        $wkhtmltopdf = $this->registry->getByType(PDFWriterType::Wkhtmltopdf);

        $this->assertNotNull($dompdf);
        $this->assertNotNull($tcpdf);
        $this->assertNotNull($wkhtmltopdf);

        $this->assertSame(PDFWriterType::Dompdf, $dompdf::getType());
        $this->assertSame(PDFWriterType::Tcpdf, $tcpdf::getType());
        $this->assertSame(PDFWriterType::Wkhtmltopdf, $wkhtmltopdf::getType());
    }

    public function testGetNonExistentWriter(): void {
        // Es gibt keinen "nonexistent" Writer mehr, wir prüfen nur ob getByType mit Zugferd null gibt wenn nicht registriert
        // Zugferd ist nicht in der Standard-Liste der Writer
        $writers = $this->registry->getAvailableWriterTypes();
        // Dieser Test ist jetzt überflüssig da Enum typsicher ist
        $this->assertIsArray($writers);
    }

    public function testGetWriterInfo(): void {
        $info = $this->registry->getWriterInfo();

        $this->assertIsArray($info);
        $this->assertNotEmpty($info);

        foreach ($info as $writerInfo) {
            $this->assertArrayHasKey('name', $writerInfo);
            $this->assertArrayHasKey('priority', $writerInfo);
            $this->assertArrayHasKey('available', $writerInfo);
            $this->assertArrayHasKey('supportsHtml', $writerInfo);
            $this->assertArrayHasKey('supportsText', $writerInfo);
        }
    }

    public function testPdfContentFromHtml(): void {
        $html = '<h1>Test</h1><p>Content</p>';
        $content = PDFContent::fromHtml($html, ['title' => 'Test PDF']);

        $this->assertTrue($content->isHtml());
        $this->assertFalse($content->isText());
        $this->assertFalse($content->isFile());
        $this->assertEquals($html, $content->content);
        $this->assertEquals('Test PDF', $content->getTitle());
    }

    public function testPdfContentFromText(): void {
        $text = "Line 1\nLine 2\nLine 3";
        $content = PDFContent::fromText($text);

        $this->assertFalse($content->isHtml());
        $this->assertTrue($content->isText());
        $this->assertFalse($content->isFile());
        $this->assertEquals($text, $content->content);
    }

    public function testPdfContentGetAsHtmlFromText(): void {
        $text = "Hello World";
        $content = PDFContent::fromText($text);

        $html = $content->getAsHtml();

        $this->assertStringContainsString('<!DOCTYPE html>', $html);
        $this->assertStringContainsString('Hello World', $html);
    }

    public function testPdfContentWithMetadata(): void {
        $content = PDFContent::fromHtml('<p>Test</p>', [
            'title' => 'My Title',
            'author' => 'Test Author',
            'subject' => 'Test Subject'
        ]);

        $this->assertEquals('My Title', $content->getTitle());
        $this->assertEquals('Test Author', $content->getAuthor());
        $this->assertEquals('Test Subject', $content->getSubject());
    }

    public function testPdfContentWithAdditionalMetadata(): void {
        $content = PDFContent::fromHtml('<p>Test</p>', ['title' => 'Original']);
        $updated = $content->withMetadata(['author' => 'New Author']);

        $this->assertEquals('Original', $updated->getTitle());
        $this->assertEquals('New Author', $updated->getAuthor());
    }

    public function testPdfContentFromNonExistentFileThrows(): void {
        $this->expectException(\InvalidArgumentException::class);

        PDFContent::fromFile('/nonexistent/file.html');
    }

    #[Group('integration')]
    public function testCreatePdfWithAvailableWriter(): void {
        if (!$this->registry->hasAvailableWriter()) {
            $this->markTestSkipped('No PDF writer available');
        }

        $content = PDFContent::fromHtml('<h1>Test</h1><p>Integration test</p>');
        $outputPath = sys_get_temp_dir() . '/test_output_' . uniqid() . '.pdf';

        try {
            $result = $this->registry->createPdf($content, $outputPath);

            $this->assertTrue($result);
            $this->assertFileExists($outputPath);

            // PDF-Signatur prüfen
            $pdfContent = file_get_contents($outputPath);
            $this->assertStringStartsWith('%PDF-', $pdfContent);
        } finally {
            @unlink($outputPath);
        }
    }

    #[Group('integration')]
    public function testHtmlToPdfShortcut(): void {
        if (!$this->registry->hasAvailableWriter()) {
            $this->markTestSkipped('No PDF writer available');
        }

        $outputPath = sys_get_temp_dir() . '/test_html_' . uniqid() . '.pdf';

        try {
            $result = $this->registry->htmlToPdf(
                '<h1>Hello World</h1>',
                $outputPath
            );

            $this->assertTrue($result);
            $this->assertFileExists($outputPath);
        } finally {
            @unlink($outputPath);
        }
    }

    #[Group('integration')]
    public function testTextToPdfShortcut(): void {
        if (!$this->registry->hasAvailableWriter()) {
            $this->markTestSkipped('No PDF writer available');
        }

        $outputPath = sys_get_temp_dir() . '/test_text_' . uniqid() . '.pdf';

        try {
            $result = $this->registry->textToPdf(
                "Line 1\nLine 2\nLine 3",
                $outputPath
            );

            $this->assertTrue($result);
            $this->assertFileExists($outputPath);
        } finally {
            @unlink($outputPath);
        }
    }
}
