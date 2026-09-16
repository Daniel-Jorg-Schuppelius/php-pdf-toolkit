<?php
/*
 * Created on   : Wed Sep 16 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : PDFToTextReaderLayoutTest.php
 * License      : MIT License
 * License Uri  : https://opensource.org/license/mit
 */

declare(strict_types=1);

namespace Tests\Readers;

use PDFToolkit\Readers\PDFToTextReader;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Eine EXPLIZITE Modus-Wahl (layout=true/false) muss respektiert werden:
 * Die Doppelstrategie verglich beide Modi per Quality-Score und ersetzte den
 * angeforderten Layout-Text still durch die Raw-Variante — Erkennung und
 * Konvertierung sahen dadurch VERSCHIEDENE Texte derselben Datei (JobRad-
 * Sammelrechnung: alle 46 Positionszeilen verloren ihre Spaltenform).
 */
final class PDFToTextReaderLayoutTest extends TestCase {
    private const SAMPLE = __DIR__ . '/../../.samples/PDF/test-a4-text.pdf';

    private function reader(): PDFToTextReader {
        $reader = new PDFToTextReader;
        if (!$reader->isAvailable()) {
            self::markTestSkipped('pdftotext nicht verfügbar');
        }
        if (!file_exists(self::SAMPLE)) {
            self::markTestSkipped('Sample-PDF nicht vorhanden');
        }

        return $reader;
    }

    public function test_explicit_layout_request_returns_layout_extraction(): void {
        $reader = $this->reader();

        $direct = new ReflectionMethod($reader, 'extractWithMode');
        $layoutText = $direct->invoke($reader, self::SAMPLE, true);
        $this->assertNotNull($layoutText);

        // Explizites layout=true: exakt der Layout-Text, keine Score-Wahl.
        $this->assertSame($layoutText, $reader->extractText(self::SAMPLE, ['layout' => true]));
    }

    public function test_explicit_raw_request_returns_raw_extraction(): void {
        $reader = $this->reader();

        $direct = new ReflectionMethod($reader, 'extractWithMode');
        $rawText = $direct->invoke($reader, self::SAMPLE, false);
        $this->assertNotNull($rawText);

        $this->assertSame($rawText, $reader->extractText(self::SAMPLE, ['layout' => false]));
    }

    public function test_explicit_dual_strategy_still_wins_over_mode_choice(): void {
        $reader = $this->reader();

        // Wer die Doppelstrategie AUSDRÜCKLICH anfordert, bekommt sie auch mit
        // Modus-Vorgabe — das Ergebnis muss einer der beiden Modi sein.
        $direct = new ReflectionMethod($reader, 'extractWithMode');
        $layoutText = $direct->invoke($reader, self::SAMPLE, true);
        $rawText = $direct->invoke($reader, self::SAMPLE, false);

        $result = $reader->extractText(self::SAMPLE, ['layout' => true, 'dualStrategy' => true]);
        $this->assertContains($result, [$layoutText, $rawText]);
    }
}
