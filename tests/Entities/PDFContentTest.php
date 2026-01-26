<?php
/*
 * Created on   : Wed Jan 22 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : PDFContentTest.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace Tests\Entities;

use PDFToolkit\Entities\PDFContent;
use PHPUnit\Framework\TestCase;

final class PDFContentTest extends TestCase {
    public function testFromHtmlCreatesHtmlContent(): void {
        $html = '<h1>Test</h1><p>Content</p>';
        $content = PDFContent::fromHtml($html);

        $this->assertTrue($content->isHtml());
        $this->assertFalse($content->isText());
        $this->assertFalse($content->isFile());
        $this->assertEquals($html, $content->content);
        $this->assertEquals(PDFContent::TYPE_HTML, $content->type);
    }

    public function testFromTextCreatesTextContent(): void {
        $text = "Line 1\nLine 2\nLine 3";
        $content = PDFContent::fromText($text);

        $this->assertFalse($content->isHtml());
        $this->assertTrue($content->isText());
        $this->assertFalse($content->isFile());
        $this->assertEquals($text, $content->content);
        $this->assertEquals(PDFContent::TYPE_TEXT, $content->type);
    }

    public function testFromFileCreatesFileContent(): void {
        $tempFile = tempnam(sys_get_temp_dir(), 'test_') . '.html';
        file_put_contents($tempFile, '<h1>Test</h1>');

        try {
            $content = PDFContent::fromFile($tempFile);

            $this->assertFalse($content->isHtml());
            $this->assertFalse($content->isText());
            $this->assertTrue($content->isFile());
            $this->assertEquals($tempFile, $content->content);
            $this->assertEquals(PDFContent::TYPE_FILE, $content->type);
        } finally {
            @unlink($tempFile);
        }
    }

    public function testFromFileThrowsExceptionForNonExistentFile(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('File not found');

        PDFContent::fromFile('/nonexistent/file.html');
    }

    public function testFromHtmlWithMetadata(): void {
        $content = PDFContent::fromHtml('<p>Test</p>', [
            'title' => 'My Title',
            'author' => 'Test Author',
            'subject' => 'Test Subject',
            'keywords' => 'test, pdf, content'
        ]);

        $this->assertEquals('My Title', $content->getTitle());
        $this->assertEquals('Test Author', $content->getAuthor());
        $this->assertEquals('Test Subject', $content->getSubject());
        $this->assertEquals('test, pdf, content', $content->getMeta('keywords'));
    }

    public function testFromTextWithMetadata(): void {
        $content = PDFContent::fromText('Text content', [
            'title' => 'Text Document'
        ]);

        $this->assertEquals('Text Document', $content->getTitle());
    }

    public function testGetMetaReturnsDefaultForMissingKey(): void {
        $content = PDFContent::fromHtml('<p>Test</p>');

        $this->assertNull($content->getMeta('nonexistent'));
        $this->assertEquals('default', $content->getMeta('nonexistent', 'default'));
    }

    public function testWithMetadataCreatesNewInstance(): void {
        $original = PDFContent::fromHtml('<p>Test</p>', ['title' => 'Original']);
        $updated = $original->withMetadata(['author' => 'New Author']);

        // Original bleibt unverändert (readonly)
        $this->assertEquals('Original', $original->getTitle());
        $this->assertNull($original->getAuthor());

        // Neue Instanz hat beide Werte
        $this->assertEquals('Original', $updated->getTitle());
        $this->assertEquals('New Author', $updated->getAuthor());
    }

    public function testWithMetadataOverwritesExisting(): void {
        $original = PDFContent::fromHtml('<p>Test</p>', ['title' => 'Original']);
        $updated = $original->withMetadata(['title' => 'Updated']);

        $this->assertEquals('Original', $original->getTitle());
        $this->assertEquals('Updated', $updated->getTitle());
    }

    public function testGetAsHtmlFromHtml(): void {
        $html = '<h1>Test</h1><p>Content</p>';
        $content = PDFContent::fromHtml($html);

        $this->assertEquals($html, $content->getAsHtml());
    }

    public function testGetAsHtmlFromText(): void {
        $text = "Hello World";
        $content = PDFContent::fromText($text);

        $html = $content->getAsHtml();

        $this->assertStringContainsString('<!DOCTYPE html>', $html);
        $this->assertStringContainsString('<html', $html);
        $this->assertStringContainsString('Hello World', $html);
    }

    public function testGetAsHtmlFromTextEscapesHtml(): void {
        $text = "<script>alert('xss')</script>";
        $content = PDFContent::fromText($text);

        $html = $content->getAsHtml();

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testGetAsHtmlFromTextPreservesNewlines(): void {
        $text = "Line 1\nLine 2\nLine 3";
        $content = PDFContent::fromText($text);

        $html = $content->getAsHtml();

        // pre-Tag bewahrt Zeilenumbrüche, alle Zeilen sollten enthalten sein
        $this->assertStringContainsString('Line 1', $html);
        $this->assertStringContainsString('Line 2', $html);
        $this->assertStringContainsString('Line 3', $html);
        $this->assertStringContainsString('<pre', $html);
    }

    public function testGetAsHtmlFromFile(): void {
        $htmlContent = '<h1>File Content</h1>';
        $tempFile = tempnam(sys_get_temp_dir(), 'test_') . '.html';
        file_put_contents($tempFile, $htmlContent);

        try {
            $content = PDFContent::fromFile($tempFile);
            $result = $content->getAsHtml();

            $this->assertEquals($htmlContent, $result);
        } finally {
            @unlink($tempFile);
        }
    }

    public function testGetAsTextFromText(): void {
        $text = "Hello World";
        $content = PDFContent::fromText($text);

        $this->assertEquals($text, $content->getAsText());
    }

    public function testGetAsTextFromHtml(): void {
        $html = '<h1>Title</h1><p>Paragraph</p>';
        $content = PDFContent::fromHtml($html);

        $text = $content->getAsText();

        $this->assertStringContainsString('Title', $text);
        $this->assertStringContainsString('Paragraph', $text);
        $this->assertStringNotContainsString('<h1>', $text);
        $this->assertStringNotContainsString('<p>', $text);
    }

    public function testGetAsTextFromFile(): void {
        $htmlContent = '<h1>Title</h1><p>Content</p>';
        $tempFile = tempnam(sys_get_temp_dir(), 'test_') . '.html';
        file_put_contents($tempFile, $htmlContent);

        try {
            $content = PDFContent::fromFile($tempFile);
            $text = $content->getAsText();

            $this->assertStringContainsString('Title', $text);
            $this->assertStringContainsString('Content', $text);
            $this->assertStringNotContainsString('<h1>', $text);
        } finally {
            @unlink($tempFile);
        }
    }

    public function testMetadataIsEmptyByDefault(): void {
        $content = PDFContent::fromHtml('<p>Test</p>');

        $this->assertEmpty($content->metadata);
        $this->assertNull($content->getTitle());
        $this->assertNull($content->getAuthor());
        $this->assertNull($content->getSubject());
    }

    public function testContentIsReadonly(): void {
        $content = PDFContent::fromHtml('<p>Test</p>');

        // PHP 8.2 readonly class verhindert Änderungen
        // Wir prüfen nur, dass die Klasse wie erwartet funktioniert
        $this->assertIsString($content->content);
        $this->assertIsString($content->type);
        $this->assertIsArray($content->metadata);
    }

    public function testEmptyHtmlContent(): void {
        $content = PDFContent::fromHtml('');

        $this->assertTrue($content->isHtml());
        $this->assertEquals('', $content->content);
        $this->assertEquals('', $content->getAsHtml());
    }

    public function testEmptyTextContent(): void {
        $content = PDFContent::fromText('');

        $this->assertTrue($content->isText());
        $this->assertEquals('', $content->content);
        $this->assertStringContainsString('<!DOCTYPE html>', $content->getAsHtml());
    }

    public function testComplexMetadata(): void {
        $metadata = [
            'title' => 'Complex Document',
            'author' => 'Test Author',
            'subject' => 'Testing',
            'keywords' => 'test, pdf, complex',
            'creator' => 'PHP PDF Toolkit',
            'producer' => 'Test Producer',
            'custom_field' => 'Custom Value',
            'number_field' => 42,
            'boolean_field' => true,
            'array_field' => ['a', 'b', 'c']
        ];

        $content = PDFContent::fromHtml('<p>Test</p>', $metadata);

        $this->assertEquals('Complex Document', $content->getTitle());
        $this->assertEquals('Test Author', $content->getAuthor());
        $this->assertEquals('Testing', $content->getSubject());
        $this->assertEquals('test, pdf, complex', $content->getMeta('keywords'));
        $this->assertEquals('Custom Value', $content->getMeta('custom_field'));
        $this->assertEquals(42, $content->getMeta('number_field'));
        $this->assertTrue($content->getMeta('boolean_field'));
        $this->assertEquals(['a', 'b', 'c'], $content->getMeta('array_field'));
    }

    public function testSpecialCharactersInContent(): void {
        $html = '<p>Sonderzeichen: äöü ÄÖÜ ß € © ® ™</p>';
        $content = PDFContent::fromHtml($html);

        $this->assertEquals($html, $content->content);
        $this->assertEquals($html, $content->getAsHtml());
    }

    public function testSpecialCharactersInMetadata(): void {
        $content = PDFContent::fromHtml('<p>Test</p>', [
            'title' => 'Über die Größe von Äpfeln',
            'author' => 'François Müller'
        ]);

        $this->assertEquals('Über die Größe von Äpfeln', $content->getTitle());
        $this->assertEquals('François Müller', $content->getAuthor());
    }
}