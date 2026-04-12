<?php
/*
 * Created on   : Sat Apr 12 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : PDFTextProviderTest.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace Tests\Helper;

use PDFToolkit\Entities\PDFDocument;
use PDFToolkit\Enums\PDFReaderType;
use PDFToolkit\Enums\PDFTextVariant;
use PDFToolkit\Helper\PDFTextProvider;
use InvalidArgumentException;
use Tests\Contracts\BaseTestCase;

final class PDFTextProviderTest extends BaseTestCase {
    private const SAMPLE_PDF = __DIR__ . '/../../.samples/PDF/test-text.pdf';

    // ========================================================================
    // Konstruktor & Initialisierung
    // ========================================================================

    public function testInitialTextIsCachedAsDefault(): void {
        $provider = new PDFTextProvider(self::SAMPLE_PDF, 'Initialer Text');

        $this->assertEquals('Initialer Text', $provider->text());
        $this->assertTrue($provider->isCached(PDFTextVariant::Default));
    }

    public function testEmptyStringInitialTextIsNormalized(): void {
        $provider = new PDFTextProvider(self::SAMPLE_PDF, '');

        $this->assertFalse($provider->isCached(PDFTextVariant::Default));
    }

    public function testWhitespaceOnlyInitialTextIsNormalized(): void {
        $provider = new PDFTextProvider(self::SAMPLE_PDF, '   ');

        $this->assertFalse($provider->isCached(PDFTextVariant::Default));
    }

    public function testNullInitialResultHasNoCachedDefault(): void {
        $provider = new PDFTextProvider(self::SAMPLE_PDF);

        $this->assertFalse($provider->isCached(PDFTextVariant::Default));
    }

    public function testPDFDocumentInitialResultCachesTextAndDocument(): void {
        $doc = new PDFDocument(
            text: 'Text aus PDFDocument',
            reader: PDFReaderType::Pdftotext,
            isScanned: false,
            sourcePath: self::SAMPLE_PDF,
        );

        $provider = new PDFTextProvider(self::SAMPLE_PDF, $doc);

        $this->assertTrue($provider->isCached(PDFTextVariant::Default));
        $this->assertEquals('Text aus PDFDocument', $provider->text());
        // usedReader() sollte NICHT neu extrahieren, sondern das gecachte Document verwenden
        $this->assertEquals(PDFReaderType::Pdftotext, $provider->usedReader());
        $this->assertFalse($provider->isScanned());
    }

    public function testPDFDocumentWithEmptyTextIsNotCached(): void {
        $doc = new PDFDocument(
            text: null,
            reader: null,
            isScanned: false,
            sourcePath: self::SAMPLE_PDF,
        );

        $provider = new PDFTextProvider(self::SAMPLE_PDF, $doc);

        $this->assertFalse($provider->isCached(PDFTextVariant::Default));
    }

    public function testPDFDocumentWithBestResultUsesIt(): void {
        $doc = new PDFDocument(
            text: 'Kurzer Text',
            reader: PDFReaderType::Pdftotext,
            isScanned: false,
            sourcePath: self::SAMPLE_PDF,
            alternatives: [
                PDFReaderType::Pdfbox->value => [
                    'text' => 'Ein viel längerer und besserer Text mit mehr Inhalt',
                    'isScanned' => false,
                ],
            ],
        );

        $provider = new PDFTextProvider(self::SAMPLE_PDF, $doc);

        // getBestResult() liefert den längeren Text (Pdfbox-Alternative)
        $this->assertEquals(
            'Ein viel längerer und besserer Text mit mehr Inhalt',
            $provider->text()
        );
    }

    // ========================================================================
    // Pfad & Grundfunktionen
    // ========================================================================

    public function testGetPathReturnsConstructorPath(): void {
        $provider = new PDFTextProvider(self::SAMPLE_PDF);
        $this->assertEquals(self::SAMPLE_PDF, $provider->getPath());
    }

    // ========================================================================
    // Cache-Verwaltung
    // ========================================================================

    public function testClearCacheResetsAll(): void {
        $provider = new PDFTextProvider(self::SAMPLE_PDF, 'Cached Text');

        $this->assertTrue($provider->isCached(PDFTextVariant::Default));

        $provider->clearCache();

        $this->assertFalse($provider->isCached(PDFTextVariant::Default));
    }

    public function testIsCachedWithVariantEnum(): void {
        $provider = new PDFTextProvider(self::SAMPLE_PDF, 'Text');

        $this->assertTrue($provider->isCached(PDFTextVariant::Default));
        $this->assertFalse($provider->isCached(PDFTextVariant::Layout));
        $this->assertFalse($provider->isCached(PDFTextVariant::Raw));
        $this->assertFalse($provider->isCached(PDFTextVariant::TextOnly));
    }

    public function testIsCachedAcceptsStringFallback(): void {
        $provider = new PDFTextProvider(self::SAMPLE_PDF, 'Text');

        // String-Keys für dynamische Varianten (OCR, Quality) weiterhin möglich
        $this->assertFalse($provider->isCached('ocr:deu+eng'));
        $this->assertTrue($provider->isCached('default'));
    }

    // ========================================================================
    // Varianten-Konstanten
    // ========================================================================

    public function testVariantEnumHasExpectedValues(): void {
        $this->assertEquals('default', PDFTextVariant::Default->value);
        $this->assertEquals('layout', PDFTextVariant::Layout->value);
        $this->assertEquals('raw', PDFTextVariant::Raw->value);
        $this->assertEquals('textonly', PDFTextVariant::TextOnly->value);
    }

    // ========================================================================
    // hasText()
    // ========================================================================

    public function testHasTextReturnsTrueWithInitialText(): void {
        $provider = new PDFTextProvider(self::SAMPLE_PDF, 'Vorhandener Text');
        $this->assertTrue($provider->hasText());
    }

    public function testHasTextReturnsFalseWithoutInitialText(): void {
        $provider = new PDFTextProvider(self::SAMPLE_PDF);
        $this->assertFalse($provider->hasText());
    }

    public function testHasTextReturnsFalseAfterClearCache(): void {
        $provider = new PDFTextProvider(self::SAMPLE_PDF, 'Text');
        $this->assertTrue($provider->hasText());

        $provider->clearCache();
        $this->assertFalse($provider->hasText());
    }

    // ========================================================================
    // textWithFallback()
    // ========================================================================

    public function testTextWithFallbackReturnsFirstNonNull(): void {
        $provider = new PDFTextProvider(self::SAMPLE_PDF, 'Default Text');

        $result = $provider->textWithFallback(
            fn(PDFTextProvider $p) => null,             // erste Variante liefert null
            fn(PDFTextProvider $p) => $p->text(),       // zweite liefert Default-Text
            fn(PDFTextProvider $p) => 'Dritte Variante', // wird nicht aufgerufen
        );

        $this->assertEquals('Default Text', $result);
    }

    public function testTextWithFallbackReturnsNullWhenAllEmpty(): void {
        $provider = new PDFTextProvider(self::SAMPLE_PDF);

        $result = $provider->textWithFallback(
            fn(PDFTextProvider $p) => null,
            fn(PDFTextProvider $p) => '',
            fn(PDFTextProvider $p) => '   ',
        );

        $this->assertNull($result);
    }

    public function testTextWithFallbackSkipsEmptyStrings(): void {
        $provider = new PDFTextProvider(self::SAMPLE_PDF);

        $result = $provider->textWithFallback(
            fn(PDFTextProvider $p) => '',               // leer
            fn(PDFTextProvider $p) => 'Treffer',        // nicht-leer
        );

        $this->assertEquals('Treffer', $result);
    }

    // ========================================================================
    // cachedVariants()
    // ========================================================================

    public function testCachedVariantsEmptyWithoutInitialText(): void {
        $provider = new PDFTextProvider(self::SAMPLE_PDF);
        $this->assertEmpty($provider->cachedVariants());
    }

    public function testCachedVariantsContainsDefaultWithInitialText(): void {
        $provider = new PDFTextProvider(self::SAMPLE_PDF, 'Text');
        $this->assertEquals(['default'], $provider->cachedVariants());
    }

    public function testCachedVariantsEmptyAfterClearCache(): void {
        $provider = new PDFTextProvider(self::SAMPLE_PDF, 'Text');
        $provider->clearCache();
        $this->assertEmpty($provider->cachedVariants());
    }

    // ========================================================================
    // textLength() / lineCount()
    // ========================================================================

    public function testTextLengthReturnsCorrectByteCount(): void {
        $provider = new PDFTextProvider(self::SAMPLE_PDF, 'Hallo Welt');
        $this->assertEquals(10, $provider->textLength());
    }

    public function testTextLengthReturnsNullForUncachedVariant(): void {
        $provider = new PDFTextProvider(self::SAMPLE_PDF, 'Text');
        $this->assertNull($provider->textLength(PDFTextVariant::Layout));
    }

    public function testTextLengthReturnsNullWithoutInitialText(): void {
        $provider = new PDFTextProvider(self::SAMPLE_PDF);
        $this->assertNull($provider->textLength());
    }

    public function testLineCountReturnsSingleLineForNoNewlines(): void {
        $provider = new PDFTextProvider(self::SAMPLE_PDF, 'Eine Zeile');
        $this->assertEquals(1, $provider->lineCount());
    }

    public function testLineCountReturnsCorrectCountForMultipleLines(): void {
        $provider = new PDFTextProvider(self::SAMPLE_PDF, "Zeile 1\nZeile 2\nZeile 3");
        $this->assertEquals(3, $provider->lineCount());
    }

    public function testLineCountReturnsNullForUncachedVariant(): void {
        $provider = new PDFTextProvider(self::SAMPLE_PDF, 'Text');
        $this->assertNull($provider->lineCount(PDFTextVariant::Raw));
    }

    public function testTextLengthWithUmlautsCountsCharacters(): void {
        $provider = new PDFTextProvider(self::SAMPLE_PDF, 'Ü');
        // mb_strlen: Ü = 1 Zeichen (nicht 2 Bytes)
        $this->assertEquals(1, $provider->textLength());
    }

    public function testTextLengthConsistentWithPDFDocument(): void {
        $text = 'Ärger mit Ü und ö';
        $doc = new PDFDocument(
            text: $text,
            reader: PDFReaderType::Pdftotext,
            isScanned: false,
            sourcePath: self::SAMPLE_PDF,
        );

        $provider = new PDFTextProvider(self::SAMPLE_PDF, $doc);

        // Konsistent mit PDFDocument::getTextLength() (beide mb_strlen)
        $this->assertEquals($doc->getTextLength(), $provider->textLength());
    }

    // ========================================================================
    // Konstruktor-Validierung
    // ========================================================================

    public function testConstructorThrowsForNonExistentFile(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('existiert nicht');

        new PDFTextProvider('/tmp/this-file-does-not-exist-12345.pdf');
    }

    public function testConstructorThrowsForNonPdfFile(): void {
        $tmpFile = tempnam(sys_get_temp_dir(), 'test_');
        file_put_contents($tmpFile, 'Das ist kein PDF');

        try {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('Keine gültige PDF-Datei');

            new PDFTextProvider($tmpFile);
        } finally {
            @unlink($tmpFile);
        }
    }
}
