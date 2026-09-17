<?php
/*
 * Created on   : Tue Sep 09 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : CipherMap.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace PDFToolkit\Entities;

/**
 * Gelernte Glyphen->Zeichen-Zuordnung eines Cipher-Textlayers.
 *
 * Reprint-PDFs ohne ToUnicode-Tabellen liefern je Glyphe einen stabilen
 * falschen Codepoint (auch das Leerzeichen ist eine Glyphe). Diese Map
 * bildet die Cipher-Codepoints zurück auf Klartext ab; gelernt wird sie
 * vom {@see \PDFToolkit\Helper\CipherLayerSolver} per Mehrheitsvotum gegen
 * den OCR-Text derselben Seiten.
 */
final readonly class CipherMap {
    /**
     * Cipher-Kandidaten: JEDES Zeichen außer Zeilenstruktur und Leerzeichen.
     *
     * Die Codepoints eines Reprint-Cipher lassen sich nicht an ihrer
     * Zeichenklasse erkennen: Im Referenzfall steht U+0002 für "A", U+0041
     * ("A") für "B" und U+0054 ("T") für "D" — druckbares ASCII gehört also
     * genauso dazu wie Steuerzeichen und exotische Codepoints. Welche Zeichen
     * tatsächlich Cipher sind, entscheidet allein die gelernte Zuordnung;
     * Zeichen, die für sich selbst stehen, bekommen eine Identitäts-Abbildung.
     * Ausgenommen bleiben nur Umbrüche/Tabulatoren und das Leerzeichen — sie
     * tragen die Struktur (der bbox-Text fügt Wortgrenzen selbst ein).
     */
    public const CIPHER_CHAR_RE = '/[^\n\r\t\f ]/u';

    /** Ungemappte Zeichen dieser Klasse bleiben beim Dekodieren stehen (wahrscheinliche Identität). */
    private const PRINTABLE_ASCII_RE = '/^[\x20-\x7E]$/';

    /**
     * @param array<string, string> $map Cipher-Zeichen (UTF-8) => Klartext-Zeichen
     * @param ?string $spaceChar Cipher-Glyphe, die als Leerzeichen erkannt wurde (bereits in $map)
     * @param int $glyphsSeen Verschiedene Nicht-ASCII-Cipher-Zeichen im Lerntext
     * @param int $glyphsMapped Anzahl übernommener Zuordnungen
     * @param float $consistency Stimmen-gewichtete Mehrheitsquote der übernommenen
     *                           Glyphen (1.0 = keine Widersprüche; niedrige Werte
     *                           deuten auf Multi-Font-Kollisionen)
     * @param float $learnCoverage Anteil gemappter Nicht-ASCII-VORKOMMEN im Lerntext
     */
    public function __construct(
        public array $map,
        public ?string $spaceChar,
        public int $glyphsSeen,
        public int $glyphsMapped,
        public float $consistency,
        public float $learnCoverage,
    ) {}

    /**
     * Dekodiert einen Text über die Map: gemappte Glyphen werden ersetzt,
     * Struktur (Umbrüche, Leerzeichen) bleibt. Ungemapptes druckbares ASCII
     * bleibt ebenfalls stehen — es steht meist für sich selbst und wäre als
     * U+FFFD unnötig zerstört; alles andere Ungemappte wird zu U+FFFD.
     *
     * Bewusst KEIN strtr + Zweitpass: das Dekodat enthält legitime
     * Nicht-ASCII-Zeichen (Umlaute, €), die ein nachgelagerter
     * "Unbekanntes -> U+FFFD"-Pass zerstören würde.
     */
    public function decode(string $text): string {
        $map = $this->map;

        return (string) preg_replace_callback(
            self::CIPHER_CHAR_RE,
            static fn (array $m): string => $map[$m[0]]
                ?? (preg_match(self::PRINTABLE_ASCII_RE, $m[0]) === 1 ? $m[0] : "\u{FFFD}"),
            $text,
        );
    }

    /**
     * Anteil der Cipher-Glyphen-VORKOMMEN in $text, die die Map abdeckt.
     * Texte ohne Cipher-Glyphen gelten als vollständig abgedeckt (1.0).
     */
    public function coverageOn(string $text): float {
        if (preg_match_all(self::CIPHER_CHAR_RE, $text, $m) === false) {
            return 0.0;
        }
        $total = count($m[0]);
        if ($total === 0) {
            return 1.0;
        }
        $mapped = 0;
        foreach ($m[0] as $ch) {
            if (isset($this->map[$ch])) {
                $mapped++;
            }
        }

        return $mapped / $total;
    }

    /**
     * @return array{glyphsSeen: int, glyphsMapped: int, consistency: float, learnCoverage: float, spaceChar: ?string}
     */
    public function stats(): array {
        return [
            'glyphsSeen' => $this->glyphsSeen,
            'glyphsMapped' => $this->glyphsMapped,
            'consistency' => round($this->consistency, 4),
            'learnCoverage' => round($this->learnCoverage, 4),
            'spaceChar' => $this->spaceChar,
        ];
    }
}
