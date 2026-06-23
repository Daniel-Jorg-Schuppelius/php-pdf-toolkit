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

use InvalidArgumentException;
use PDFToolkit\Entities\PDFDocument;
use PDFToolkit\Enums\{PDFReaderType, PDFTextVariant};
use PDFToolkit\Helper\PDFTextProvider;
use Tests\Contracts\BaseTestCase;

final class PDFTextProviderTest extends BaseTestCase {
    private const SAMPLE_PDF = __DIR__ . '/../../.samples/PDF/test-text.pdf';

    // ========================================================================
    // Konstruktor & Initialisierung
    // ========================================================================

    public function test_initial_text_is_cached_as_default(): void {
        $provider = new PDFTextProvider(self::SAMPLE_PDF, 'Initialer Text');

        $this->assertEquals('Initialer Text', $provider->text());
        $this->assertTrue($provider->isCached(PDFTextVariant::Default));
    }

    public function test_empty_string_initial_text_is_normalized(): void {
        $provider = new PDFTextProvider(self::SAMPLE_PDF, '');

        $this->assertFalse($provider->isCached(PDFTextVariant::Default));
    }

    public function test_whitespace_only_initial_text_is_normalized(): void {
        $provider = new PDFTextProvider(self::SAMPLE_PDF, '   ');

        $this->assertFalse($provider->isCached(PDFTextVariant::Default));
    }

    public function test_null_initial_result_has_no_cached_default(): void {
        $provider = new PDFTextProvider(self::SAMPLE_PDF);

        $this->assertFalse($provider->isCached(PDFTextVariant::Default));
    }

    public function test_pdf_document_initial_result_caches_text_and_document(): void {
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

    public function test_pdf_document_with_empty_text_is_not_cached(): void {
        $doc = new PDFDocument(
            text: null,
            reader: null,
            isScanned: false,
            sourcePath: self::SAMPLE_PDF,
        );

        $provider = new PDFTextProvider(self::SAMPLE_PDF, $doc);

        $this->assertFalse($provider->isCached(PDFTextVariant::Default));
    }

    public function test_pdf_document_with_best_result_uses_it(): void {
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

    public function test_get_path_returns_constructor_path(): void {
        $provider = new PDFTextProvider(self::SAMPLE_PDF);
        $this->assertEquals(self::SAMPLE_PDF, $provider->getPath());
    }

    // ========================================================================
    // Cache-Verwaltung
    // ========================================================================

    public function test_clear_cache_resets_all(): void {
        $provider = new PDFTextProvider(self::SAMPLE_PDF, 'Cached Text');

        $this->assertTrue($provider->isCached(PDFTextVariant::Default));

        $provider->clearCache();

        $this->assertFalse($provider->isCached(PDFTextVariant::Default));
    }

    public function test_is_cached_with_variant_enum(): void {
        $provider = new PDFTextProvider(self::SAMPLE_PDF, 'Text');

        $this->assertTrue($provider->isCached(PDFTextVariant::Default));
        $this->assertFalse($provider->isCached(PDFTextVariant::Layout));
        $this->assertFalse($provider->isCached(PDFTextVariant::Raw));
        $this->assertFalse($provider->isCached(PDFTextVariant::TextOnly));
    }

    public function test_is_cached_accepts_string_fallback(): void {
        $provider = new PDFTextProvider(self::SAMPLE_PDF, 'Text');

        // String-Keys für dynamische Varianten (OCR, Quality) weiterhin möglich
        $this->assertFalse($provider->isCached('ocr:deu+eng'));
        $this->assertTrue($provider->isCached('default'));
    }

    // ========================================================================
    // Varianten-Konstanten
    // ========================================================================

    public function test_variant_enum_has_expected_values(): void {
        $this->assertEquals('default', PDFTextVariant::Default->value);
        $this->assertEquals('layout', PDFTextVariant::Layout->value);
        $this->assertEquals('raw', PDFTextVariant::Raw->value);
        $this->assertEquals('textonly', PDFTextVariant::TextOnly->value);
    }

    // ========================================================================
    // hasText()
    // ========================================================================

    public function test_has_text_returns_true_with_initial_text(): void {
        $provider = new PDFTextProvider(self::SAMPLE_PDF, 'Vorhandener Text');
        $this->assertTrue($provider->hasText());
    }

    public function test_has_text_returns_false_without_initial_text(): void {
        $provider = new PDFTextProvider(self::SAMPLE_PDF);
        $this->assertFalse($provider->hasText());
    }

    public function test_has_text_returns_false_after_clear_cache(): void {
        $provider = new PDFTextProvider(self::SAMPLE_PDF, 'Text');
        $this->assertTrue($provider->hasText());

        $provider->clearCache();
        $this->assertFalse($provider->hasText());
    }

    // ========================================================================
    // textWithFallback()
    // ========================================================================

    public function test_text_with_fallback_returns_first_non_null(): void {
        $provider = new PDFTextProvider(self::SAMPLE_PDF, 'Default Text');

        $result = $provider->textWithFallback(
            fn (PDFTextProvider $p) => null,             // erste Variante liefert null
            fn (PDFTextProvider $p) => $p->text(),       // zweite liefert Default-Text
            fn (PDFTextProvider $p) => 'Dritte Variante', // wird nicht aufgerufen
        );

        $this->assertEquals('Default Text', $result);
    }

    public function test_text_with_fallback_returns_null_when_all_empty(): void {
        $provider = new PDFTextProvider(self::SAMPLE_PDF);

        $result = $provider->textWithFallback(
            fn (PDFTextProvider $p) => null,
            fn (PDFTextProvider $p) => '',
            fn (PDFTextProvider $p) => '   ',
        );

        $this->assertNull($result);
    }

    public function test_text_with_fallback_skips_empty_strings(): void {
        $provider = new PDFTextProvider(self::SAMPLE_PDF);

        $result = $provider->textWithFallback(
            fn (PDFTextProvider $p) => '',               // leer
            fn (PDFTextProvider $p) => 'Treffer',        // nicht-leer
        );

        $this->assertEquals('Treffer', $result);
    }

    // ========================================================================
    // cachedVariants()
    // ========================================================================

    public function test_cached_variants_empty_without_initial_text(): void {
        $provider = new PDFTextProvider(self::SAMPLE_PDF);
        $this->assertEmpty($provider->cachedVariants());
    }

    public function test_cached_variants_contains_default_with_initial_text(): void {
        $provider = new PDFTextProvider(self::SAMPLE_PDF, 'Text');
        $this->assertEquals(['default'], $provider->cachedVariants());
    }

    public function test_cached_variants_empty_after_clear_cache(): void {
        $provider = new PDFTextProvider(self::SAMPLE_PDF, 'Text');
        $provider->clearCache();
        $this->assertEmpty($provider->cachedVariants());
    }

    // ========================================================================
    // textLength() / lineCount()
    // ========================================================================

    public function test_text_length_returns_correct_byte_count(): void {
        $provider = new PDFTextProvider(self::SAMPLE_PDF, 'Hallo Welt');
        $this->assertEquals(10, $provider->textLength());
    }

    public function test_text_length_returns_null_for_uncached_variant(): void {
        $provider = new PDFTextProvider(self::SAMPLE_PDF, 'Text');
        $this->assertNull($provider->textLength(PDFTextVariant::Layout));
    }

    public function test_text_length_returns_null_without_initial_text(): void {
        $provider = new PDFTextProvider(self::SAMPLE_PDF);
        $this->assertNull($provider->textLength());
    }

    public function test_line_count_returns_single_line_for_no_newlines(): void {
        $provider = new PDFTextProvider(self::SAMPLE_PDF, 'Eine Zeile');
        $this->assertEquals(1, $provider->lineCount());
    }

    public function test_line_count_returns_correct_count_for_multiple_lines(): void {
        $provider = new PDFTextProvider(self::SAMPLE_PDF, "Zeile 1\nZeile 2\nZeile 3");
        $this->assertEquals(3, $provider->lineCount());
    }

    public function test_line_count_returns_null_for_uncached_variant(): void {
        $provider = new PDFTextProvider(self::SAMPLE_PDF, 'Text');
        $this->assertNull($provider->lineCount(PDFTextVariant::Raw));
    }

    public function test_text_length_with_umlauts_counts_characters(): void {
        $provider = new PDFTextProvider(self::SAMPLE_PDF, 'Ü');
        // mb_strlen: Ü = 1 Zeichen (nicht 2 Bytes)
        $this->assertEquals(1, $provider->textLength());
    }

    public function test_text_length_consistent_with_pdf_document(): void {
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

    public function test_constructor_throws_for_non_existent_file(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('existiert nicht');

        new PDFTextProvider('/tmp/this-file-does-not-exist-12345.pdf');
    }

    public function test_constructor_throws_for_non_pdf_file(): void {
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
