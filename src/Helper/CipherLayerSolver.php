<?php
/*
 * Created on   : Tue Sep 09 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : CipherLayerSolver.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace PDFToolkit\Helper;

use ERRORToolkit\Traits\ErrorLog;
use PDFToolkit\Entities\CipherMap;

/**
 * Cipher-Layer-Rebuild: lernt die Glyphen->Zeichen-Zuordnung eines defekten
 * Textlayers aus dem OCR-Text derselben Seiten und dekodiert damit den
 * ORIGINAL-Textlayer.
 *
 * Hintergrund: Neu gedruckte/konvertierte PDFs (PDF24/Ghostscript) verlieren
 * die ToUnicode-Tabellen ihrer subsetteten Fonts; der Textlayer wird zum
 * KONSISTENTEN Substitutions-Zeichensalat (1 Glyphe = 1 stabiler falscher
 * Codepoint — auch das Leerzeichen ist eine Glyphe). Der fehlerbehaftete,
 * aber meist richtige OCR-Text dient als statistischer Schlüssel: Wort-
 * Alignment + Mehrheitsvotum je Glyphe. Das Dekodat trägt die exakten
 * Original-Werte (Beträge!), OCR liefert nur die Zuordnung.
 *
 * Die fachliche Abnahme des Dekodats ist Sache des Aufrufers (z.B. Saldo-
 * Prüfsumme); die Gates hier (Abdeckung/Konsistenz) sortieren nur offenkundig
 * unbrauchbare Maps früh aus. Alle Schritte sind deterministisch.
 */
final class CipherLayerSolver {
    use ErrorLog;

    /** Weniger Stimmen sind einzelne OCR-Verleser, kein Beleg. */
    public const MIN_VOTES = 3;

    /**
     * Mehrheitsquote je Glyphe: 0.55 verwirft Glyphen, deren Stimmen sich auf
     * zwei Fonts verteilen (Multi-Font-Kollision ≈ Nutzungsanteil), toleriert
     * aber ~1/3 OCR-Rauschen auf seltenen Glyphen.
     */
    public const MIN_MAJORITY = 0.55;

    /**
     * Mindest-Abdeckung der Cipher-Glyphen-VORKOMMEN auf dem Decode-ZIEL —
     * darunter würde zu viel Text zu U+FFFD. Gemessen wird bewusst auf dem
     * Ziel, nicht auf dem Lerntext: dekodiert wird das Ziel. Realprobe:
     * 66 von 77 Glyphen genügen für 99,5 % der Vorkommen, weil die seltenen
     * Restglyphen kaum auftreten.
     */
    public const MIN_OCCURRENCE_COVERAGE = 0.98;

    /**
     * Stimmen-gewichtete Mehrheitsquote über alle übernommenen Glyphen: eine
     * saubere Ein-Font-Cipher liegt nahe 1.0 (OCR-Rauschen kostet wenige
     * Prozent); kollidierende Subset-Fonts ziehen den Mittelwert Richtung
     * ihres Nutzungssplits. 0.95 fängt auch schiefe Kollisionen (70/30),
     * die das Mehrheits-Gate einzelner Glyphen noch passieren würden.
     */
    public const MIN_CONSISTENCY = 0.95;

    /** Darunter ist die Datenbasis für ein Mehrheitsvotum zu dünn. */
    private const MIN_GLYPHS_SEEN = 10;

    /** Lernen sättigt früh; Kappung begrenzt die Aligner-Kosten. */
    private const LEARN_MAX_CHARS = 120000;

    /** Obergrenze der Wortfolgen fürs Alignment (Kosten O(n·band)). */
    private const LEARN_MAX_WORDS = 8000;

    /** Laufen die Wortzahlen weiter auseinander, ist das Alignment sinnlos. */
    private const MAX_WORD_COUNT_DRIFT = 1500;

    /** EM-Runden nach dem Bootstrap (Sättigung tritt nach 2–3 Runden ein). */
    private const MAX_EM_ROUNDS = 4;

    /**
     * Mindestlänge eines equal-Laufs im Bootstrap-Wortlängen-Alignment:
     * einzelne gleichlange Wörter paaren dort zu oft zufällig (und bei
     * verschobenen Wortgrenzen systematisch) falsch.
     */
    private const BOOTSTRAP_MIN_RUN = 3;

    /**
     * Zeichen-Obergrenze des globalen Zeichen-Alignments. Es ist die
     * ergiebigste Stimmenquelle, weil es ohne Wortgrenzen auskommt — kostet
     * aber O(n·band) je Runde.
     */
    private const GLOBAL_ALIGN_MAX_CHARS = 60000;

    /**
     * Band des globalen Zeichen-Alignments: eng genug für die Laufzeit, weit
     * genug für lokale Versätze (verlorene/doppelte Wörter der OCR).
     */
    private const GLOBAL_ALIGN_BAND = 96;

    /** Wortumfang der Space-Glyphen-Probe (bis zu 4 Alignments). */
    private const SPACE_PROBE_WORDS = 1200;

    /** Bidi-Steuerzeichen, die poppler um RTL-verdächtige Glyphen legt. */
    private const BIDI_RE = '/[\x{202A}-\x{202E}\x{200E}\x{200F}\x{2066}-\x{2069}]/u';

    /**
     * Lernt die Zuordnung aus Cipher-Text und OCR-Text. null, wenn die
     * Eingaben fehlen, zu wenige Cipher-Glyphen vorkommen oder die Texte
     * nicht alignierbar sind.
     */
    public static function learnMap(string $cipherText, string $ocrText): ?CipherMap {
        $cipher = self::prepare($cipherText);
        $ocr = self::prepare($ocrText);
        if ($cipher === '' || $ocr === '') {
            return null;
        }

        $freq = self::cipherFrequency($cipher);
        if (count($freq) < self::MIN_GLYPHS_SEEN) {
            return null;
        }

        $ocrWords = self::words($ocr);
        if ($ocrWords === []) {
            return null;
        }

        // Space-Glyphe: der häufigste Cipher-Kandidat, der als Worttrenner
        // das Wortlängen-Alignment gegenüber der Baseline strikt verbessert.
        $spaceChar = self::detectSpaceGlyph($cipher, $freq, $ocrWords);

        $cipherWords = self::words($spaceChar !== null ? str_replace($spaceChar, ' ', $cipher) : $cipher);
        $cipherWords = array_slice($cipherWords, 0, self::LEARN_MAX_WORDS);
        $ocrWords = array_slice($ocrWords, 0, self::LEARN_MAX_WORDS);
        if ($cipherWords === [] || abs(count($cipherWords) - count($ocrWords)) > self::MAX_WORD_COUNT_DRIFT) {
            return null;
        }

        // Wort-Startoffsets im flachen Text: der GEMEINSAME Adressraum aller
        // Stimmkanäle. Nur so sperrt eine im globalen Kanal abgegebene Stimme
        // dieselbe Textstelle auch für den Wort-Kanal — sonst zählte jede
        // Position doppelt und die Mindest-Stimmzahl wäre wirkungslos.
        $wordOffsets = [];
        $offset = 0;
        foreach ($cipherWords as $index => $word) {
            $wordOffsets[$index] = $offset;
            $offset += mb_strlen($word) + 1; // +1 für das Trenn-Leerzeichen
        }

        // Bootstrap: Wortlängen-Alignment, positionsweise Stimmen in equal-Runs.
        $votes = [];
        $votedPositions = [];
        $lensA = array_map('mb_strlen', $cipherWords);
        $lensB = array_map('mb_strlen', $ocrWords);
        foreach (SequenceAligner::opcodes($lensA, $lensB) as $op) {
            $span = $op['i2'] - $op['i1'];
            // Nur längere equal-Läufe: Gleiche Wortlänge allein ist ein
            // schwaches Signal, und wo der Cipher-Layer Wortgrenzen verschiebt
            // ("24A"+"ug" statt "24"+"Aug"), paart es reihenweise falsch.
            // Mehrere gleichlange Wörter in Folge sind ein tragfähiger Anker.
            if ($op['tag'] !== 'equal' || $span < self::BOOTSTRAP_MIN_RUN) {
                continue;
            }
            for ($k = 0; $k < $span; $k++) {
                $index = $op['i1'] + $k;
                self::votePair($wordOffsets[$index], $cipherWords[$index], $ocrWords[$op['j1'] + $k], null, $votes, $votedPositions);
            }
        }

        [$map, $shares] = self::acceptVotes($votes);
        if ($spaceChar !== null) {
            $map[$spaceChar] = ' ';
        }

        // Flache, whitespace-normalisierte Fassungen für das globale
        // Zeichen-Alignment der EM-Runden (einmalig aufgebaut).
        $cipherFlat = mb_str_split(self::flatten(implode(' ', $cipherWords), self::GLOBAL_ALIGN_MAX_CHARS));
        $ocrFlat = mb_str_split(self::flatten(implode(' ', $ocrWords), self::GLOBAL_ALIGN_MAX_CHARS));

        // EM-Runden: E-Schritt = mit der aktuellen Map dekodieren und neu
        // alignieren, M-Schritt = Map aus den Stimmen DIESES Alignments.
        // Die Stimmen werden je Runde frisch gesammelt: ein besseres Alignment
        // muss frühe Fehlpaarungen überstimmen können, und dieselbe Evidenz
        // darf über Runden hinweg nicht kumulieren (das höhlte MIN_VOTES aus).
        $best = [$map, $shares, self::coverage($map, $freq)];
        for ($round = 0; $round < self::MAX_EM_ROUNDS; $round++) {
            $roundVotes = [];
            $roundPositions = [];
            $decodedWords = array_map(
                static fn (string $w): string => self::translate($w, $map),
                $cipherWords,
            );

            // Kanal 1 (zuerst, weil verlässlichste Quelle): globales
            // Zeichen-Alignment auf dem flachen Text. Der Cipher-Layer
            // zerreißt Wortgrenzen (die degenerierten Quads der Space-Glyphe
            // klammern Zeichen falsch: "24A ug" statt "24 Aug"), sodass das
            // Wort-Alignment dort systematisch falsch paart — zeichenweise
            // entfällt das Problem. Die Positionssperre sorgt dafür, dass der
            // schwächere Wort-Kanal danach nur noch offene Stellen füllt.
            self::voteByGlobalAlignment($cipherFlat, $ocrFlat, $map, $roundVotes, $roundPositions);

            // Kanal 2: inhaltliches Wort-Alignment. Paare gleicher Länge
            // stimmen positionsweise, ungleiche über ein Zeichen-Alignment
            // innerhalb des Wortpaares (fängt Zeichenverluste der OCR ab).
            foreach (SequenceAligner::opcodes($decodedWords, $ocrWords) as $op) {
                $spanA = $op['i2'] - $op['i1'];
                if ($spanA !== $op['j2'] - $op['j1']) {
                    continue;
                }
                for ($k = 0; $k < $spanA; $k++) {
                    $index = $op['i1'] + $k;
                    $cipherWord = $cipherWords[$index];
                    $ocrWord = $ocrWords[$op['j1'] + $k];
                    $base = $wordOffsets[$index];
                    if (mb_strlen($cipherWord) === mb_strlen($ocrWord)) {
                        self::votePair($base, $cipherWord, $ocrWord, null, $roundVotes, $roundPositions);
                        continue;
                    }
                    self::voteByCharAlignment($base, $cipherWord, $decodedWords[$index], $ocrWord, $roundVotes, $roundPositions);
                }
            }

            [$roundMap, $roundShares] = self::acceptVotes($roundVotes);
            if ($spaceChar !== null) {
                $roundMap[$spaceChar] = ' ';
            }
            $roundCoverage = self::coverage($roundMap, $freq);

            $improved = $roundCoverage > $best[2] + 1e-9;
            if ($improved) {
                $best = [$roundMap, $roundShares, $roundCoverage];
            }
            $changed = $roundMap !== $map;
            $map = $roundMap;
            if (!$changed) {
                break;
            }
        }

        [$map, $shares, $coverage] = $best;
        if ($spaceChar !== null) {
            $map[$spaceChar] = ' ';
        }

        $voteWeight = 0;
        $weightedShare = 0.0;
        foreach ($shares as $glyph => [$share, $total]) {
            if (isset($map[$glyph])) {
                $voteWeight += $total;
                $weightedShare += $share * $total;
            }
        }

        return new CipherMap(
            map: $map,
            spaceChar: $spaceChar,
            glyphsSeen: count($freq),
            glyphsMapped: count($map),
            consistency: $voteWeight > 0 ? $weightedShare / $voteWeight : 1.0,
            learnCoverage: $coverage,
        );
    }

    /**
     * Anteil der Glyphen-VORKOMMEN im Lerntext, den eine Map abdeckt.
     *
     * @param array<string, string> $map
     * @param array<string, int> $freq
     */
    private static function coverage(array $map, array $freq): float {
        $total = array_sum($freq);
        if ($total === 0) {
            return 0.0;
        }
        $mapped = 0;
        foreach ($freq as $glyph => $count) {
            if (isset($map[$glyph])) {
                $mapped += $count;
            }
        }

        return $mapped / $total;
    }

    /** Dekodiert $text (nach Bidi-Bereinigung) über die gelernte Map. */
    public static function decode(string $text, CipherMap $map): string {
        return $map->decode(self::stripBidi($text));
    }

    /**
     * Vorprüfung des Dekodats: Abdeckung wird auf dem tatsächlichen
     * Decode-ZIEL gemessen (nicht auf dem Lerntext), dazu die Vote-Konsistenz
     * als Kollisions-Indikator.
     */
    public static function isReliable(CipherMap $map, string $decodeTarget): bool {
        return $map->coverageOn(self::stripBidi($decodeTarget)) >= self::MIN_OCCURRENCE_COVERAGE
            && $map->consistency >= self::MIN_CONSISTENCY;
    }

    /**
     * Komplettpipeline: lernen -> Gate -> dekodieren. null bei fehlenden
     * Eingaben oder verfehltem Gate — der Aufrufer bleibt dann bei seiner
     * bisherigen Textquelle.
     */
    public static function solveAndDecode(string $cipherText, string $ocrText, string $decodeTarget): ?string {
        $map = self::learnMap($cipherText, $ocrText);
        if ($map === null) {
            return null;
        }
        if (!self::isReliable($map, $decodeTarget)) {
            self::logInfo(sprintf(
                'Cipher-Layer-Map verfehlt das Gate (Abdeckung %.4f, Konsistenz %.4f, %d/%d Glyphen) — Dekodat verworfen',
                $map->coverageOn(self::stripBidi($decodeTarget)),
                $map->consistency,
                $map->glyphsMapped,
                $map->glyphsSeen,
            ));

            return null;
        }

        return self::decode($decodeTarget, $map);
    }

    // ------------------------------------------------------------------
    // Interna
    // ------------------------------------------------------------------

    private static function prepare(string $text): string {
        $text = self::stripBidi($text);
        if (trim($text) === '') {
            return '';
        }

        return mb_strlen($text) > self::LEARN_MAX_CHARS ? mb_substr($text, 0, self::LEARN_MAX_CHARS) : $text;
    }

    private static function stripBidi(string $text): string {
        return (string) preg_replace(self::BIDI_RE, '', $text);
    }

    /** Ist das Zeichen eine Cipher-Glyphe (kein druckbares ASCII, keine Zeilenstruktur)? */
    private static function isCipherChar(string $char): bool {
        return preg_match(CipherMap::CIPHER_CHAR_RE, $char) === 1;
    }

    /** @return array<string, int> Cipher-Glyphe => Vorkommen */
    private static function cipherFrequency(string $text): array {
        if (preg_match_all(CipherMap::CIPHER_CHAR_RE, $text, $m) === false) {
            return [];
        }

        return array_count_values($m[0]);
    }

    /** @return list<string> */
    private static function words(string $text): array {
        return preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /**
     * Bestimmt die Space-Glyphe: unter den drei häufigsten Cipher-Glyphen
     * gewinnt der Kandidat, der als Worttrenner die Summe der equal-Runs des
     * Wortlängen-Alignments gegenüber der Baseline (keine Ersetzung) STRIKT
     * maximiert.
     *
     * @param array<string, int> $freq
     * @param list<string> $ocrWords
     */
    private static function detectSpaceGlyph(string $cipher, array $freq, array $ocrWords): ?string {
        arsort($freq);
        $candidates = array_slice(array_keys($freq), 0, 3);

        $ocrLens = array_map('mb_strlen', array_slice($ocrWords, 0, self::SPACE_PROBE_WORDS));

        $score = static function (?string $candidate) use ($cipher, $ocrLens): int {
            $probe = $candidate !== null ? str_replace($candidate, ' ', $cipher) : $cipher;
            $lens = array_map('mb_strlen', array_slice(self::words($probe), 0, self::SPACE_PROBE_WORDS));
            $sum = 0;
            foreach (SequenceAligner::opcodes($lens, $ocrLens) as $op) {
                if ($op['tag'] === 'equal') {
                    $sum += $op['i2'] - $op['i1'];
                }
            }

            return $sum;
        };

        $best = null;
        $bestScore = $score(null);
        foreach ($candidates as $candidate) {
            // Ziffern-Glyphen kommen als Array-Key ganzzahlig zurück.
            $candidate = (string) $candidate;
            $candidateScore = $score($candidate);
            if ($candidateScore > $bestScore) {
                $best = $candidate;
                $bestScore = $candidateScore;
            }
        }

        return $best;
    }

    /**
     * Positionsweise Stimmen eines Wortpaares gleicher Länge sammeln.
     * Mit $map werden nur Positionen gewertet, deren Cipher-Zeichen noch
     * ungemappt ist (EM-Runden); ohne $map zählt jede Cipher-Position
     * (Runde 1). Jede Cipher-Position (Wortindex:Offset) stimmt über alle
     * Runden hinweg höchstens einmal ab.
     *
     * @param ?array<string, string> $map
     * @param array<string, array<string, int>> $votes
     * @param array<string, true> $votedPositions
     */
    private static function votePair(int $wordIndex, string $cipherWord, string $ocrWord, ?array $map, array &$votes, array &$votedPositions): void {
        if (mb_strlen($cipherWord) !== mb_strlen($ocrWord)) {
            return;
        }
        $cipherChars = mb_str_split($cipherWord);
        $ocrChars = mb_str_split($ocrWord);
        foreach ($cipherChars as $pos => $cc) {
            if (!self::isCipherChar($cc)) {
                continue;
            }
            if ($map !== null && isset($map[$cc])) {
                continue;
            }
            $positionKey = 'g:' . ($wordIndex + $pos);
            if (isset($votedPositions[$positionKey])) {
                continue;
            }
            $votedPositions[$positionKey] = true;
            $votes[$cc][$ocrChars[$pos]] = ($votes[$cc][$ocrChars[$pos]] ?? 0) + 1;
        }
    }

    /**
     * Stimmen aus einem Wortpaar UNGLEICHER Länge: das teildekodierte Wort
     * wird zeichenweise gegen das OCR-Wort aligniert (kurze Folgen, exaktes
     * DP), Stimmen liefern nur 1:1-Zuordnungen aus equal- und gleich langen
     * replace-Läufen. So zählt auch ein Wort mit, aus dem die OCR ein Zeichen
     * verschluckt oder hinzuerfunden hat — die Hauptquelle für die Glyphen,
     * die das reine Längen-Alignment nie zu sehen bekommt.
     *
     * @param int $wordIndex Startoffset des Wortes im flachen Adressraum
     * @param array<string, array<string, int>> $votes
     * @param array<string, true> $votedPositions
     */
    private static function voteByCharAlignment(int $wordIndex, string $cipherWord, string $decodedWord, string $ocrWord, array &$votes, array &$votedPositions): void {
        $cipherChars = mb_str_split($cipherWord);
        $decodedChars = mb_str_split($decodedWord);
        $ocrChars = mb_str_split($ocrWord);
        if (count($cipherChars) !== count($decodedChars) || $cipherChars === [] || $ocrChars === []) {
            return;
        }

        foreach (SequenceAligner::opcodes($decodedChars, $ocrChars) as $op) {
            $span = $op['i2'] - $op['i1'];
            if (($op['tag'] !== 'equal' && $op['tag'] !== 'replace') || $span !== $op['j2'] - $op['j1']) {
                continue;
            }
            for ($k = 0; $k < $span; $k++) {
                $cc = $cipherChars[$op['i1'] + $k];
                if (!self::isCipherChar($cc)) {
                    continue;
                }
                $positionKey = 'g:' . ($wordIndex + $op['i1'] + $k);
                if (isset($votedPositions[$positionKey])) {
                    continue;
                }
                $votedPositions[$positionKey] = true;
                $ocrChar = $ocrChars[$op['j1'] + $k];
                $votes[$cc][$ocrChar] = ($votes[$cc][$ocrChar] ?? 0) + 1;
            }
        }
    }

    /**
     * Stimmen aus dem globalen Zeichen-Alignment: der teildekodierte Cipher-
     * Text wird als EINE Zeichenfolge gegen den OCR-Text aligniert. Wertet nur
     * 1:1-Zuordnungen aus equal-/gleich langen replace-Läufen und nur noch
     * ungemappte Glyphen; jede Cipher-Position stimmt einmal ab.
     *
     * @param list<string> $cipherFlat
     * @param list<string> $ocrFlat
     * @param array<string, string> $map
     * @param array<string, array<string, int>> $votes
     * @param array<string, true> $votedPositions
     */
    private static function voteByGlobalAlignment(array $cipherFlat, array $ocrFlat, array $map, array &$votes, array &$votedPositions): void {
        if ($cipherFlat === [] || $ocrFlat === []) {
            return;
        }
        $decodedFlat = array_map(
            static fn (string $ch): string => self::isCipherChar($ch) ? ($map[$ch] ?? "\u{FFFD}") : $ch,
            $cipherFlat,
        );

        foreach (SequenceAligner::opcodes($decodedFlat, $ocrFlat, self::GLOBAL_ALIGN_BAND) as $op) {
            $span = $op['i2'] - $op['i1'];
            if (($op['tag'] !== 'equal' && $op['tag'] !== 'replace') || $span !== $op['j2'] - $op['j1']) {
                continue;
            }
            for ($k = 0; $k < $span; $k++) {
                $cc = $cipherFlat[$op['i1'] + $k];
                if (!self::isCipherChar($cc)) {
                    continue;
                }
                // Vertauschte Nachbarn überspringen: Bleibt im Cipher-Layer
                // ein Zeichen über die Wortgrenze hängen ("24A ug" statt
                // "24 Aug"), sieht das Alignment zwei Ersetzungen, deren
                // Stimmen beide falsch wären. Erkennbar am Über-Kreuz-Treffer
                // der bereits dekodierten Nachbarn.
                if ($op['tag'] === 'replace' && self::isTransposition($decodedFlat, $ocrFlat, $op['i1'] + $k, $op['j1'] + $k, $op)) {
                    continue;
                }
                $positionKey = 'g:' . ($op['i1'] + $k);
                if (isset($votedPositions[$positionKey])) {
                    continue;
                }
                $votedPositions[$positionKey] = true;
                $ocrChar = $ocrFlat[$op['j1'] + $k];
                $votes[$cc][$ocrChar] = ($votes[$cc][$ocrChar] ?? 0) + 1;
            }
        }
    }

    /**
     * Ist die Position Teil einer Zeichenvertauschung? Geprüft wird der
     * Über-Kreuz-Treffer mit dem jeweiligen Nachbarn innerhalb desselben
     * replace-Laufs; U+FFFD (noch ungemappt) zählt nicht als Treffer.
     *
     * @param list<string> $decoded
     * @param list<string> $ocr
     * @param array{tag: string, i1: int, i2: int, j1: int, j2: int} $op
     */
    private static function isTransposition(array $decoded, array $ocr, int $i, int $j, array $op): bool {
        $unknown = "\u{FFFD}";
        if ($i + 1 < $op['i2'] && $j + 1 < $op['j2']
            && $decoded[$i] !== $unknown && $decoded[$i] === ($ocr[$j + 1] ?? null)
            && $decoded[$i + 1] !== $unknown && $decoded[$i + 1] === ($ocr[$j] ?? null)) {
            return true;
        }

        return $i - 1 >= $op['i1'] && $j - 1 >= $op['j1']
            && $decoded[$i] !== $unknown && $decoded[$i] === ($ocr[$j - 1] ?? null)
            && $decoded[$i - 1] !== $unknown && $decoded[$i - 1] === ($ocr[$j] ?? null);
    }

    /** Whitespace vereinheitlichen und auf $maxChars kappen. */
    private static function flatten(string $text, int $maxChars): string {
        $flat = (string) preg_replace('/\s+/u', ' ', $text);

        return mb_strlen($flat) > $maxChars ? mb_substr($flat, 0, $maxChars) : $flat;
    }

    /**
     * Mehrheitsvotum: übernommen wird eine Glyphe bei >= MIN_VOTES Stimmen
     * und Mehrheitsquote >= MIN_MAJORITY; Ties deterministisch (höhere
     * Stimmzahl, dann binär kleinstes Klartext-Zeichen).
     *
     * @param array<string, array<string, int>> $votes
     * @return array{0: array<string, string>, 1: array<string, array{0: float, 1: int}>}
     *         [Map, Glyphe => [Mehrheitsquote, Stimmen gesamt]]
     */
    private static function acceptVotes(array $votes): array {
        $map = [];
        $shares = [];
        foreach ($votes as $glyph => $bucket) {
            $total = array_sum($bucket);
            if ($total < self::MIN_VOTES) {
                continue;
            }
            $topChar = null;
            $topCount = -1;
            foreach ($bucket as $char => $count) {
                $char = (string) $char;
                if ($count > $topCount || ($count === $topCount && ($topChar === null || strcmp($char, $topChar) < 0))) {
                    $topChar = $char;
                    $topCount = $count;
                }
            }
            $share = $topCount / $total;
            if ($topChar !== null && $share >= self::MIN_MAJORITY) {
                $map[$glyph] = $topChar;
                $shares[$glyph] = [$share, $total];
            }
        }

        return [$map, $shares];
    }

    /** Wort über eine (Teil-)Map übersetzen; Ungemapptes -> U+FFFD. */
    private static function translate(string $word, array $map): string {
        return (string) preg_replace_callback(
            CipherMap::CIPHER_CHAR_RE,
            static fn (array $m): string => $map[$m[0]] ?? "\u{FFFD}",
            $word,
        );
    }
}
