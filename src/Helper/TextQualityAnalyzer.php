<?php
/*
 * Created on   : Thu Jan 30 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : TextQualityAnalyzer.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace PDFToolkit\Helper;

use ERRORToolkit\Traits\ErrorLog;

/**
 * Analysiert die Qualität von OCR-extrahiertem Text.
 * 
 * Verwendet verschiedene Heuristiken um zu bestimmen, welche
 * Spracheinstellung das beste OCR-Ergebnis geliefert hat.
 */
final class TextQualityAnalyzer {
    use ErrorLog;

    /**
     * Häufige deutsche Wörter für Qualitätsprüfung.
     * Enthält Wörter mit Umlauten die bei falscher Sprache oft falsch erkannt werden.
     */
    private const GERMAN_WORDS = [
        // Artikel und Pronomen
        'der',
        'die',
        'das',
        'den',
        'dem',
        'des',
        'ein',
        'eine',
        'einer',
        'eines',
        'und',
        'oder',
        'aber',
        'nicht',
        'auch',
        'nur',
        'noch',
        'schon',
        'sehr',
        'für',
        'über',
        'unter',
        'zwischen',
        'durch',
        'gegen',
        'ohne',
        'während',
        'werden',
        'wurde',
        'wird',
        'sind',
        'ist',
        'hat',
        'haben',
        'kann',
        'nach',
        'vom',
        'zum',
        'zur',
        'bei',
        'mit',
        'auf',
        'aus',
        'von',
        // Wörter mit Umlauten (wichtig für Erkennung)
        'für',
        'über',
        'würde',
        'können',
        'müssen',
        'größe',
        'größer',
        'größte',
        'größten',
        'fähig',
        'tätig',
        'mäßig',
        'gemäß',
        'während',
        'ungefähr',
        'dafür',
        'darüber',
        'hierfür',
        'wofür',
        'außer',
        'außerdem',
        'ähnlich',
        'öffentlich',
        'möglich',
        'nötig',
        'höher',
        'höchst',
        'prüfung',
        'geprüft',
        'erfüllt',
        'erläutert',
        'erklären',
        'entspricht',
        'geschäftsjahr',
        'jahresabschluss',
        'gesellschaft',
        'vermögens',
        'finanzlage',
        'ertragslage',
        // ß-Wörter
        'gemäß',
        'größe',
        'außer',
        'maßnahme',
        'maßgeblich',
        'straße',
        // Geschäftsbegriffe
        'bilanz',
        'gewinn',
        'verlust',
        'rechnung',
        'anhang',
        'lagebericht',
        // Bank- und Finanzbegriffe
        'kontoauszug',
        'kontostand',
        'buchungsdatum',
        'wertstellung',
        'valuta',
        'umsatz',
        'betrag',
        'saldo',
        'haben',
        'soll',
        'gutschrift',
        'lastschrift',
        'verwendungszweck',
        'empfänger',
        'auftraggeber',
        'zahlungseingang',
        'überweisung',
        'dauerauftrag',
        'abbuchung',
        'einzahlung',
        'auszahlung',
        'bankleitzahl',
        'kontonummer',
        'kreditkarte',
        'girokonto',
        'abrechnung',
        'kartenabrechnung',
        'kreditkartenabrechnung',
        'buchungstext',
        'buchung',
        'transaktion',
        'transaktionsdatum',
        'zinsen',
        'gebühren',
        'entgelt',
        'provision',
        'kontoinhaber',
        'bankverbindung',
        'zahlungsverkehr',
        'rechnungsnummer',
        'rechnungsdatum',
        'fälligkeitsdatum',
        'steuernummer',
        'umsatzsteuer',
        'mehrwertsteuer',
        'nettobetrag',
        'bruttobetrag',
        'gesamtbetrag',
        'zwischensumme',
        'einzelpreis',
        'menge',
        'lieferdatum',
        'bestellnummer',
        'kundennummer',
        'anschrift',
        'telefon',
        'datum',
        'seite',
        'blatt',
        'euro',
    ];

    /**
     * Häufige englische Wörter für Qualitätsprüfung.
     */
    private const ENGLISH_WORDS = [
        'the',
        'and',
        'that',
        'have',
        'for',
        'not',
        'with',
        'you',
        'this',
        'but',
        'his',
        'from',
        'they',
        'were',
        'been',
        'have',
        'their',
        'would',
        'there',
        'which',
        'about',
        'when',
        'will',
        'more',
        'some',
        'than',
        'them',
        'other',
        'into',
        'could',
        'your',
        'time',
        'very',
        'just',
        'know',
        'take',
        'come',
        // Business & Finance
        'financial',
        'statement',
        'report',
        'company',
        'business',
        'annual',
        'balance',
        'sheet',
        'income',
        'audit',
        'auditor',
        'opinion',
        'account',
        'transaction',
        'payment',
        'transfer',
        'amount',
        'total',
        'invoice',
        'credit',
        'debit',
        'date',
        'number',
        'reference',
        'bank',
        'customer',
        'order',
        'price',
        'quantity',
        'description',
    ];

    /**
     * Zeichen-Muster die auf fehlerhafte OCR hindeuten.
     */
    private const ERROR_PATTERNS = [
        // Typische Fehlinterpretationen von Umlauten
        '/[a-z]ii[a-z]/i',     // ü wird oft als ii erkannt
        '/[a-z]fl[a-z]/i',     // ü wird manchmal als fl erkannt  
        '/[a-z]fi[a-z]/i',     // fi-Ligatur Probleme
        '/\b[A-Z]{5,}\b/',     // Zu viele Großbuchstaben in Folge
        '/[^\s\w\d\.\,\;\:\-\(\)\[\]\"\'\/\&\%\€\$\§\!\?\@\#\+\=\*äöüÄÖÜß]/u', // Ungültige Zeichen
    ];

    /**
     * Berechnet einen Qualitätsscore für den extrahierten Text.
     * 
     * @param string $text Der zu analysierende Text
     * @param string $language Die verwendete Sprache (deu, eng, deu+eng)
     * @return float Score zwischen 0 und 100
     */
    public static function calculateQualityScore(string $text, string $language): float {
        if (empty(trim($text))) {
            return 0.0;
        }

        // Erst prüfen ob schwerwiegende Encoding-Probleme vorliegen
        $missingCharsScore = self::calculateMissingCharsScore($text);

        // Bei schwerwiegenden Encoding-Problemen (Score < 30) direkt niedrigen Score zurückgeben
        if ($missingCharsScore < 30) {
            self::logDebug("Quality score for '$language': " . round($missingCharsScore, 2) . " (severe encoding issues detected, missing chars score: $missingCharsScore)");
            return $missingCharsScore;
        }

        $scores = [];

        // 1. Worterkennungs-Score (35%)
        $scores['wordMatch'] = self::calculateWordMatchScore($text, $language) * 0.35;

        // 2. Umlaut-Erkennungs-Score (25%) - wichtig für deutsche Texte
        $scores['umlaut'] = self::calculateUmlautScore($text, $language) * 0.25;

        // 3. Fehlerfreie-Zeichen-Score (15%)
        $scores['cleanChars'] = self::calculateCleanCharScore($text) * 0.15;

        // 4. Wortlängen-Verteilungs-Score (10%)
        $scores['wordLength'] = self::calculateWordLengthScore($text) * 0.1;

        // 5. Fehlende-Zeichen-Score (15%) - erkennt "f r" statt "für"
        $scores['missingChars'] = $missingCharsScore * 0.15;

        $totalScore = array_sum($scores);

        self::logDebug("Quality score for '$language': " . round($totalScore, 2) . " (word: {$scores['wordMatch']}, umlaut: {$scores['umlaut']}, clean: {$scores['cleanChars']}, length: {$scores['wordLength']}, missing: {$scores['missingChars']})");

        return $totalScore;
    }

    /**
     * Berechnet Qualitätsscores für jede einzelne Seite des Textes.
     * 
     * pdftotext trennt Seiten mit Form-Feed (\f). Diese Methode
     * nutzt das, um jede Seite einzeln zu bewerten.
     * 
     * @param string $text Der gesamte extrahierte Text
     * @param string $language Die verwendete Sprache
     * @return array{scores: float[], averageScore: float, lowQualityPages: int[]}
     */
    public static function calculatePageQualityScores(string $text, string $language): array {
        $pages = explode("\f", $text);
        $scores = [];
        $lowQualityPages = [];
        $threshold = (float) (\PDFToolkit\Config\Config::getInstance()->getConfig('PDFSettings', 'quality_threshold') ?? 60.0);

        foreach ($pages as $index => $pageText) {
            $pageText = trim($pageText);
            if ($pageText === '') {
                $scores[$index] = 0.0;
                $lowQualityPages[] = $index + 1; // 1-basiert
                continue;
            }

            $score = self::calculateQualityScore($pageText, $language);
            $scores[$index] = $score;

            if ($score < $threshold) {
                $lowQualityPages[] = $index + 1; // 1-basiert
            }
        }

        $nonEmptyScores = array_filter($scores, fn(float $s) => $s > 0.0);
        $averageScore = count($nonEmptyScores) > 0
            ? array_sum($nonEmptyScores) / count($nonEmptyScores)
            : 0.0;

        self::logDebug(sprintf(
            "Page-level quality: %d pages, avg score %.2f, %d low-quality pages: [%s]",
            count($pages),
            $averageScore,
            count($lowQualityPages),
            implode(', ', $lowQualityPages)
        ));

        return [
            'scores' => $scores,
            'averageScore' => $averageScore,
            'lowQualityPages' => $lowQualityPages,
        ];
    }

    /**
     * Vergleicht mehrere Textergebnisse und gibt das beste zurück.
     * 
     * @param array<string, string> $results Array mit Sprache => Text
     * @return array{text: string, language: string, score: float}
     */
    public static function selectBestResult(array $results): array {
        $bestResult = ['text' => '', 'language' => '', 'score' => 0.0];

        foreach ($results as $language => $text) {
            if (empty($text)) {
                continue;
            }

            $score = self::calculateQualityScore($text, $language);

            self::logInfo("Language '$language': score = " . round($score, 2) . ", length = " . strlen($text));

            if ($score > $bestResult['score']) {
                $bestResult = [
                    'text' => $text,
                    'language' => $language,
                    'score' => $score,
                ];
            }
        }

        if (!empty($bestResult['text'])) {
            self::logInfo("Best result: '{$bestResult['language']}' with score " . round($bestResult['score'], 2));
        }

        return $bestResult;
    }

    /**
     * Berechnet wie viele bekannte Wörter im Text gefunden werden.
     */
    private static function calculateWordMatchScore(string $text, string $language): float {
        $textLower = mb_strtolower($text, 'UTF-8');
        $words = preg_split('/\s+/', $textLower);
        $totalWords = count($words);

        if ($totalWords === 0) {
            return 0.0;
        }

        // Wörterbuch basierend auf Sprache wählen
        $dictionary = match (true) {
            str_contains($language, 'deu') && !str_contains($language, 'eng') => self::GERMAN_WORDS,
            str_contains($language, 'eng') && !str_contains($language, 'deu') => self::ENGLISH_WORDS,
            default => array_merge(self::GERMAN_WORDS, self::ENGLISH_WORDS),
        };

        $matchedWords = 0;
        foreach ($words as $word) {
            $cleanWord = preg_replace('/[^\p{L}]/u', '', $word);
            if (in_array($cleanWord, $dictionary, true)) {
                $matchedWords++;
            }
        }

        // Normalisieren auf 0-100
        $ratio = $matchedWords / min($totalWords, 500); // Max 500 Wörter berücksichtigen
        return min(100, $ratio * 1000); // Skalieren
    }

    /**
     * Bewertet die korrekte Erkennung von Umlauten.
     */
    private static function calculateUmlautScore(string $text, string $language): float {
        // Zähle korrekte Umlaute
        $correctUmlauts = preg_match_all('/[äöüÄÖÜß]/u', $text);

        // Zähle typische Fehlinterpretationen
        $wrongPatterns = 0;
        $wrongPatterns += preg_match_all('/[a-z]ii[a-z]/i', $text);  // ü als ii
        $wrongPatterns += preg_match_all('/ae(?![rl])/i', $text);    // ä als ae (außer in "aerial", "aerl...")
        $wrongPatterns += preg_match_all('/oe(?![uv])/i', $text);    // ö als oe
        $wrongPatterns += preg_match_all('/ue(?![lrst])/i', $text);  // ü als ue

        // Bei deutschen Texten sollten Umlaute vorkommen
        if (str_contains($language, 'deu')) {
            $textLength = mb_strlen($text, 'UTF-8');
            $expectedUmlautRatio = 0.005; // Erwarte ~0.5% Umlaute in deutschem Text
            $expectedUmlauts = $textLength * $expectedUmlautRatio;

            if ($correctUmlauts >= $expectedUmlauts * 0.5) {
                // Gute Umlaut-Erkennung
                $score = 100 - ($wrongPatterns * 5);
            } else {
                // Zu wenige Umlaute erkannt
                $score = 50 - ($wrongPatterns * 10);
            }
        } else {
            // Bei englischen Texten sind Umlaute weniger wichtig
            $score = 80 - ($wrongPatterns * 2);
        }

        return max(0, min(100, $score));
    }

    /**
     * Bewertet die Sauberkeit des Textes (keine Artefakte).
     */
    private static function calculateCleanCharScore(string $text): float {
        $totalChars = mb_strlen($text, 'UTF-8');
        if ($totalChars === 0) {
            return 0.0;
        }

        $errorCount = 0;
        foreach (self::ERROR_PATTERNS as $pattern) {
            $errorCount += preg_match_all($pattern, $text);
        }

        // Berechne Fehlerrate
        $errorRate = $errorCount / ($totalChars / 100);
        $score = 100 - ($errorRate * 10);

        return max(0, min(100, $score));
    }

    /**
     * Bewertet die Wortlängen-Verteilung (realistisch vs. Artefakte).
     */
    private static function calculateWordLengthScore(string $text): float {
        $words = preg_split('/\s+/', $text);
        $wordCount = count($words);

        if ($wordCount < 10) {
            return 50.0; // Nicht genug Daten
        }

        $lengths = array_map('mb_strlen', $words);
        $avgLength = array_sum($lengths) / $wordCount;

        // Durchschnittliche Wortlänge sollte zwischen 4 und 8 liegen
        if ($avgLength >= 4 && $avgLength <= 8) {
            return 100.0;
        } elseif ($avgLength >= 3 && $avgLength <= 10) {
            return 80.0;
        } elseif ($avgLength >= 2 && $avgLength <= 12) {
            return 60.0;
        }

        return 40.0;
    }

    /**
     * Erkennt fehlende Zeichen durch Encoding-Probleme.
     * 
     * Typische Muster bei PDFs mit Encoding-Problemen:
     * - "f r" statt "für"
     * - "G bH" statt "GmbH"
     * - Einzelne Buchstaben umgeben von Leerzeichen
     * 
     * Layout-Whitespace (von pdftotext -layout) wird vor der Analyse normalisiert,
     * damit absichtliche Spaltenausrichtung nicht als Encoding-Fehler gewertet wird.
     */
    private static function calculateMissingCharsScore(string $text): float {
        $issues = 0;

        // Layout-Whitespace normalisieren: Mehrfach-Leerzeichen auf eines reduzieren,
        // Zeilenumbrüche beibehalten. Damit wird die Layout-Formatierung von
        // pdftotext entfernt, aber echte Encoding-Fehler (z.B. "f r" statt "für")
        // bleiben als einzelne Leerzeichen erhalten.
        $normalizedText = preg_replace('/[^\S\n]+/', ' ', $text);
        $normalizedText = preg_replace('/^ | $/m', '', $normalizedText);

        // Muster für fehlende Zeichen: einzelner Buchstabe umgeben von Leerzeichen
        // innerhalb eines Wortkontexts (z.B. "Vermögens" → "Verm gens")
        $singleLetterPattern = '/(?<=\p{L}) \p{L} (?=\p{L}{2})/u';
        $issues += preg_match_all($singleLetterPattern, $normalizedText) * 2;

        // Muster für bekannte Encoding-Probleme bei deutschen Texten
        $knownPatterns = [
            '/G\s+bH/i',              // GmbH
            '/\bf\s+r\b/i',           // für
            '/\b\w?\s+ber\b/i',       // über, darüber
            '/\b[aA]\s+ndern/i',      // ändern
            '/gepr\s+ft/i',           // geprüft
            '/Gesch\s+fts/i',         // Geschäfts
            '/Jahresabschl\s+ss/i',   // Jahresabschluss
            '/Grunds\s+tze/i',        // Grundsätze
            '/Verh\s+ltnissen/i',     // Verhältnissen
            '/tats\s+chlichen/i',     // tatsächlichen
            '/ordnungsm\s+ig/i',      // ordnungsmäßig
            '/Buchf\s+hrung/i',       // Buchführung
            '/Erl\s+uterungen/i',     // Erläuterungen
            '/zuk\s+nftigen/i',       // zukünftigen
            '/einschlie\s+lich/i',    // einschließlich
            '/gem\s+\s/i',            // gemäß
            '/ma\s+geblich/i',        // maßgeblich
            '/verf\s+gbar/i',         // verfügbar
            '/Verm\s+gens/i',         // Vermögens
            '/Finanz\s+bersicht/i',   // Finanzübersicht
            // Häufige OCR-Verwechslungen (Zeichen → ähnliches Zeichen)
            '/\bUberweisung/i',       // Ü→U: Überweisung
            '/\bUbersicht/i',         // Ü→U: Übersicht
            '/\bUber\b/i',            // Ü→U: Über (alleinstehend)
            '/\b0ffentlich/i',        // ö→0: öffentlich
            '/Geb\s+hren/i',         // Gebühren
            '/Kontoaus\s+ge/i',      // Kontoauszüge
            '/Empf\s+nger/i',        // Empfänger
            '/Beg\s+nstigter/i',     // Begünstigter
            '/Auftr\s+ge/i',         // Aufträge
            '/Betr\s+ge/i',          // Beträge
            '/R\s+ckbuchung/i',      // Rückbuchung
            '/Geb\s+hr/i',          // Gebühr
            '/Stra\s+e\b/i',        // Straße (ß→Leerzeichen)
            '/Ma\s+nahme/i',        // Maßnahme
        ];

        foreach ($knownPatterns as $pattern) {
            $matches = preg_match_all($pattern, $normalizedText);
            $issues += $matches * 3; // Jedes bekannte Muster zählt stark
        }

        // Score berechnen (mehr Issues = niedrigerer Score)
        // Bei 10+ Issues pro 1000 Zeichen ist der Score 0
        $textLength = mb_strlen($normalizedText, 'UTF-8');
        $issueRate = $issues / max(1, $textLength / 1000); // Pro 1000 Zeichen

        // Strenge Bewertung: schon wenige Issues = deutlich niedrigerer Score
        $score = 100 - ($issueRate * 15);

        self::logDebug("Missing chars: $issues issues, rate = " . round($issueRate, 2) . "/1000 chars, score = " . round($score, 2));

        return max(0, min(100, $score));
    }
}
