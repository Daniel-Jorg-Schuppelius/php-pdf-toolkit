<?php
/*
 * Created on   : Thu Jan 30 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : TextQualityAnalyzerTest.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace Tests\Helper;

use PDFToolkit\Helper\TextQualityAnalyzer;
use Tests\Contracts\BaseTestCase;

/**
 * Tests für TextQualityAnalyzer.
 */
final class TextQualityAnalyzerTest extends BaseTestCase {
    public function test_calculate_quality_score_with_german_text(): void {
        $germanText = "Dies ist ein deutscher Text mit Umlauten wie ä, ö, ü und ß. " .
            "Die Gesellschaft hat den Jahresabschluss geprüft und für ordnungsgemäß befunden. " .
            "Der Lagebericht entspricht den gesetzlichen Vorschriften.";

        $score = TextQualityAnalyzer::calculateQualityScore($germanText, 'deu');

        $this->assertGreaterThan(50, $score, "German text should have high score with 'deu' language");
    }

    public function test_calculate_quality_score_with_english_text(): void {
        $englishText = "This is an English text about financial statements. " .
            "The company has been audited and the annual report is accurate. " .
            "The balance sheet shows the financial position of the company.";

        $score = TextQualityAnalyzer::calculateQualityScore($englishText, 'eng');

        $this->assertGreaterThan(50, $score, "English text should have high score with 'eng' language");
    }

    public function test_calculate_quality_score_with_broken_umlauts(): void {
        // Text mit typischen OCR-Fehlern bei falscher Spracheinstellung
        $brokenText = "Dies ist ein deutscher Text mit falschen Umlauten wie a, o, u. " .
            "Die Priifung wurde durchgefiihrt und der Jahresabschluss gepriift.";

        $correctText = "Dies ist ein deutscher Text mit richtigen Umlauten wie ä, ö, ü. " .
            "Die Prüfung wurde durchgeführt und der Jahresabschluss geprüft.";

        $brokenScore = TextQualityAnalyzer::calculateQualityScore($brokenText, 'deu');
        $correctScore = TextQualityAnalyzer::calculateQualityScore($correctText, 'deu');

        $this->assertGreaterThan(
            $brokenScore,
            $correctScore,
            "Correct umlauts should score higher than broken umlauts"
        );
    }

    public function test_select_best_result_chooses_correct_language(): void {
        $results = [
            'deu' => "Prüfungsvermerk: Die Gesellschaft hat den Jahresabschluss ordnungsgemäß erstellt.",
            'eng' => "Prufungsvermerk: Die Gesellschaft hat den Jahresabschluss ordnungsgemai3 erstellt.",
        ];

        $best = TextQualityAnalyzer::selectBestResult($results);

        $this->assertEquals('deu', $best['language'], "Should select 'deu' as best language");
        $this->assertNotEmpty($best['text']);
        $this->assertGreaterThan(0, $best['score']);
    }

    public function test_select_best_result_with_empty_results(): void {
        $results = [];

        $best = TextQualityAnalyzer::selectBestResult($results);

        $this->assertEmpty($best['text']);
        $this->assertEmpty($best['language']);
        $this->assertEquals(0.0, $best['score']);
    }

    public function test_select_best_result_with_only_one_result(): void {
        $results = [
            'deu' => "Ein einfacher deutscher Text.",
        ];

        $best = TextQualityAnalyzer::selectBestResult($results);

        $this->assertEquals('deu', $best['language']);
        $this->assertEquals("Ein einfacher deutscher Text.", $best['text']);
    }

    public function test_empty_text_returns_zero_score(): void {
        $score = TextQualityAnalyzer::calculateQualityScore('', 'deu');

        $this->assertEquals(0.0, $score);
    }

    public function test_whitespace_only_text_returns_low_score(): void {
        $score = TextQualityAnalyzer::calculateQualityScore('   \n\t  ', 'deu');

        $this->assertLessThan(50, $score, "Whitespace-only text should have a low score");
    }
}
