<?php
/*
 * Created on   : Tue Sep 09 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : CipherLayerSolverTest.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace Tests\Helper;

use PDFToolkit\Helper\CipherLayerSolver;
use PHPUnit\Framework\TestCase;

class CipherLayerSolverTest extends TestCase {
    /**
     * Kontoauszugsartiger Klartext: genug Wiederholung, damit jede Glyphe
     * die Mindest-Stimmzahl erreicht; Beträge, Umlaute, Satzzeichen.
     */
    private static function plainText(): string {
        $lines = [];
        $amounts = ['1 234,56', '87,10', '2 659,76', '350,38', '1 869,54', '2 271,17', '990,00', '12,34'];
        $partner = ['Bäckerei Müller GmbH', 'Überweisung Löhne', 'Miete Bürogebäude Süd', 'Zahlung Kärcher Vertrieb'];
        // Kopf-/Summenzeilen mehrfach, damit auch seltene Zeichen (z, f, A …)
        // die Mindest-Stimmzahl erreichen können — jede Cipher-Position stimmt
        // nur einmal ab.
        for ($i = 0; $i < 4; $i++) {
            $lines[] = 'Kontoauszug für August 2026';
            $lines[] = 'Anfangssaldo 0,00 Endsaldo 0,00';
            $lines[] = 'Summe der Umsätze 27 039,25 EUR';
        }
        for ($i = 0; $i < 40; $i++) {
            $lines[] = sprintf(
                '%02d.08.2026 %s Betrag %s EUR Saldo %s',
                ($i % 28) + 1,
                $partner[$i % 4],
                $amounts[$i % 8],
                $amounts[($i + 3) % 8],
            );
        }
        $lines[] = 'Summe der Umsätze 27 039,25 EUR';

        return implode("\n", $lines);
    }

    /**
     * Deterministische Substitutions-Map: jedem im Text vorkommenden Zeichen
     * (außer Zeilenumbruch, optional außer Leerzeichen) wird ein eindeutiger
     * Nicht-ASCII-Codepoint ab U+0390 zugewiesen.
     *
     * @return array<string, string> Klartext-Zeichen => Cipher-Glyphe
     */
    private static function cipherAlphabet(string $plain, bool $cipherSpace): array {
        $alphabet = [];
        $next = 0x0390;
        foreach (mb_str_split($plain) as $ch) {
            if ($ch === "\n" || isset($alphabet[$ch]) || (!$cipherSpace && $ch === ' ')) {
                continue;
            }
            $alphabet[$ch] = mb_chr($next++, 'UTF-8');
        }

        return $alphabet;
    }

    /** @param array<string, string> $alphabet */
    private static function encipher(string $plain, array $alphabet): string {
        return implode('', array_map(
            static fn (string $ch): string => $alphabet[$ch] ?? $ch,
            mb_str_split($plain),
        ));
    }

    /** OCR-Schlüssel: Klartext mit deterministischem Rauschen. */
    private static function noisyOcr(string $plain): string {
        $words = preg_split('/(\s+)/u', $plain, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $out = [];
        $wordIndex = 0;
        foreach ($words as $token) {
            if (trim($token) === '') {
                $out[] = $token;
                continue;
            }
            $wordIndex++;
            if ($wordIndex % 29 === 0) {
                continue; // Wort verloren (OCR-Aussetzer)
            }
            if ($wordIndex % 31 === 0) {
                $out[] = $token . ' ' . $token; // Wort doppelt
                continue;
            }
            if ($wordIndex % 7 === 0 && mb_strlen($token) > 3) {
                // Ein Zeichen verlesen (Länge bleibt gleich)
                $chars = mb_str_split($token);
                $chars[2] = $chars[2] === '#' ? '+' : '#';
                $token = implode('', $chars);
            }
            $out[] = $token;
        }

        return implode('', $out);
    }

    public function test_round_trip_with_ciphered_space_and_bidi_noise(): void {
        $plain = self::plainText();
        $alphabet = self::cipherAlphabet($plain, cipherSpace: true);
        $cipher = self::encipher($plain, $alphabet);
        // poppler-artige Bidi-Klammern um einige Glyphen
        $cipher = str_replace($alphabet[' '], "\u{202B}" . $alphabet[' '] . "\u{202C}", $cipher);

        $map = CipherLayerSolver::learnMap($cipher, self::noisyOcr($plain));
        $this->assertNotNull($map);
        $this->assertSame($alphabet[' '], $map->spaceChar, 'Space-Glyphe nicht erkannt');
        $this->assertSame($plain, CipherLayerSolver::decode($cipher, $map));

        $decoded = CipherLayerSolver::solveAndDecode($cipher, self::noisyOcr($plain), $cipher);
        $this->assertSame($plain, $decoded);
    }

    public function test_real_spaces_are_kept_without_space_glyph(): void {
        $plain = self::plainText();
        $alphabet = self::cipherAlphabet($plain, cipherSpace: false);
        $cipher = self::encipher($plain, $alphabet);

        $map = CipherLayerSolver::learnMap($cipher, self::noisyOcr($plain));
        $this->assertNotNull($map);
        $this->assertNull($map->spaceChar);
        $this->assertSame($plain, CipherLayerSolver::decode($cipher, $map));
    }

    public function test_rare_glyph_below_vote_threshold_stays_unmapped(): void {
        // 'q' kommt genau einmal vor (< MIN_VOTES) — alles andere oft.
        $plain = self::plainText() . "\nqq";
        $alphabet = self::cipherAlphabet($plain, cipherSpace: true);
        $cipher = self::encipher($plain, $alphabet);

        $map = CipherLayerSolver::learnMap($cipher, $plain); // perfekter Schlüssel
        $this->assertNotNull($map);
        $this->assertArrayNotHasKey($alphabet['q'], $map->map);
        $this->assertStringContainsString("\u{FFFD}\u{FFFD}", CipherLayerSolver::decode($cipher, $map));
    }

    public function test_two_font_collision_fails_gate(): void {
        $plain = self::plainText();
        $alphabetA = self::cipherAlphabet($plain, cipherSpace: true);
        // Font B: dieselben Codepoints, aber die häufigen Buchstaben zyklisch
        // vertauscht — wie zwei unabhängig subsettete Fonts, bei denen dieselbe
        // Glyphen-ID je Font etwas anderes bedeutet. Bei hälftiger Nutzung
        // bleiben die Mehrheiten der Kollisionsglyphen nahe 50–70 % und die
        // stimmen-gewichtete Konsistenz fällt unter das Gate.
        $rotate = ['a', 'e', 'n', 'r', 's', 't', 'u', 'o', 'l', 'd', 'i', 'g'];
        $alphabetB = $alphabetA;
        foreach ($rotate as $idx => $char) {
            $alphabetB[$char] = $alphabetA[$rotate[($idx + 1) % count($rotate)]];
        }

        $lines = explode("\n", $plain);
        $half = (int) (count($lines) / 2);
        $cipher = self::encipher(implode("\n", array_slice($lines, 0, $half)), $alphabetA)
            . "\n" . self::encipher(implode("\n", array_slice($lines, $half)), $alphabetB);

        $this->assertNull(
            CipherLayerSolver::solveAndDecode($cipher, $plain, $cipher),
            'Zwei-Font-Kollisionen dürfen das Gate nicht passieren'
        );
    }

    public function test_unrelated_ocr_text_yields_null(): void {
        $plain = self::plainText();
        $cipher = self::encipher($plain, self::cipherAlphabet($plain, cipherSpace: true));
        $unrelated = str_repeat("Lorem ipsum dolor sit amet consectetur adipisci elit sed diam nonumy\n", 40);

        $this->assertNull(CipherLayerSolver::solveAndDecode($cipher, $unrelated, $cipher));
    }

    public function test_too_few_distinct_glyphs_yields_null(): void {
        $this->assertNull(CipherLayerSolver::learnMap("aaa bbb\n", "aaa bbb\n"));
    }

    /**
     * Sicherheitsnetz: Ein GESUNDER Text (der Aufrufer ruft den Solver nur bei
     * defektem Layer, aber Fehlauslösungen sind möglich) lernt eine
     * Identitäts-Zuordnung und gibt den Text unverändert zurück.
     */
    public function test_healthy_text_decodes_to_itself(): void {
        $plain = self::plainText();

        $decoded = CipherLayerSolver::solveAndDecode($plain, self::noisyOcr($plain), $plain);

        $this->assertSame($plain, $decoded);
    }

    public function test_empty_inputs_yield_null(): void {
        $this->assertNull(CipherLayerSolver::learnMap('', 'text'));
        $this->assertNull(CipherLayerSolver::learnMap('text', "  \n "));
    }

    public function test_determinism(): void {
        $plain = self::plainText();
        $cipher = self::encipher($plain, self::cipherAlphabet($plain, cipherSpace: true));
        $ocr = self::noisyOcr($plain);

        $first = CipherLayerSolver::learnMap($cipher, $ocr);
        $this->assertNotNull($first);
        for ($run = 0; $run < 2; $run++) {
            $again = CipherLayerSolver::learnMap($cipher, $ocr);
            $this->assertNotNull($again);
            $this->assertSame($first->map, $again->map);
            $this->assertSame($first->consistency, $again->consistency);
        }
    }

    public function test_stats_reflect_learning(): void {
        $plain = self::plainText();
        $cipher = self::encipher($plain, self::cipherAlphabet($plain, cipherSpace: true));

        $map = CipherLayerSolver::learnMap($cipher, self::noisyOcr($plain));
        $this->assertNotNull($map);
        $this->assertGreaterThan(10, $map->glyphsSeen);
        $this->assertGreaterThanOrEqual($map->glyphsMapped, $map->glyphsSeen);
        $this->assertGreaterThan(0.9, $map->consistency);
        $this->assertGreaterThan(0.98, $map->learnCoverage);
    }
}
