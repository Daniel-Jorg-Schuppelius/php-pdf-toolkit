<?php
/*
 * Created on   : Sat Sep 20 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : GlyphLayerResult.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace PDFToolkit\Entities;

/**
 * Ergebnis der Textrekonstruktion aus Glyphennamen
 * ({@see \PDFToolkit\Helper\GlyphNameLayer}).
 *
 * Der Anteil unbenannter Glyphen sagt, wie weit der Font seine Zeichen
 * ueberhaupt benennt: 0 heisst, jede Glyphe trug einen sprechenden Namen und
 * der Text ist exakt - geraten wurde nichts.
 */
final readonly class GlyphLayerResult {
    /**
     * @param string $text Rekonstruierter Text (Zeilen und Spalten aus den Koordinaten).
     * @param int $glyphs Anzahl gelesener Glyphen.
     * @param int $unnamed Davon ohne sprechenden Glyphennamen (aus der ToUnicode-Tabelle uebernommen).
     */
    public function __construct(
        public string $text,
        public int $glyphs,
        public int $unnamed,
    ) {}

    /** Anteil der Glyphen ohne sprechenden Namen (0.0 = vollstaendig benannt). */
    public function unnamedShare(): float {
        return $this->glyphs > 0 ? $this->unnamed / $this->glyphs : 1.0;
    }

    /** Jede Glyphe trug einen Namen - die Rekonstruktion ist exakt. */
    public function isComplete(): bool {
        return $this->glyphs > 0 && $this->unnamed === 0;
    }

    /**
     * @return array{glyphs: int, unnamed: int, unnamedShare: float}
     */
    public function stats(): array {
        return [
            'glyphs' => $this->glyphs,
            'unnamed' => $this->unnamed,
            'unnamedShare' => round($this->unnamedShare(), 4),
        ];
    }
}
