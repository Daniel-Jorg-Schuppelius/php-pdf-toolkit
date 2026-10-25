<?php
/*
 * Created on   : Sun Oct 25 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : PageFurnitureDetector.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace PDFToolkit\Helper;

use Closure;

/**
 * Seitenausstattung im extrahierten Text: Kopf- und Fußzeilen, die auf den
 * meisten Seiten an derselben Stelle vom Seitenrand stehen und nie im
 * Seiteninneren. Ziffern zählen beim Vergleich nicht ("Seite 2 von 52",
 * Druckdatum). Gelesen wird vom Rand her bis zur ersten Zeile, die keine
 * Ausstattung ist; markiert wird nach Position, nicht nach Inhalt — der
 * Kontoinhaber aus dem Seitenkopf bleibt als Gegenpartei einer Umbuchung
 * mitten auf der Seite erhalten.
 *
 * Spaltenköpfe kennt nur der Aufrufer ($isColumnHeader): wiederholte gehören
 * zur Ausstattung, und der Lauf geht über jeden hinweg. Nie Ausstattung:
 * Zeilen mit Betrag (Übertrag, Saldo, wiederkehrende Gebühr) oder mit Datum
 * am Zeilenanfang (Buchung) und einzelne kurze Wörter.
 */
final class PageFurnitureDetector {
    private const TOP_LINES = 8;

    private const BOTTOM_LINES = 6;

    /** Anteil der Seiten, auf denen die Zeile an ihrer Stelle stehen muss. */
    private const MIN_SHARE = 0.6;

    private const MIN_SINGLE_WORD = 8;

    /**
     * So weit sucht die Abbildung im Rohtext nach der nächsten bereinigten Zeile
     * (dazwischen liegen Leer- und Artefaktzeilen); ohne Treffer gilt die Zeile
     * als umgeschrieben.
     */
    private const LOOKAHEAD = 500;

    /**
     * @param (Closure(string): bool)|null $isColumnHeader Zeile ist ein Tabellen-/Spaltenkopf
     */
    public function __construct(
        private readonly ?Closure $isColumnHeader = null,
    ) {}

    /**
     * Ausstattung als Zeilenindizes in $cleaned.
     *
     * @param string $text Text mit Seitentrennern (\f aus pdftotext)
     * @param string $cleaned derselbe Text bereinigt: dieselben Zeilen in derselben
     *        Reihenfolge, ohne Leer- und Artefaktzeilen. Umgeschriebene oder
     *        eingefügte Zeilen ohne Gegenstück im Rohtext bleiben unmarkiert.
     * @return array<int, true>
     */
    public function in(string $text, string $cleaned): array {
        $raw = [];
        $pages = [];
        foreach (explode("\f", $text) as $page) {
            $lines = [];
            foreach (preg_split('/\r?\n/', $page) ?: [] as $line) {
                if (trim($line) !== '') {
                    $lines[] = count($raw);
                }
                $raw[] = $line;
            }
            if ($lines !== []) {
                $pages[] = $lines;
            }
        }
        $marked = $this->marked($raw, $pages);
        if ($marked === []) {
            return [];
        }

        $furniture = [];
        $r = 0;
        $rawCount = count($raw);
        foreach (preg_split('/\r?\n/', $cleaned) ?: [] as $c => $line) {
            $match = $r;
            $limit = min($rawCount, $r + self::LOOKAHEAD);
            while ($match < $limit && $raw[$match] !== $line) {
                $match++;
            }
            if ($match === $limit) {
                continue;
            }
            if (isset($marked[$match])) {
                $furniture[$c] = true;
            }
            $r = $match + 1;
        }

        return $furniture;
    }

    /**
     * @param list<string> $raw
     * @param list<list<int>> $pages Indizes der nicht-leeren Zeilen je Seite
     * @return array<int, true> Indizes in $raw
     */
    private function marked(array $raw, array $pages): array {
        if (count($pages) < 2) {
            return [];
        }
        $seen = [];
        $body = [];
        foreach ($pages as $p => $lines) {
            $edgeLines = [];
            foreach (self::edges($lines) as $edge) {
                foreach ($edge as [$slot, $index]) {
                    $seen[$slot . '|' . self::key($raw[$index])][$p] = true;
                    $edgeLines[$index] = true;
                }
            }
            foreach ($lines as $index) {
                if (!isset($edgeLines[$index])) {
                    $body[self::key($raw[$index])] = true;
                }
            }
        }
        $needed = max(2, (int) ceil(count($pages) * self::MIN_SHARE));

        $marked = [];
        foreach ($pages as $lines) {
            foreach (self::edges($lines) as $edge) {
                foreach ($edge as [$slot, $index]) {
                    $line = $raw[$index];
                    $key = self::key($line);
                    $repeated = count($seen[$slot . '|' . $key] ?? []) >= $needed;
                    if ($this->isColumnHeader !== null && ($this->isColumnHeader)($line)) {
                        // Der erste Kopf steht auf Seite 1 meist im Seiteninneren
                        if ($repeated) {
                            $marked[$index] = true;
                        }
                        continue;
                    }
                    // Auch im Seiteninneren: Inhalt, der oft am Seitenanfang steht (Buchungsart
                    // "Transfer Fee" über der Datumszeile der ersten Buchung jeder Seite)
                    if (!$repeated || isset($body[$key]) || !self::eligible($line)) {
                        break;
                    }
                    $marked[$index] = true;
                }
            }
        }

        return $marked;
    }

    /**
     * Ränder einer Seite, je vom Rand nach innen: [Stelle, Zeilenindex].
     *
     * @param list<int> $lines
     * @return array{0: list<array{0: string, 1: int}>, 1: list<array{0: string, 1: int}>}
     */
    private static function edges(array $lines): array {
        $top = [];
        foreach (array_slice($lines, 0, self::TOP_LINES) as $r => $index) {
            $top[] = ['t' . $r, $index];
        }
        $bottom = [];
        foreach (array_slice(array_reverse($lines), 0, self::BOTTOM_LINES) as $r => $index) {
            $bottom[] = ['b' . $r, $index];
        }

        return [$top, $bottom];
    }

    private static function key(string $line): string {
        return (string) preg_replace('/\d+/', '#', self::normalize($line));
    }

    private static function normalize(string $line): string {
        return trim((string) preg_replace('/\s+/u', ' ', $line));
    }

    private static function eligible(string $line): bool {
        $normalized = self::normalize($line);
        if (preg_match('/\p{L}{2}/u', $normalized) !== 1) {
            return false;
        }
        if (!str_contains($normalized, ' ') && mb_strlen($normalized) < self::MIN_SINGLE_WORD) {
            return false;
        }
        // Betrag: Dezimalteil, der nicht in ein Datum weiterläuft ("21.09.2026" ist keiner)
        if (preg_match('/\d[.,]\d{2}(?![\d.,])/', $normalized) === 1) {
            return false;
        }

        return preg_match('/^(?:\d{1,2}[.\/-]\d{1,2}|\d{4}-\d{2}-\d{2}|\d{1,2}\.?\s+\p{L}{3})/u', $normalized) !== 1;
    }
}
